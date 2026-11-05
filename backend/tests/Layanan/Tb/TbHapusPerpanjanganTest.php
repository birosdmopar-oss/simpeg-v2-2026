<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Tb\PerpanjanganTbService;
use App\Libraries\Layanan\Tb\TbService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * CR-102: hapus TB disetujui saat perpanjangan berjalan, di celah antara induk dan perpanjangan, atau di luar semua
 * segmen. Status `pegawai.id_jenis_status` kembali 1 bila hari ini berada di rentang gabungan induk + perpanjangan; di
 * luar rentang gabungan status tidak diubah. Hari ini = 2026-10-27 (HARI_INI).
 *
 * @internal
 */
final class TbHapusPerpanjanganTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private AuthContext $su;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        [, $this->su] = $this->aktorStudi(Role::SUPER_ADMIN);
        [$this->nip]  = $this->aktorStudi(Role::PEGAWAI);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    /**
     * Hari ini di dalam perpanjangan yang berjalan: induk sudah berakhir, perpanjangan memuat hari ini.
     */
    public function testHapusSaatPerpanjanganBerjalanMengembalikanAktif(): void
    {
        $id = $this->tbDisetujui('2026-09-01', '2026-10-15');
        (new PerpanjanganTbService())->tambah($this->su, $id, ['tgl_mulai_perpan' => '2026-10-16', 'tgl_akhir_perpan' => '2026-12-31'], $this->berkasPdf());
        $this->statusPegawai(2);

        (new TbService())->hapus($this->su, $id);

        $this->assertSame(1, $this->statusPegawai(), 'hari ini dalam perpanjangan → status kembali Aktif');
    }

    /**
     * Hari ini di celah antara akhir induk dan mulai perpanjangan: masih bagian dari rentang gabungan.
     */
    public function testHapusSaatCelahMengembalikanAktif(): void
    {
        $id = $this->tbDisetujui('2026-09-01', '2026-10-20');
        (new PerpanjanganTbService())->tambah($this->su, $id, ['tgl_mulai_perpan' => '2026-11-05', 'tgl_akhir_perpan' => '2026-12-31'], $this->berkasPdf());
        $this->statusPegawai(2);

        (new TbService())->hapus($this->su, $id);

        $this->assertSame(1, $this->statusPegawai(), 'hari ini di celah → status kembali Aktif');
    }

    /**
     * Hari ini di luar semua segmen (rentang gabungan telah lewat): status tidak diubah.
     */
    public function testHapusDiLuarSemuaSegmenTidakMengubahStatus(): void
    {
        $id = $this->tbDisetujui('2026-08-01', '2026-09-30');
        (new PerpanjanganTbService())->tambah($this->su, $id, ['tgl_mulai_perpan' => '2026-10-01', 'tgl_akhir_perpan' => '2026-10-20'], $this->berkasPdf());
        $this->statusPegawai(2);

        (new TbService())->hapus($this->su, $id);

        $this->assertSame(2, $this->statusPegawai(), 'di luar rentang gabungan → status tidak diubah');
    }

    private function tbDisetujui(string $mulai, string $akhir): int
    {
        return (int) (new TbService())->tambah(
            $this->su,
            $this->isianStudi(['nip' => $this->nip, 'status' => '1', 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir]) + $this->dataSkTb(),
            [],
        )['id_riwayat_tb'];
    }

    /**
     * @param int|null $nilai bila diisi, menulis status pegawai; selain itu membaca
     */
    private function statusPegawai(?int $nilai = null): int
    {
        if ($nilai !== null) {
            $this->db->table('pegawai')->where('nip', $this->nip)->update(['id_jenis_status' => $nilai]);
        }

        return (int) $this->db->table('pegawai')->where('nip', $this->nip)->get()->getRow()->id_jenis_status;
    }
}
