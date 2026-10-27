<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\StatistikUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use ZipArchive;

/**
 * F-03 ekspor XLSX (MAKE-036, PRD §7.1.8 StatistikEksporTest, F7-11/F7-27): berkas zip OOXML, MIME, nama berkas,
 * isi judul/periode/header/subtotal, audit `dokumen_unduhan`, role 2 → 403 tanpa audit.
 *
 * @internal
 */
final class StatistikEksporTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use StatistikUjiTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/statistik';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->siapkanMasterStatistik();

        $lahir = date('Y-m-d', strtotime(TanggalBisnis::hariIni() . ' -40 years -100 days'));
        $this->pegawaiStatistik('A1', ['jenis' => 1, 'lahir' => $lahir]);
        $this->pegawaiStatistik('A2', ['jenis' => 6, 'lahir' => $lahir]);
        $this->pegawaiStatistik('B1', ['jenis' => 1, 'lahir' => $lahir]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testUnduhXlsxUnitKerjaDanAudit(): void
    {
        $result = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja/xlsx');
        $result->assertStatus(200);
        $result->assertHeader('Content-Type', EksporTabel::MIME[EksporTabel::XLSX]);
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertMatchesRegularExpression('/^attachment; filename="statistik_unit-kerja_kementerian_\d{14}\.xlsx"$/', $result->response()->getHeaderLine('Content-Disposition'));

        $isi = $result->response()->getBody();
        $this->assertStringStartsWith('PK', $isi);

        $sheet = $this->sheet($isi);
        $this->assertStringContainsString('Formasi Unit Kerja', $sheet);
        $this->assertStringContainsString('Periode: ' . date('d M Y', strtotime(TanggalBisnis::hariIni())), $sheet);
        $this->assertStringContainsString('UNIT KERJA ESELON I', $sheet);
        $this->assertStringContainsString('PNS', $sheet);
        $this->assertStringContainsString('PPPK', $sheet);
        $this->assertStringContainsString('Unit A', $sheet);
        $this->assertStringContainsString('SUBTOTAL', $sheet);

        $audit = $this->db->table('audit_logs')->where('entity', 'dokumen_unduhan')->where('entity_id', 'statistik_unit-kerja')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame('create', $audit[0]['event']);
        $this->assertSame(AuthSeeder::nipForRole(Role::SUPER_ADMIN), $audit[0]['nip_actor']);
        $after = json_decode((string) $audit[0]['after_json'], true);
        // Kolom JSON MySQL menata ulang urutan key → bandingkan tanpa urutan.
        $this->assertEquals(['jenis' => 'statistik_unit-kerja', 'format' => 'xlsx', 'lingkup' => 'kementerian', 'id' => null, 'jumlah_baris' => 2], array_intersect_key($after, array_flip(['jenis', 'format', 'lingkup', 'id', 'jumlah_baris'])));
        $this->assertSame(TanggalBisnis::hariIni(), $after['parameter']['periode']);
    }

    public function testLingkupSatkerPadaNamaBerkasDanAudit(): void
    {
        $result = $this->asRole(Role::PIMPINAN)->get(self::BASE . '/golongan/xlsx', ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']]);
        $result->assertStatus(200);
        $this->assertStringContainsString('statistik_golongan_satker' . $this->satker['A2'] . '_', $result->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('Satker A2', $this->sheet($result->response()->getBody()));

        $after = json_decode((string) $this->db->table('audit_logs')->where('entity_id', 'statistik_golongan')->get()->getRow('after_json'), true);
        $this->assertSame(['satker', $this->satker['A2']], [$after['lingkup'], $after['id']]);
    }

    public function testRoleTanpaHakTidakMengunduhDanTidakDiaudit(): void
    {
        $this->asRole(Role::PEGAWAI)->get(self::BASE . '/unit-kerja/xlsx')->assertStatus(403);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/agama/xlsx')->assertStatus(404);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja/xlsx', ['lingkup' => 'x'])->assertStatus(422);

        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'dokumen_unduhan')->countAllResults());
    }

    /**
     * Teks sheet pertama dari berkas XLSX (OOXML inline string).
     */
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
