<?php

declare(strict_types=1);

namespace Tests\Layanan;

use App\Constants\Role;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Tb\TbService;
use App\Models\Layanan\RiwayatPmkReqModel;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * CR-086 (keputusan Horii 30-10): kolom audit DATETIME berisi UTC dan diisi APLIKASI, bukan `DEFAULT CURRENT_TIMESTAMP` yang
 * mengikuti zona SESI database. Zona sesi sengaja diset +14:00 supaya nilai default DB pasti berbeda ±14 jam dari UTC; tulis
 * lewat penulis nyata (TB, IB) dan lewat model dasar (PMK pengajuan, LKH) lalu bandingkan dengan jam acuan UTC.
 *
 * @internal
 */
final class StempelUtcPenulisTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        $this->db->query("SET time_zone = '+14:00'");
    }

    protected function tearDown(): void
    {
        $this->db->query('SET time_zone = SYSTEM');
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testTugasBelajarMengisiCreatedAtDanUpdatedAtUtc(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);

        $awal = time();
        $tb   = (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_TB));
        $row  = $this->db->table('riwayat_tb')->where('id_riwayat_tb', (int) $tb['id_riwayat_tb'])->get()->getRowArray();

        $this->assertUtc($awal, $row['created_at'], 'riwayat_tb.created_at');
        $this->assertUtc($awal, $row['updated_at'], 'riwayat_tb.updated_at');
    }

    public function testIzinBelajarMengisiCreatedAtDanUpdatedAtUtc(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);

        $awal = time();
        $ib   = (new IbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_IB));
        $row  = $this->db->table('riwayat_ib')->where('id_riwayat_ib', (int) $ib['id_riwayat_ib'])->get()->getRowArray();

        $this->assertUtc($awal, $row['created_at'], 'riwayat_ib.created_at');
        $this->assertUtc($awal, $row['updated_at'], 'riwayat_ib.updated_at');
    }

    public function testModelDasarMengisiCreatedAtUtcBilaTidakDikirim(): void
    {
        [$nip] = $this->aktorStudi(Role::PEGAWAI);

        $awal = time();
        $id   = (int) (new RiwayatPmkReqModel($this->db))->insert([
            'nip'     => $nip, 'jenis_pmk' => 'Swasta', 'instansi' => 'PT Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2013-03-15',
            'mker_th' => 3, 'mker_bl' => 2, 'file_kontrak' => 'a', 'file_paklaring' => 'b', 'file_surat_phk' => 'c', 'file_slip_gaji' => 'd', 'status' => 0,
        ], true);
        $row = $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRowArray();

        $this->assertUtc($awal, $row['created_at'], 'riwayat_pmk_req.created_at');
        $this->assertNull($row['updated_at'], 'updated_at pengajuan PMK tetap NULL saat insert (default [K] NULL)');

        (new RiwayatPmkReqModel($this->db))->update($id, ['instansi' => 'PT Uji 2']);
        $this->assertUtc($awal, $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRowArray()['updated_at'], 'riwayat_pmk_req.updated_at saat update');
    }

    public function testNilaiEksplisitTidakDitimpa(): void
    {
        [$nip] = $this->aktorStudi(Role::PEGAWAI);

        $id = (int) (new RiwayatPmkReqModel($this->db))->insert([
            'nip'        => $nip, 'jenis_pmk' => 'Swasta', 'instansi' => 'PT Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2013-03-15',
            'mker_th'    => 3, 'mker_bl' => 2, 'file_kontrak' => 'a', 'file_paklaring' => 'b', 'file_surat_phk' => 'c', 'file_slip_gaji' => 'd', 'status' => 0,
            'created_at' => '2020-01-02 03:04:05',
        ], true);

        $this->assertSame('2020-01-02 03:04:05', $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRowArray()['created_at']);
    }

    private function assertUtc(int $awal, mixed $nilai, string $label): void
    {
        $epoch = strtotime((string) $nilai . ' UTC');
        $this->assertNotFalse($epoch, "{$label} harus terbaca");
        $this->assertEqualsWithDelta($awal, $epoch, 30, "{$label} harus UTC (zona sesi DB +14:00 tidak boleh bocor): {$nilai}");
    }
}
