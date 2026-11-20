<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Presensi;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use Config\Services;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * CR-164 butir 8 (pagar paritas step 3, test karakterisasi): impor Slip Gaji — 409 `konflik_data` (KonflikImporSlip),
 * tepat satu entri audit `import` per commit sukses, pemetaan galat parse berkas (status HTTP + pesan) di pratinjau dan
 * commit, serta jalur 500 "gagal commit" untuk galat non-ApiException. Mengunci perilaku SEKARANG. Data & NIP sintetis.
 * Perilaku kunci baris dengan koneksi kedua ada di {@see ImporSlipKunciParitasTest}.
 *
 * @internal
 */
final class ImporSlipParitasTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    /**
     * perilaku sekarang (dikunci CR-164): `message` amplop tidak seragam — galat dari ValidasiBerkas::periksaImpor memakai
     * pesan umum "Validasi gagal." sedangkan `ValidationException::forField` lain memakai pesan field-nya sendiri.
     */
    private const PESAN_UTAMA = [
        'ekstensi csv'      => 'Validasi gagal.',
        'pdf berkedok xlsx' => 'Validasi gagal.',
        'xlsx terpotong'    => 'Validasi gagal.',
    ];

    private int $maksBaris;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->maksBaris = config(Presensi::class)->maksBarisImporPerSheet;
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->maksBarisImporPerSheet = $this->maksBaris;
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testKonflikUniqueJadi409TanpaAuditImport(): void
    {
        $a     = $this->buatPegawai(['nama' => 'Ahmad Satu']);
        $admin = $this->adminSlip();
        $token = $this->token($admin, $a, 'Ahmad Satu');
        $kait  = static function (BaseConnection $db, string $nip, PeriodeSlip $periode): void {
            $db->table('gaji_pegawai')->insert(['nip' => $nip, 'bulan' => $periode->bulan, 'tahun' => $periode->tahun, 'created_at' => '2026-10-27 00:00:00']);
        };

        try {
            (new ImporSlipService(null, null, null, null, $kait))->commit($this->auth($admin), $token, null);
            $this->fail('1062 harus menjadi 409');
        } catch (ApiException $e) {
            $this->assertSame([409, KodeGalatSlip::KONFLIK_DATA], [$e->getStatusCode(), $e->getKode()]);
            $this->assertSame("Data slip NIP {$a} periode September 2026 sudah ditambahkan proses lain. Tidak ada data yang disimpan; ulangi unggah dan pratinjau.", $e->getMessage());
            $this->assertSame(['nip_periode' => ["{$a} 2026-09"]], $e->getErrors());
        }

        $this->assertSame(0, $this->db->table('gaji_pegawai')->where('nip', $a)->countAllResults(), 'rollback, termasuk sisipan seam');
        $this->assertSame(0, $this->jumlahAuditImport());
        $this->assertSame([], $this->isiDirPratinjau());
    }

    public function testCommitSuksesTepatSatuEntriAudit(): void
    {
        $a     = $this->buatPegawai(['nama' => 'Ahmad Satu']);
        $b     = $this->buatPegawai(['nama' => 'Budi Dua']);
        $admin = $this->adminSlip();
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($a, 9, 2026, 'Ahmad Satu'), $this->barisGpp($b, 8, 2026, 'Budi Dua')],
            [$this->barisTk($a, 9, 2026, 'Ahmad Satu'), $this->barisTk($b, 8, 2026, 'Budi Dua')],
            namaKlien: 'slip_paritas.xlsx',
        )))['token_pratinjau'];
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', ImporSlipService::ENTITAS_AUDIT)->countAllResults(), 'pratinjau tanpa nama tidak cocok tidak menulis audit');

        Services::resetSingle('response');
        $data = $this->dataOk($this->commitSlip($admin, $token));
        $this->assertSame([2, 0, 0, 0], [$data['disisip'], $data['diperbarui'], $data['dilewati'], $data['gagal']]);

        $audit = $this->db->table('audit_logs')->where('entity', ImporSlipService::ENTITAS_AUDIT)->get()->getResultArray();
        $this->assertCount(1, $audit, 'tepat satu entri audit per commit (ringkasan, bukan per baris)');
        $this->assertSame(['import', substr(hash('sha256', $token), 0, 8)], [$audit[0]['event'], $audit[0]['entity_id']]);
        $after = (array) json_decode((string) $audit[0]['after_json'], true);
        ksort($after);
        $this->assertSame(['dilewati' => 0, 'diperbarui' => 0, 'disisip' => 2, 'filter' => null, 'gagal' => 0, 'nama_berkas' => 'slip_paritas.xlsx', 'periode' => ['2026-08', '2026-09'], 'timpa' => true], $after);
    }

    /**
     * Pemetaan galat parse berkas: setiap galat parse (pratinjau maupun commit) → 422 `errors.berkas`, bukan 500.
     */
    public function testPemetaanGalatParseBerkas(): void
    {
        $n     = $this->buatPegawai(['nama' => 'Fajar Nugroho']);
        $admin = $this->adminSlip();
        $baik  = fn (): array => [[$this->barisGpp($n, 9, 2026, 'Fajar Nugroho')], [$this->barisTk($n, 9, 2026, 'Fajar Nugroho')]];
        $utuh  = $this->berkasSlip(...$baik());
        $kasus = [
            'tanpa berkas'       => [null, 'Berkas wajib diunggah.'],
            'ekstensi csv'       => [$this->berkasMentah("nip,bulan\n1,9\n", 'slip.csv'), ImporSlipService::PESAN_FORMAT],
            'pdf berkedok xlsx'  => [$this->berkasMentah("%PDF-1.4\n%%EOF", 'slip.xlsx'), ImporSlipService::PESAN_FORMAT],
            'xlsx terpotong'     => [$this->berkasMentah(substr((string) file_get_contents($utuh->getTempName()), 0, 600), 'slip.xlsx'), 'Isi berkas tidak sesuai dengan ekstensinya.'],
            'zip bukan workbook' => [$this->berkasMentah($this->zipSampah(), 'slip.xlsx'), PembacaExcelSlip::PESAN_TIDAK_TERBACA],
            'sheet TK tidak ada' => [$this->berkasSatuSheet(), 'Berkas tidak memiliki sheet TK. Pastikan nama sheet sesuai template (GPP dan TK).'],
            'sheet GPP kosong'   => [$this->berkasSlip([], [$this->barisTk($n, 9, 2026, 'Fajar Nugroho')]), 'Sheet GPP kosong atau tidak memiliki baris data.'],
        ];

        $aktual = [];
        $harap  = [];

        foreach ($kasus as $label => [$berkas, $pesan]) {
            $res            = $this->unggahSlip($admin, $berkas);
            $aktual[$label] = [$res->response()->getStatusCode(), $this->json($res)['message'], $this->json($res)['errors']];
            $harap[$label]  = [422, self::PESAN_UTAMA[$label] ?? $pesan, ['berkas' => [$pesan]]];
            Services::resetSingle('response');
        }

        $this->assertSame($harap, $aktual);

        // Batas baris di pratinjau.
        config(Presensi::class)->maksBarisImporPerSheet = 0;
        $res                                            = $this->unggahSlip($admin, $this->berkasSlip(...$baik()));
        $this->assertSame([422, ['berkas' => ['Jumlah baris melebihi batas 0 per sheet.']]], [$res->response()->getStatusCode(), $this->json($res)['errors']]);
        Services::resetSingle('response');

        // Galat parse saat commit (validasi ulang penuh, berkas dibaca lagi): tetap 422 `errors.berkas`, bukan 500 gagal commit.
        config(Presensi::class)->maksBarisImporPerSheet = $this->maksBaris;
        $token                                          = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(...$baik())))['token_pratinjau'];
        Services::resetSingle('response');
        config(Presensi::class)->maksBarisImporPerSheet = 0;
        $res                                            = $this->commitSlip($admin, $token);
        $this->assertSame([422, 'Jumlah baris melebihi batas 0 per sheet.', ['berkas' => ['Jumlah baris melebihi batas 0 per sheet.']]], [$res->response()->getStatusCode(), $this->json($res)['message'], $this->json($res)['errors']]);
        $this->assertSame([0, 0], [$this->db->table('gaji_pegawai')->where('nip', $n)->countAllResults(), $this->jumlahAuditImport()]);
        $this->assertSame([], $this->isiDirPratinjau(), 'berkas pratinjau dihapus walau 422 saat commit');
    }

    /**
     * Galat non-ApiException di dalam transaksi commit (mis. galat DB selain 1062) → 500 generik "gagal commit".
     */
    public function testGalatNonApiSaatCommitJadi500GagalCommit(): void
    {
        $a     = $this->buatPegawai(['nama' => 'Ahmad Satu']);
        $admin = $this->adminSlip();
        $token = $this->token($admin, $a, 'Ahmad Satu');
        $kait  = static function (): void {
            throw new RuntimeException('galat sintetis');
        };

        try {
            (new ImporSlipService(null, null, null, null, $kait))->commit($this->auth($admin), $token, true);
            $this->fail('harus 500');
        } catch (ApiException $e) {
            // perilaku sekarang (dikunci CR-164): ImporSlipService.php:241-245 — semua Throwable non-Api → 500 tanpa kode & errors.
            $this->assertSame([500, ImporSlipService::PESAN_GAGAL_COMMIT, null, null], [$e->getStatusCode(), $e->getMessage(), $e->getKode(), $e->getErrors()]);
        }

        $this->assertSame([0, 0], [$this->db->table('gaji_pegawai')->where('nip', $a)->countAllResults(), $this->jumlahAuditImport()]);
        $this->assertSame([], $this->isiDirPratinjau());
    }

    /**
     * @param array<string, mixed> $admin
     */
    private function token(array $admin, string $nip, string $nama): string
    {
        $token = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($nip, 9, 2026, $nama)], [$this->barisTk($nip, 9, 2026, $nama)])))['token_pratinjau'];
        Services::resetSingle('response');

        return (string) $token;
    }

    private function berkasSatuSheet(): \CodeIgniter\HTTP\Files\UploadedFile
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->setTitle('GPP')->fromArray([['nip', 'bulan', 'tahun']], null, 'A1');
        $path = (string) tempnam(sys_get_temp_dir(), 'isp');
        IOFactory::createWriter($buku, 'Xlsx')->save($path);
        $buku->disconnectWorksheets();
        $isi = (string) file_get_contents($path);
        @unlink($path);

        return $this->berkasMentah($isi, 'satu_sheet.xlsx');
    }

    private function zipSampah(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'isz');
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', 'bukan xml');
        $zip->close();
        $isi = (string) file_get_contents($path);
        @unlink($path);

        return $isi;
    }

    private function jumlahAuditImport(): int
    {
        return $this->db->table('audit_logs')->where('entity', ImporSlipService::ENTITAS_AUDIT)->where('event', 'import')->countAllResults();
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function auth(array $akun): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $akun['id_pengguna'], 'id_pengguna' => (int) $akun['id_pengguna'], 'role' => (int) $akun['user_level'], 'id_satker' => $akun['id_satker'], 'nip' => null]);

        return $auth;
    }
}
