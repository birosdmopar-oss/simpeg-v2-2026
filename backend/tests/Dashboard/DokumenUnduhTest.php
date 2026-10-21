<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Dokumen\DokumenService;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Pdf\KopDokumen;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\DokumenUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use ZipArchive;

/**
 * F-09 unduhan (MAKE-037, PRD §7.2.11 DokumenPdfTest + DokumenAuditTest): PDF `%PDF`/MIME/nama/inline, XLSX (zip,
 * header lengkap), kop `web_config` (KopDokumen), Baperjakat POST (NIP terpilih, judul bebas, NIP asing → 422),
 * throttle 429 pada unduhan ke-7 dalam semenit, audit `dokumen_unduhan` (fail-closed: 403/422 tidak diaudit), fallback
 * F7-15 (PDF hanya per unit/satker, batas baris PDF).
 *
 * @internal
 */
final class DokumenUnduhTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DokumenUjiTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/dokumen';

    /** @var list<string> */
    private array $nip = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->pasangThrottlerUji();
        $this->siapkanMasterDokumen();

        $this->nip[] = $this->pegawaiDokumen('A1', ['nama' => 'Citra', 'jenis' => 1, 'lahir' => '1968-05-15', 'pangkat' => 'IV/a', 'eselon' => 1, 'cpns' => '1995-03-01']);
        $this->nip[] = $this->pegawaiDokumen('A1', ['nama' => 'Budi', 'jenis' => 1, 'lahir' => '1985-01-01', 'pangkat' => 'III/a']);
        $this->nip[] = $this->pegawaiDokumen('B1', ['nama' => 'Gina', 'jenis' => 6, 'lahir' => '1990-01-01', 'gol_pppk' => 'V']);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testPdfDukInlineDanAttachmentPlusAudit(): void
    {
        $idSatker = (string) $this->satker['A1'];
        $result   = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/pdf', ['lingkup' => 'satker', 'id' => $idSatker]);
        $result->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', $result->response()->getBody());
        $this->assertMatchesRegularExpression('/^attachment; filename="duk_satker' . $idSatker . '_\d{14}\.pdf"$/', $result->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $result->response()->getHeaderLine('X-Content-Type-Options'));

        $inline = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/pdf', ['inline' => '1', 'lingkup' => 'unit', 'id' => (string) $this->unit['A']]);
        $inline->assertStatus(200);
        $this->assertStringStartsWith('inline; filename="duk_unit' . $this->unit['A'] . '_', $inline->response()->getHeaderLine('Content-Disposition'));

        $audit = $this->auditUnduhan('duk');
        $this->assertCount(2, $audit);
        $this->assertSame('create', $audit[0]['event']);
        $this->assertSame(AuthSeeder::nipForRole(Role::SUPER_ADMIN), $audit[0]['nip_actor']);
        $after = json_decode((string) $audit[1]['after_json'], true);
        $this->assertSame(['duk', 'pdf', 'unit', $this->unit['A'], 2], [$after['jenis'], $after['format'], $after['lingkup'], $after['id'], $after['jumlah_baris']]);
    }

    /**
     * Fallback LOCKED F7-15 (ADR-034): PDF lingkup kementerian → 422 `errors.lingkup`; XLSX kementerian tetap 200. 422
     * ditolak sebelum throttle (tidak memakan jatah unduhan) dan tidak diaudit.
     */
    public function testPdfKementerian422XlsxKementerianTetapBoleh(): void
    {
        foreach (['duk', 'nominatif', 'pensiun'] as $jenis) {
            $result = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . "/{$jenis}/pdf");
            $result->assertStatus(422);
            $this->assertSame([DokumenService::PESAN_PDF_LINGKUP], $this->json($result)['errors']['lingkup'], $jenis);
        }

        // Lebih dari jatah throttle (6/menit) permintaan 422 → unduhan berikutnya tetap dilayani.
        for ($i = 0; $i < DokumenService::UNDUH_PER_MENIT + 1; $i++) {
            $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/pdf', ['lingkup' => 'kementerian'])->assertStatus(422);
        }

        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', DokumenService::ENTITY_AUDIT)->countAllResults());

        $xlsx = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/xlsx');
        $xlsx->assertStatus(200);
        $this->assertStringStartsWith('attachment; filename="duk_kementerian_', $xlsx->response()->getHeaderLine('Content-Disposition'));
        $this->assertCount(1, $this->auditUnduhan('duk'));
    }

    /**
     * Batas baris PDF (PDF_MAKS_BARIS, hasil uji volume): satker di atas batas → 422 sebelum render, XLSX satker yang sama
     * 200; Baperjakat PDF dengan NIP di atas batas → 422 `errors.nip` (XLSX boleh s.d. 2.000 NIP).
     */
    public function testPdfDiAtasBatasBaris422(): void
    {
        $idSatker = $this->seedSatkerBesar('B1', DokumenService::PDF_MAKS_BARIS + 1);

        $pdf = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/pdf', ['lingkup' => 'satker', 'id' => (string) $idSatker]);
        $pdf->assertStatus(422);
        $this->assertSame([DokumenService::PESAN_PDF_BATAS], $this->json($pdf)['errors']['lingkup']);
        $this->assertCount(0, $this->auditUnduhan('duk'));

        $xlsx = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/xlsx', ['lingkup' => 'satker', 'id' => (string) $idSatker]);
        $xlsx->assertStatus(200);
        $after = json_decode((string) $this->auditUnduhan('duk')[0]['after_json'], true);
        $this->assertSame(DokumenService::PDF_MAKS_BARIS + 1, $after['jumlah_baris']);

        $nip = array_map(static fn (int $i): string => sprintf('1998%06d%08d', $i, 20000000 + $i), range(1, DokumenService::PDF_MAKS_BARIS + 1));
        $bap = $this->asRole(Role::SUPER_ADMIN)->withBodyFormat('json')->post(self::BASE . '/baperjakat/pdf', ['nip' => $nip, 'judul' => 'Baperjakat Uji']);
        $bap->assertStatus(422);
        $this->assertSame([DokumenService::PESAN_PDF_BATAS_NIP], $this->json($bap)['errors']['nip']);
        $this->assertCount(0, $this->auditUnduhan('baperjakat'));
    }

    public function testXlsxNominatifSemuaKolomDanPensiunParameter(): void
    {
        $result = $this->asRole(Role::PIMPINAN)->get(self::BASE . '/nominatif/xlsx');
        $result->assertStatus(200);
        $this->assertSame(EksporTabel::MIME[EksporTabel::XLSX], $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="nominatif_kementerian_', $result->response()->getHeaderLine('Content-Disposition'));
        $sheet = $this->sheet($result->response()->getBody());
        foreach (['NO', 'NAMA', 'NIP LAMA', 'KP (TMT)', 'KGB (MKER)', 'PENSIUN (BL)', 'Citra', 'Gina'] as $teks) {
            $this->assertStringContainsString($teks, $sheet, $teks);
        }

        $tahun  = (int) substr(TanggalBisnis::hariIni(), 0, 4);
        $result = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pensiun/xlsx', ['tahun' => '2026', 'sampai' => '2028']);
        $result->assertStatus(200);
        $this->assertStringContainsString('TGL PENSIUN', $this->sheet($result->response()->getBody()));
        $after = json_decode((string) $this->auditUnduhan('pensiun')[0]['after_json'], true);
        $this->assertSame(['tahun' => 2026, 'sampai' => 2028, 'search' => ''], $after['parameter']);
        $this->assertGreaterThanOrEqual(2000, $tahun);

        $rentang = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pensiun/pdf', ['lingkup' => 'unit', 'id' => (string) $this->unit['A'], 'tahun' => '2026', 'sampai' => '2040']);
        $rentang->assertStatus(422);
        $this->assertArrayHasKey('sampai', $this->json($rentang)['errors']);
        $this->assertCount(1, $this->auditUnduhan('pensiun'), '422 tidak diaudit');
    }

    public function testKopDokumenDariWebConfigDanOverrideUnitSatker(): void
    {
        $this->db->table('web_config')->insertBatch([
            ['config_name' => 'pdf_header_nama_kementerian', 'config_value' => 'Kementerian Uji'],
            ['config_name' => 'pdf_header_alamat_kementerian', 'config_value' => 'Jl. Pusat No. 1'],
        ]);
        $this->db->table('unit')->where('id_unit', $this->unit['A'])->update(['alamat_pdf_header' => 'Jl. Unit A']);
        $this->db->table('satker')->where('id_satker', $this->satker['A1'])->update(['alamat_pdf_header' => 'Jl. Satker A1 <b>']);
        $this->cacheUji->invalidate(WebConfigService::CACHE_KEY);

        $kemen = KopDokumen::html(null, null, $this->db);
        $this->assertStringContainsString('KEMENTERIAN UJI', $kemen);
        $this->assertStringContainsString('Jl. Pusat No. 1', $kemen);
        $this->assertStringNotContainsString('UNIT A', $kemen);

        $unit = KopDokumen::html($this->unit['A'], null, $this->db);
        $this->assertStringContainsString('UNIT A', $unit);
        $this->assertStringContainsString('Jl. Unit A', $unit);

        $satker = KopDokumen::html(null, $this->satker['A1'], $this->db);
        $this->assertStringContainsString('SATKER A1', $satker);
        $this->assertStringContainsString('Jl. Satker A1</td>', $satker, 'tag HTML di alamat dibuang (strip_tags) lalu di-escape');
        $this->assertStringNotContainsString('<b>Jl', $satker);
        $this->assertStringNotContainsString('<img', $satker, 'tanpa logo (F7-15)');
    }

    public function testBaperjakatPostPdfXlsxDanValidasi(): void
    {
        $body = ['nip' => [$this->nip[0], $this->nip[1]], 'judul' => 'Bahan Sidang Baperjakat', 'subjudul' => 'Periode I', 'teks' => 'Daftar calon.'];

        $pdf = $this->asRole(Role::SUPER_ADMIN)->withBodyFormat('json')->post(self::BASE . '/baperjakat/pdf', $body);
        $pdf->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $pdf->response()->getBody());
        $this->assertStringStartsWith('attachment; filename="baperjakat_kementerian_', $pdf->response()->getHeaderLine('Content-Disposition'));

        $xlsx = $this->asRole(Role::MENTERI)->withBodyFormat('json')->post(self::BASE . '/baperjakat/xlsx', $body);
        $xlsx->assertStatus(200);
        $sheet = $this->sheet($xlsx->response()->getBody());
        $this->assertStringContainsString('Citra', $sheet);
        $this->assertStringNotContainsString('Gina', $sheet);

        $audit = $this->auditUnduhan('baperjakat');
        $this->assertCount(2, $audit);
        $after = json_decode((string) $audit[0]['after_json'], true);
        $this->assertSame(['Bahan Sidang Baperjakat', 2, 2], [$after['parameter']['judul'], $after['parameter']['jumlah_nip'], $after['jumlah_baris']]);

        // NIP asing / nonaktif → 422 errors.nip; judul kosong → 422; GET pdf baperjakat → 404.
        $asing = $this->asRole(Role::SUPER_ADMIN)->withBodyFormat('json')->post(self::BASE . '/baperjakat/pdf', ['nip' => [$this->nip[0], '199999999999999999'], 'judul' => 'X']);
        $asing->assertStatus(422);
        $this->assertStringContainsString('199999999999999999', $this->json($asing)['errors']['nip'][0]);
        $this->asRole(Role::SUPER_ADMIN)->withBodyFormat('json')->post(self::BASE . '/baperjakat/pdf', ['nip' => [$this->nip[0]]])->assertStatus(422);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/baperjakat/pdf')->assertStatus(404);
        $this->assertCount(2, $this->auditUnduhan('baperjakat'));
    }

    public function testThrottleEnamPerMenitPerAkun(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/xlsx')->assertStatus(200);
        }

        $ketujuh = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/xlsx');
        $ketujuh->assertStatus(429);
        $this->assertCount(6, $this->auditUnduhan('duk'));
        // Daftar JSON tidak dibatasi; akun lain tidak terpengaruh.
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk')->assertStatus(200);
        $this->asRole(Role::PIMPINAN)->get(self::BASE . '/duk/xlsx')->assertStatus(200);
    }

    public function testRoleTanpaHakDanTanpaToken(): void
    {
        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $this->asRole($role)->get(self::BASE . '/duk/pdf')->assertStatus(403);
            $this->asRole($role)->withBodyFormat('json')->post(self::BASE . '/baperjakat/xlsx', ['nip' => [$this->nip[0]], 'judul' => 'X'])->assertStatus(403);
        }

        $this->clearAuthState();
        $this->withHeaders([])->get(self::BASE . '/duk/xlsx')->assertStatus(401);
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'dokumen_unduhan')->countAllResults());
    }

    /**
     * $jumlah PNS aktif sintetis di satker $kode (insertBatch, NIP awalan 1998…) untuk uji batas baris PDF.
     */
    private function seedSatkerBesar(string $kode, int $jumlah): int
    {
        $j   = $this->buatJabatan($this->satker[$kode]);
        $peg = [];
        $pmj = [];
        $kp  = [];
        $idP = $this->pangkat['III/a'];

        for ($i = 0; $i < $jumlah; $i++) {
            $nip   = sprintf('1998%06d%08d', $i, 30000000 + $i);
            $peg[] = ['nip' => $nip, 'nama' => 'Pegawai Batas ' . $i, 'tgl_lahir' => '1980-01-15', 'jenis_kelamin' => 1, 'status' => 1, 'id_jenis_pegawai' => 1];
            $pmj[] = ['nip' => $nip, 'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2015-01-01', 'id_unit' => $j['id_unit'], 'id_satker' => $j['id_satker'], 'id_group_jabatan' => $j['id_group_jabatan'], 'id_sub_group_jabatan' => $j['id_sub_group_jabatan'], 'id_jabatan' => $j['id_jabatan'], 'unit' => 'Unit', 'satker' => 'Satker ' . $kode, 'jabatan' => 'Jabatan ' . $kode];
            $kp[]  = ['nip' => $nip, 'id_pangkat' => $idP, 'tmtsk' => '2010-04-01', 'tgl_sk' => '2010-03-01', 'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda'];
        }

        foreach ([['pegawai', $peg], ['pegawai_mutasi_jabatan', $pmj], ['pegawai_kp', $kp]] as [$tabel, $rows]) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->db->table($tabel)->insertBatch($chunk);
            }
        }

        return (int) $j['id_satker'];
    }

    private function sheet(string $isi): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $isi);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        return $sheet;
    }
}
