<?php

declare(strict_types=1);

namespace Tests\Layanan\Dashboard;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\LayananDashboardService;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\DashboardUjiTrait;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\SisipMinimalTrait;
use ZipArchive;

/**
 * `GET layanan/status/ekspor` ([V2] MAKE-046; legacy tidak punya halaman Status Layanan): role & lingkup sama dengan
 * `GET layanan/status` (role 8 → 403, role 3 hanya satker, role 2 hanya data sendiri), filter diteruskan, format tidak sah
 * 422, isi CSV/XLSX sesuai kolom, dan `can_export` pada daftar.
 *
 * @internal
 */
final class LayananStatusEksporTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DashboardUjiTrait;
    use FeatureTestTrait;
    use LayananStudiUjiTrait;
    use SisipMinimalTrait;

    protected $seed = MasterDataSeeder::class;

    private int $satker;

    private string $dalam;

    private string $luar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();

        $this->satker  = $this->buatSatker();
        [$this->dalam] = $this->aktorStudi(Role::PEGAWAI, [], $this->satker);
        [$this->luar]  = $this->aktorStudi(Role::PEGAWAI);

        foreach ([$this->dalam, $this->luar] as $nip) {
            $this->sisipCuti($nip);
            $this->sisipStudi('tb', $nip);
            $this->sisipStudi('ib', $nip);
            $this->sisipPmk($nip);
        }
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testCsvMemuatHeaderKolomDanBarisSesuaiFilter(): void
    {
        [$admin] = $this->aktorStudi(Role::SUPER_ADMIN);

        $res = $this->sebagai($admin)->get('api/v1/layanan/status/ekspor?format=csv&jenis=tb&search=' . substr($this->dalam, -8));
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));
        $this->assertMatchesRegularExpression('/^attachment; filename="status_layanan_\d{8}_\d{6}\.csv"$/', $res->response()->getHeaderLine('Content-Disposition'));

        $csv = $res->response()->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $baris = array_map(static fn (string $b): array => str_getcsv($b, ',', '"', ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
        $this->assertSame(LayananDashboardService::HEADER_EKSPOR, $baris[0]);
        $this->assertCount(2, $baris, 'header + satu baris TB milik NIP yang dicari (filter jenis & search diteruskan)');
        $this->assertSame('="' . $this->dalam . '"', $baris[1][1], 'NIP ditulis sebagai teks Excel');
        $this->assertSame('Tugas Belajar', $baris[1][3]);
        $this->assertStringStartsWith('Tugas Belajar S.1 - Universitas Uji', $baris[1][4]);
        $this->assertMatchesRegularExpression('/^\d{2}-\d{2}-\d{4}$/', $baris[1][5]);
        $this->assertSame('Menunggu PIC', $baris[1][6]);
        $this->assertSame(LayananDashboardService::CATATAN_TANPA_PIC, $baris[1][7]);
    }

    public function testXlsxDefaultDanIsiSheet(): void
    {
        [$admin] = $this->aktorStudi(Role::SUPER_ADMIN);

        $res = $this->sebagai($admin)->get('api/v1/layanan/status/ekspor?status=berjalan');
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertMatchesRegularExpression('/filename="status_layanan_\d{8}_\d{6}\.xlsx"/', $res->response()->getHeaderLine('Content-Disposition'));

        $sheet = $this->sheet($res->response()->getBody());
        $this->assertStringContainsString('<t', $sheet);
        $this->assertStringContainsString('Jenis Layanan', $sheet);
        $this->assertStringContainsString('PIC/Petugas', $sheet);
        $this->assertStringContainsString($this->dalam, $sheet);
        $this->assertStringContainsString($this->luar, $sheet);
    }

    public function testLingkupSamaDenganDaftarRole3Role2(): void
    {
        [$admin3] = $this->aktorStudi(Role::ADMIN_SATKER, [], $this->satker);
        $csv3     = $this->sebagai($admin3)->get('api/v1/layanan/status/ekspor?format=csv')->response()->getBody();

        $this->assertStringContainsString($this->dalam, $csv3);
        $this->assertStringNotContainsString($this->luar, $csv3, 'lingkup role 3 dipaksa server');
        $this->assertSame(4, substr_count($csv3, '=""' . $this->dalam . '""'), 'empat jenis layanan milik NIP dalam lingkup');

        $csv2 = $this->sebagai($this->dalam)->get('api/v1/layanan/status/ekspor?format=csv&id_satker=' . $this->buatSatker())->response()->getBody();
        $this->assertStringNotContainsString($this->luar, $csv2, 'role 2 hanya data sendiri');
        $this->assertStringNotContainsString($this->dalam, $csv2, 'filter satker lain diterapkan');
    }

    public function testRoleTerbatasModulDanRole8Ditolak(): void
    {
        [$ptt] = $this->aktorStudi(Role::PTT);
        $this->sisipCuti($ptt);
        $this->sisipStudi('tb', $ptt);
        $this->sisipStudi('ib', $ptt);

        $csv = $this->sebagai($ptt)->get('api/v1/layanan/status/ekspor?format=csv')->response()->getBody();
        $this->assertStringContainsString('Tugas Belajar', $csv);
        $this->assertStringNotContainsString('Izin Belajar', $csv);

        [$pimpinan] = $this->aktorStudi(Role::PIMPINAN);
        $this->sebagai($pimpinan)->get('api/v1/layanan/status/ekspor')->assertStatus(403);
    }

    public function testRole8DitolakDiService(): void
    {
        [, $auth] = $this->aktorStudi(Role::PIMPINAN);

        $this->expectException(ForbiddenException::class);
        (new LayananDashboardService())->ekspor($auth, []);
    }

    public function testFormatDanFilterTidakSah422(): void
    {
        [$admin] = $this->aktorStudi(Role::SUPER_ADMIN);

        $res = $this->sebagai($admin)->get('api/v1/layanan/status/ekspor?format=pdf');
        $res->assertStatus(422);
        $this->assertArrayHasKey('format', json_decode((string) $res->getJSON(), true)['errors']);

        $this->sebagai($admin)->get('api/v1/layanan/status/ekspor?format=csv&jenis=karpeg')->assertStatus(422);
        $this->sebagai($admin)->get('api/v1/layanan/status/ekspor?format=csv&status=1')->assertStatus(422);
    }

    public function testBatasBarisDanKosong(): void
    {
        [, $auth] = $this->aktorStudi(Role::SUPER_ADMIN);

        $kosong = (new LayananDashboardService())->ekspor($auth, ['format' => 'csv', 'search' => 'tidak-ada-%']);
        $this->assertSame(1, count(array_filter(explode('
', substr($kosong['isi'], 3)))), 'hanya header');

        $batas = new class () extends LayananDashboardService {
            protected function maksEkspor(): int
            {
                return 2;
            }
        };

        try {
            $batas->ekspor($auth, ['format' => 'csv']);
            $this->fail('Seharusnya 422 (melebihi batas baris).');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('maksimal 2 baris', $e->getErrors()['format'][0]);
        }

        $this->assertSame(20000, LayananDashboardService::MAKS_EKSPOR);
    }

    public function testDaftarMemuatCanExport(): void
    {
        [$admin] = $this->aktorStudi(Role::SUPER_ADMIN);
        $res     = $this->sebagai($admin)->get('api/v1/layanan/status');
        $res->assertStatus(200);
        $this->assertTrue(json_decode((string) $res->getJSON(), true)['data']['can_export']);
    }

    private function sebagai(string $nip): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }

    private function sheet(string $xlsx): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'uji');
        file_put_contents($tmp, $xlsx);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $isi = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);

        return $isi;
    }
}
