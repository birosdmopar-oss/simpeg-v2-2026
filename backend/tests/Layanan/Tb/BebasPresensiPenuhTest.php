<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Interfaces\Layanan\StatusTugasBelajarInterface;
use App\Libraries\Layanan\Tb\PerpanjanganTbService;
use App\Libraries\Layanan\Tb\StatusTugasBelajar;
use App\Libraries\Layanan\Tb\TbService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * `StatusTugasBelajarInterface::bebasPresensiPenuh` ber-DB (PRD Fase 4 §6.7.5, D15; AC-07.3; konsumen Fase 5 D-06):
 * true di rentang TB disetujui (inklusif) dan di rentang perpanjangan, false di celah antara keduanya, di luar rentang,
 * untuk TB status 0/2, dan untuk NIP lain; `rentangBebasPresensi` satu periode; `status_penuh` respons TB = hari ini;
 * `Config\Services::statusTugasBelajar()` memberi implementasi ini.
 *
 * @internal
 */
final class BebasPresensiPenuhTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testRentangTbPerpanjanganDanCelah(): void
    {
        [$nip]  = $this->aktorStudi(Role::PEGAWAI);
        [$lain] = $this->aktorStudi(Role::PEGAWAI);
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);

        $id = (int) (new TbService())->tambah($su, $this->isianStudi(['nip' => $nip, 'status' => '1', 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-06-30']) + $this->dataSkTb(), [])['id_riwayat_tb'];
        (new PerpanjanganTbService())->tambah($su, $id, ['tgl_mulai_perpan' => '2026-08-01', 'tgl_akhir_perpan' => '2026-12-31'], $this->berkasPdf());

        $status = service('statusTugasBelajar');
        $this->assertInstanceOf(StatusTugasBelajarInterface::class, $status);
        $this->assertInstanceOf(StatusTugasBelajar::class, $status);

        foreach (['2026-01-01' => true, '2026-06-30' => true, '2026-07-15' => false, '2026-08-01' => true, '2026-12-31' => true, '2025-12-31' => false, '2027-01-01' => false] as $tanggal => $harapan) {
            $this->assertSame($harapan, $status->bebasPresensiPenuh($nip, $tanggal), $tanggal);
        }

        $this->assertFalse($status->bebasPresensiPenuh($lain, '2026-03-01'));
        $this->assertSame(['2026-06-29', '2026-06-30', '2026-08-01', '2026-08-02'], $status->rentangBebasPresensi($nip, '2026-06-29', '2026-08-02'), 'celah 07-01..07-31 tidak bebas');

        $this->assertSame(1, (new TbService())->detail($su, $id)['status_penuh'], 'hari ini 2026-10-27 di rentang perpanjangan');
    }

    public function testTbBelumAtauTidakDisetujuiTidakBebas(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);

        $tb = (new TbService())->tambah($auth, $this->isianStudi(['tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2027-10-31']), $this->berkasSemuaSlot('tb'));
        $this->assertFalse(service('statusTugasBelajar')->bebasPresensiPenuh($nip, '2026-12-01'), 'status 0');

        $this->db->table('riwayat_tb')->where('id_riwayat_tb', (int) $tb['id_riwayat_tb'])->update(['status' => 2]);
        $this->assertFalse(service('statusTugasBelajar')->bebasPresensiPenuh($nip, '2026-12-01'), 'status 2');
        $this->assertSame(0, (new TbService())->detail($auth, (int) $tb['id_riwayat_tb'])['status_penuh']);
    }
}
