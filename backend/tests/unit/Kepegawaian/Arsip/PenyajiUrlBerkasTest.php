<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Arsip;

use App\Controllers\BerkasDevController;
use App\Libraries\Kepegawaian\Arsip\ArsipService;
use App\Libraries\Kepegawaian\Arsip\PenyajiUrlBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use Config\Arsip;
use Config\Services;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MAKE-090 — penentu URL/status berkas Arsip (PRD F-10 §7.2, F10-19, F10-23, F10-24, F10-32, AC1-08, AC1-16) dan rute dev
 * `BerkasDevController` (§7.4): dua pola legacy saja, kunci v2 → 404, tipe & header daftar tertutup, realpath di akar.
 *
 * @internal
 */
final class PenyajiUrlBerkasTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    private const NIP = '199001012015011001';

    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = sys_get_temp_dir() . '/simpeg-arsip-uji-' . bin2hex(random_bytes(6));
        mkdir($this->akar . '/arsip/' . self::NIP . '/sk', 0777, true);
        mkdir($this->akar . '/assets/upload/x', 0777, true);
        mkdir($this->akar . '/2026/11', 0777, true);
        mkdir($this->akar . '/kolom', 0777, true);
        file_put_contents($this->akar . '/arsip/' . self::NIP . '/sk/SK CPNS.PDF', '%PDF-1.4');
        file_put_contents($this->akar . '/arsip/' . self::NIP . '/sk/catatan.html', '<script>1</script>');
        file_put_contents($this->akar . '/assets/upload/x/foto.png', 'PNG');
        file_put_contents($this->akar . '/2026/11/' . str_repeat('a', 32) . '.pdf', '%PDF-1.4');
        file_put_contents($this->akar . '/kolom/a.pdf', '%PDF-1.4');
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->akar, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->akar);
        Services::reset(true);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null}>
     */
    public static function pathProvider(): array
    {
        $n = self::NIP;

        return [
            'legacy awalan / + backslash + spasi + .PDF' => ["/arsip\\{$n}/sk/SK CPNS.PDF", 'ada', 'publik', "/berkas/arsip/{$n}/sk/SK%20CPNS.PDF", 'pratinjau'],
            'legacy html → unduh'                        => ["arsip/{$n}/sk/catatan.html", 'ada', 'publik', "/berkas/arsip/{$n}/sk/catatan.html", 'unduh'],
            'assets/upload'                              => ['assets/upload/x/foto.png', 'ada', 'publik', '/berkas/assets/upload/x/foto.png', 'pratinjau'],
            'kunci v2'                                   => ['2026/11/' . str_repeat('a', 32) . '.pdf', 'ada', 'privat', "pegawai/{$n}/arsip/berkas/7/buka", 'pratinjau'],
            'kunci v2 hilang'                            => ['2026/11/' . str_repeat('b', 32) . '.pdf', 'hilang', null, null, null],
            'legacy hilang'                              => ["arsip/{$n}/tidak-ada.pdf", 'hilang', null, null, null],
            'kolom/ (tidak cocok)'                       => ['kolom/a.pdf', 'tak_dapat_dibuka', null, null, null],
            'form-digital/'                              => ['form-digital/1/a.pdf', 'tak_dapat_dibuka', null, null, null],
            'segmen berawalan titik'                     => ["arsip/{$n}/.ssh/a.pdf", 'tak_dapat_dibuka', null, null, null],
            'traversal ../'                              => ["arsip/{$n}/../../kolom/a.pdf", 'tak_dapat_dibuka', null, null, null],
            'traversal ..\\'                             => ["arsip\\{$n}\\..\\..\\kolom\\a.pdf", 'tak_dapat_dibuka', null, null, null],
            'traversal ter-encode'                       => ["arsip/{$n}/%2e%2e/a.pdf", 'tak_dapat_dibuka', null, null, null],
            'byte kontrol'                               => ["arsip/{$n}/a\x00.pdf", 'tak_dapat_dibuka', null, null, null],
            'skema URL'                                  => ['http://contoh/arsip/a.pdf', 'tak_dapat_dibuka', null, null, null],
            'kunci v2 huruf besar (bukan kunci)'         => ['2026/11/' . str_repeat('A', 32) . '.pdf', 'tak_dapat_dibuka', null, null, null],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testNilaiPerPath(string $path, string $status, ?string $akses, ?string $url, ?string $jenis): void
    {
        $nilai = $this->penyaji('publik')->nilai($path, self::NIP, 7);

        $this->assertSame([$status, $akses, $url, $jenis, $status === 'ada'], [
            $nilai['status_berkas'], $nilai['akses_berkas'], $nilai['url_berkas'], $nilai['jenis_buka'], $nilai['berkas_ada'],
        ]);
    }

    public function testSaklarPrivatHanyaMengubahBerkasLegacy(): void
    {
        $penyaji = $this->penyaji('privat');

        $this->assertSame(['privat', 'pegawai/' . self::NIP . '/arsip/berkas/3/buka'], array_values(array_intersect_key($penyaji->nilai('assets/upload/x/foto.png', self::NIP, 3), array_flip(['akses_berkas', 'url_berkas']))));
        $this->assertSame('privat', $penyaji->nilai('2026/11/' . str_repeat('a', 32) . '.pdf', self::NIP, 4)['akses_berkas']);
        // Nilai saklar tidak dikenal → privat (fail-closed).
        $this->assertSame('privat', $this->penyaji('PUBLIK ')->nilai('assets/upload/x/foto.png', self::NIP, 3)['akses_berkas']);
        $this->assertSame('publik', $this->penyaji(' publik ')->nilai('assets/upload/x/foto.png', self::NIP, 3)['akses_berkas']);
    }

    public function testDasarUrlBisaDiganti(): void
    {
        $config                 = new Arsip();
        $config->dasarUrlBerkas = 'http://localhost:8080/berkas/';

        $this->assertSame('http://localhost:8080/berkas/assets/upload/x/foto.png', (new PenyajiUrlBerkas(new LocalStorageAdapter($this->akar), $config))->nilai('assets/upload/x/foto.png', self::NIP, 1)['url_berkas']);
    }

    public function testNamaArsipF1019(): void
    {
        $kunci = '2026/11/' . str_repeat('a', 32) . '.pdf';

        $this->assertSame('Tag', ArsipService::namaArsip(['id_attachment' => 1, 'tag' => 'Tag', 'display_name' => 'd', 'filename' => 'f', 'path' => $kunci]));
        $this->assertSame('d.pdf', ArsipService::namaArsip(['id_attachment' => 1, 'tag' => ' ', 'display_name' => 'd.pdf', 'filename' => 'f', 'path' => $kunci]));
        $this->assertSame('Berkas 9', ArsipService::namaArsip(['id_attachment' => 9, 'tag' => null, 'display_name' => '', 'filename' => basename($kunci), 'path' => $kunci]));
        $this->assertSame('SK.pdf', ArsipService::namaArsip(['id_attachment' => 9, 'tag' => null, 'display_name' => null, 'filename' => 'SK.pdf', 'path' => 'arsip/x/SK.pdf']));
        $this->assertSame('Berkas 5', ArsipService::namaArsip(['id_attachment' => 5, 'tag' => null, 'display_name' => null, 'filename' => null, 'path' => null]));
    }

    public function testRegexLegacySamaDenganBlokNginxPrd(): void
    {
        // Teks regex = PRD F-10 §7.2/§7.3 (dibandingkan dengan README-deploy §1l oleh test MAKE-093).
        $this->assertSame('^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$', Arsip::REGEX_LEGACY_ARSIP);
        $this->assertSame('^assets/upload/(?:[^/.][^/]*/)*[^/.][^/]*$', Arsip::REGEX_LEGACY_ASSETS);
        $this->assertSame('^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.[a-z0-9]{1,10}$', Arsip::REGEX_KUNCI_V2);
    }

    public function testRuteDevTidakTerdaftarDiTesting(): void
    {
        $routes = service('routes')->loadRoutes();

        foreach (array_keys($routes->getRoutes('GET')) as $from) {
            $this->assertDoesNotMatchRegularExpression('#^berkas(/|$)#', (string) $from);
        }

        $this->assertSame('testing', ENVIRONMENT);
        $this->assertStringContainsString("ENVIRONMENT === 'development' && config(Arsip::class)->sajiBerkasDev", (string) file_get_contents(APPPATH . 'Config/RoutesPegawai.php'));
    }

    public function testBerkasDevControllerMenirukanNginx(): void
    {
        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akar));

        $pdf = $this->controller(BerkasDevController::class)->execute('saji', 'arsip', self::NIP, 'sk', 'SK%20CPNS.PDF');
        $pdf->assertStatus(200);
        $r = $pdf->response();
        $this->assertSame('%PDF-1.4', (string) $r->getBody());
        $this->assertSame(['application/pdf', 'inline', 'nosniff', 'no-store', 'no-referrer', Arsip::CSP_BERKAS], [
            $r->getHeaderLine('Content-Type'), $r->getHeaderLine('Content-Disposition'), $r->getHeaderLine('X-Content-Type-Options'),
            $r->getHeaderLine('Cache-Control'), $r->getHeaderLine('Referrer-Policy'), $r->getHeaderLine('Content-Security-Policy'),
        ]);

        $html = $this->controller(BerkasDevController::class)->execute('saji', 'arsip', self::NIP, 'sk', 'catatan.html')->response();
        $this->assertSame(['application/octet-stream', 'attachment'], [$html->getHeaderLine('Content-Type'), $html->getHeaderLine('Content-Disposition')]);

        $png = $this->controller(BerkasDevController::class)->execute('saji', 'assets', 'upload', 'x', 'foto.png')->response();
        $this->assertSame([200, 'image/png'], [$png->getStatusCode(), $png->getHeaderLine('Content-Type')]);

        // Kunci v2, kolom/, traversal, segmen titik, tidak ada → 404.
        foreach ([
            ['2026', '11', str_repeat('a', 32) . '.pdf'],
            ['kolom', 'a.pdf'],
            ['arsip', self::NIP, '..', '..', 'kolom', 'a.pdf'],
            ['arsip', self::NIP, '%2e%2e', 'a.pdf'],
            ['arsip', '.git', 'config'],
            ['arsip', self::NIP, 'tidak-ada.pdf'],
        ] as $segmen) {
            $this->assertSame(404, $this->controller(BerkasDevController::class)->execute('saji', ...$segmen)->response()->getStatusCode(), implode('/', $segmen));
        }
    }

    private function penyaji(string $mode): PenyajiUrlBerkas
    {
        $config                        = new Arsip();
        $config->penyajianBerkasLegacy = $mode;

        return new PenyajiUrlBerkas(new LocalStorageAdapter($this->akar), $config);
    }
}
