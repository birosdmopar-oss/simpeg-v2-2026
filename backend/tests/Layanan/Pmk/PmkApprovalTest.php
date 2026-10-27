<?php

declare(strict_types=1);

namespace Tests\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Pmk\PmkApprovalService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use Closure;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\NotifikasiRekam;

/**
 * C-09 proses PIC PMK (PRD Fase 4 §6.9.2; AC-09.1, AC-09.5): PIC PMK aktif memproses (0 → 3), menyetujui (0/3 → 1),
 * menolak (0/3 → 2, alasan wajib); setiap aksi mengisi `show_notif`, `notif_date`, `approved_at`, `approved_by`; final
 * terkunci; bukan PIC → 403; persetujuan TIDAK menulis `riwayat_pmk` dan TIDAK mengubah tabel pegawai/masa kerja (D16 —
 * dibandingkan sebelum/sesudah); notifikasi ke pemohon per aksi.
 *
 * @internal
 */
final class PmkApprovalTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private NotifikasiRekam $notif;

    private string $nip;

    private AuthContext $pemohon;

    private AuthContext $pic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        $this->notif = new NotifikasiRekam();
        Services::injectMock('notifikasiLayanan', $this->notif);

        [$this->nip, $this->pemohon] = $this->aktorStudi(Role::PEGAWAI);
        [$picNip, $this->pic]        = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testProsesLaluSetujuiTanpaEfekKeRiwayatDanPegawai(): void
    {
        $id      = $this->ajukan();
        $sebelum = $this->jejakPegawai();

        (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'proses']);
        $row = $this->baris($id);
        $this->assertSame([3, 1], [(int) $row['status'], (int) $row['show_notif']]);
        $this->assertNotNull($row['approved_at']);
        $this->assertNotNull($row['approved_by']);
        $this->assertSame('Peninjauan Masa Kerja Sedang Diproses', end($this->notif->kirim)['judul']);

        (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui']);
        $this->assertSame(1, (int) $this->baris($id)['status']);
        $this->assertSame('Peninjauan Masa Kerja Selesai', end($this->notif->kirim)['judul']);

        $this->assertSame(0, $this->db->table('riwayat_pmk')->countAllResults(), 'tanpa salin ke riwayat_pmk (D16)');
        $this->assertSame($sebelum, $this->jejakPegawai(), 'pegawai & snapshot masa kerja tidak berubah (D16)');
    }

    public function testSetujuiLangsungDari0(): void
    {
        $id = $this->ajukan();

        (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui']);

        $this->assertSame(1, (int) $this->baris($id)['status'], '0 → 1 [K P-14]');
    }

    public function testTolakWajibAlasanDanMemuatAlasan(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['reason_note'], $this->kunci(fn () => (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak'])));

        (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak', 'reason_note' => 'Kontrak tidak sah']);

        $this->assertSame([2, 'Kontrak tidak sah'], [(int) $this->baris($id)['status'], $this->baris($id)['reason_note']]);
        $this->assertSame(['Peninjauan Masa Kerja Ditolak', 'Kontrak tidak sah'], [end($this->notif->kirim)['judul'], end($this->notif->kirim)['reason_note']]);
    }

    public function testFinalTerkunciDanAksiTidakDikenal(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['aksi'], $this->kunci(fn () => (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'tangguhkan'])));

        (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui']);

        foreach (['proses', 'setujui'] as $aksi) {
            $this->assertSame(['status'], $this->kunci(fn () => (new PmkApprovalService())->proses($this->pic, $id, ['aksi' => $aksi])));
        }
    }

    public function testBukanPicDitolak(): void
    {
        $id     = $this->ajukan();
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);

        $this->expectException(ForbiddenException::class);
        (new PmkApprovalService())->proses($su, $id, ['aksi' => 'proses']);
    }

    private function ajukan(): int
    {
        $berkas = [];

        foreach (array_keys(PmkPengajuanService::BERKAS) as $kolom) {
            $berkas[$kolom] = $this->berkasPdf("{$kolom}.pdf");
        }

        return (int) (new PmkPengajuanService())->tambah($this->pemohon, ['jenis_pmk' => 'Negeri', 'instansi' => 'Pemda Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2012-12-31', 'mker_th' => '2', 'mker_bl' => '11'], $berkas)['id_riwayat_pmk_req'];
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $id): array
    {
        return (array) $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRowArray();
    }

    /**
     * Isi pegawai + snapshot yang memuat masa kerja (D16).
     *
     * @return array<string, mixed>
     */
    private function jejakPegawai(): array
    {
        $jejak = [];

        foreach (['pegawai', 'pegawai_kp', 'pegawai_cpns', 'pegawai_kgb', 'pegawai_mutasi_jabatan'] as $tabel) {
            $jejak[$tabel] = $this->db->table($tabel)->where('nip', $this->nip)->get()->getResultArray();
        }

        return $jejak;
    }

    /**
     * @return list<string>
     */
    private function kunci(Closure $aksi): array
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $k = array_keys($e->getErrors() ?? []);
            sort($k);

            return $k;
        }

        $this->fail('Seharusnya 422');
    }
}
