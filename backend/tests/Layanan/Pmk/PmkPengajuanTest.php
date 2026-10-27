<?php

declare(strict_types=1);

namespace Tests\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Pmk\PmkApprovalService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use App\Libraries\Layanan\Pmk\ValidasiPmk;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\NotifikasiRekam;
use Tests\Support\Layanan\SisipMinimalTrait;

/**
 * C-09 pengajuan PMK (PRD Fase 4 §5.4, §6.9.1; AC-09.1, AC-09.2): role 2 PNS diri sendiri & role 1 atas nama PNS → status
 * 0; role 3 → 403; non-PNS → 422; validasi (enum `jenis_pmk`, urutan, TMT CPNS P-3, tumpang tindih status ≠ 2) dengan
 * semua galat; empat berkas kolom wajib, 2 MB; `mker` dari klien; opsi & kalkulator; ubah tanpa syarat status (status
 * tetap) [K D19]; hapus ditolak bila status 1, selain itu fisik + berkas; notifikasi PIC jenis 5.
 *
 * @internal
 */
final class PmkPengajuanTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;
    use SisipMinimalTrait;

    protected $seed = MasterDataSeeder::class;

    private NotifikasiRekam $notif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        $this->notif = new NotifikasiRekam();
        Services::injectMock('notifikasiLayanan', $this->notif);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testPnsMengajukanStatus0DenganEmpatBerkasDanPicDiberiTahu(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        [$picNip]     = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);

        $pmk = (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['status' => '1']), $this->berkasPmk());

        $this->assertSame([$nip, 0, 'Menunggu PIC', 3, 2], [$pmk['nip'], (int) $pmk['status'], $pmk['label_status'], (int) $pmk['mker_th'], (int) $pmk['mker_bl']]);
        $this->assertSame([true, true, true, true], [$pmk['file_kontrak'], $pmk['file_paklaring'], $pmk['file_surat_phk'], $pmk['file_slip_gaji']], 'path internal tidak keluar');
        $row = $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', (int) $pmk['id_riwayat_pmk_req'])->get()->getRowArray();
        $this->assertNotSame('', (string) $row['file_kontrak']);

        $this->assertSame([[$picNip], 'Permohonan Peninjauan Masa Kerja Baru', 0], [$this->notif->kirim[0]['penerima'], $this->notif->kirim[0]['judul'], $this->notif->kirim[0]['status']]);
        $this->assertStringContainsString('dengan masa kerja 3 tahun 2 bulan oleh', $this->notif->kirim[0]['isi']);
    }

    public function testRole1AtasNamaPnsDanRole3Ditolak(): void
    {
        [$pns]     = $this->aktorStudi(Role::PEGAWAI);
        [$ptt]     = $this->aktorStudi(Role::PTT, ['id_jenis_pegawai' => 2]);
        [, $su]    = $this->aktorStudi(Role::SUPER_ADMIN);
        [, $admin] = $this->aktorStudi(Role::ADMIN_SATKER);

        $this->assertSame($pns, (new PmkPengajuanService())->tambah($su, $this->isianPmk(['nip' => $pns]), $this->berkasPmk())['nip']);
        $this->assertSame(['nip'], $this->kunci(fn () => (new PmkPengajuanService())->tambah($su, $this->isianPmk(['nip' => $ptt]), $this->berkasPmk())), 'PMK hanya PNS (P-17)');

        $this->expectException(ForbiddenException::class);
        (new PmkPengajuanService())->tambah($admin, $this->isianPmk(['nip' => $pns]), $this->berkasPmk());
    }

    public function testPegawaiRole2NonPnsDitolak(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI, ['id_jenis_pegawai' => 2]);

        $this->assertSame(['nip'], $this->kunci(fn () => (new PmkPengajuanService())->tambah($auth, $this->isianPmk(), $this->berkasPmk())));
    }

    public function testValidasiMengumpulkanSemuaGalatTermasukBerkas(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $berkas   = $this->berkasPmk();
        unset($berkas['file_slip_gaji']);
        $berkas['file_surat_phk'] = $this->berkasPdf('besar.pdf', 3 * 1024 * 1024);

        $errors = $this->galat(fn () => (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['jenis_pmk' => 'Lainnya', 'tgl_akhir' => '2009-12-31', 'mker_th' => '']), $berkas));

        $this->assertSame(['file_slip_gaji', 'file_surat_phk', 'jenis_pmk', 'mker_th', 'tgl_akhir'], $this->urut(array_keys($errors)));
        $this->assertSame(ValidasiPmk::PESAN_URUTAN, $errors['tgl_akhir'][0]);
    }

    public function testBatasTmtCpnsDanOpsi(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $this->aturTmtCpns($nip, '2015-03-01');

        $opsi = (new PmkPengajuanService())->opsi($auth, null);
        $this->assertSame(['2015-03-01', '2015-02-28', ['Swasta', 'Negeri']], [$opsi['tmt_cpns'], $opsi['tgl_awal_maks'], $opsi['jenis_pmk']]);

        $this->assertSame(['tgl_akhir'], $this->kunci(fn () => (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['tgl_akhir' => '2015-03-01']), $this->berkasPmk())), 'tgl_akhir harus < TMT CPNS (P-3)');
        $this->assertSame(0, (int) (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['tgl_akhir' => '2015-02-28']), $this->berkasPmk())['status']);
    }

    public function testTumpangTindihKecualiYangDitolak(): void
    {
        [, $auth]       = $this->aktorStudi(Role::PEGAWAI);
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);
        $id = (int) (new PmkPengajuanService())->tambah($auth, $this->isianPmk(), $this->berkasPmk())['id_riwayat_pmk_req'];

        $this->assertSame(['tgl_awal'], $this->kunci(fn () => (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['tgl_awal' => '2012-06-01']), $this->berkasPmk())));

        (new PmkApprovalService())->proses($pic, $id, ['aksi' => 'tolak', 'reason_note' => 'Paklaring tidak sah']);
        $this->assertSame(0, (int) (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['tgl_awal' => '2012-06-01']), $this->berkasPmk())['status'], 'yang ditolak tidak ikut tumpang tindih [K]');
    }

    public function testUbahTanpaSyaratStatusDanStatusTetap(): void
    {
        [$nip, $auth]   = $this->aktorStudi(Role::PEGAWAI);
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);
        $id = (int) (new PmkPengajuanService())->tambah($auth, $this->isianPmk(), $this->berkasPmk())['id_riwayat_pmk_req'];
        (new PmkApprovalService())->proses($pic, $id, ['aksi' => 'proses']);
        $lama = (string) $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRow()->file_kontrak;

        $pmk = (new PmkPengajuanService())->ubah($auth, $id, $this->isianPmk(['instansi' => 'PT Baru', 'status' => '1']), ['file_kontrak' => $this->berkasPdf('kontrak2.pdf')]);

        $this->assertSame(['PT Baru', 3], [$pmk['instansi'], (int) $pmk['status']], 'status tidak berubah [K]');
        $this->assertNotSame($lama, (string) $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRow()->file_kontrak);
        $this->assertSame($nip, $pmk['nip']);
    }

    public function testHapusDitolakBilaSelesai(): void
    {
        [, $auth]       = $this->aktorStudi(Role::PEGAWAI);
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);
        $a = (int) (new PmkPengajuanService())->tambah($auth, $this->isianPmk(), $this->berkasPmk())['id_riwayat_pmk_req'];
        $b = (int) (new PmkPengajuanService())->tambah($auth, $this->isianPmk(['tgl_awal' => '2005-01-01', 'tgl_akhir' => '2008-12-31']), $this->berkasPmk())['id_riwayat_pmk_req'];

        (new PmkApprovalService())->proses($pic, $a, ['aksi' => 'setujui']);
        $this->assertSame(['status'], $this->kunci(fn () => (new PmkPengajuanService())->hapus($auth, $a)));

        (new PmkPengajuanService())->hapus($auth, $b);
        $this->assertSame(0, $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $b)->countAllResults());
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'riwayat_pmk_req')->where('entity_id', (string) $b)->where('event', 'delete')->countAllResults());
    }

    public function testKalkulatorMker(): void
    {
        $this->assertSame(['mker_th' => 3, 'mker_bl' => 11, 'peringatan' => null], (new PmkPengajuanService())->hitungMker(['tgl_awal' => '2010-01-15', 'tgl_akhir' => '2013-12-31']));
        $this->assertNotNull((new PmkPengajuanService())->hitungMker(['tgl_awal' => '2010-01-15', 'tgl_akhir' => '2010-02-01'])['peringatan']);

        $this->expectException(ValidationException::class);
        (new PmkPengajuanService())->hitungMker(['tgl_awal' => '2013-01-01', 'tgl_akhir' => '2012-01-01']);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function isianPmk(array $override = []): array
    {
        return $override + ['jenis_pmk' => 'Swasta', 'instansi' => 'PT Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2013-03-15', 'mker_th' => '3', 'mker_bl' => '2'];
    }

    /**
     * @return array<string, UploadedFile>
     */
    private function berkasPmk(): array
    {
        return array_map(fn (string $k): UploadedFile => $this->berkasPdf("{$k}.pdf"), array_combine(array_keys(PmkPengajuanService::BERKAS), array_keys(PmkPengajuanService::BERKAS)));
    }

    /**
     * @return array<string, list<string>>
     */
    private function galat(Closure $aksi): array
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            return $e->getErrors() ?? [];
        }

        $this->fail('Seharusnya 422');
    }

    /**
     * @return list<string>
     */
    private function kunci(Closure $aksi): array
    {
        return $this->urut(array_keys($this->galat($aksi)));
    }

    /**
     * @param list<string> $k
     *
     * @return list<string>
     */
    private function urut(array $k): array
    {
        sort($k);

        return $k;
    }
}
