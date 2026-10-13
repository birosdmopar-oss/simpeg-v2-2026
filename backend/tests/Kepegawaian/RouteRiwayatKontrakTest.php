<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Modules;
use Config\Routing;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * S0-A (MAKE-002) — berkas route riwayat (milik WS-1): termuat lewat Routing::$routeFiles, filter `jwt` di level grup
 * (route yang ditambahkan WS-1 tidak pernah terbuka tanpa token), dan path kontrak tidak pernah 500. Tahan perubahan:
 * tetap berlaku setelah WS-1 mendaftarkan endpoint nyata (401 tanpa token).
 *
 * @internal
 */
final class RouteRiwayatKontrakTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const BERKAS = APPPATH . 'Config/RoutesRiwayat.php';

    private const NIP = '199001012015011001';

    public function testBerkasRouteTermuat(): void
    {
        $files = config(Routing::class)->routeFiles;

        $this->assertSame(APPPATH . 'Config/Routes.php', $files[0]);
        $this->assertContains(self::BERKAS, $files);
        $this->assertFileExists(self::BERKAS);

        // Route Routes.php tetap termuat bersama berkas route Fase 3.
        $this->assertArrayHasKey('api/v1/health', service('routes')->loadRoutes()->getRoutes('GET'));
    }

    public function testRouteBaruDiGrupOtomatisBerFilterJwt(): void
    {
        // Simulasi WS menambah route di grup berkas ini tanpa filter per route: filter jwt grup tetap berlaku.
        $sumber = (string) file_get_contents(self::BERKAS);
        $sisip  = "\$routes->get('pegawai/(:segment)/riwayat/uji-jwt', 'Uji::index');\n\$routes->post('pegawai/(:segment)/riwayat/uji-role', 'Uji::index', ['filter' => 'role:1']);\n";
        $this->assertSame(1, substr_count($sumber, '// WS-1:'), 'penanda sisip ada sekali');
        $uji = str_replace('// WS-1:', $sisip . '// WS-1:', $sumber);

        $berkas = tempnam(sys_get_temp_dir(), 'routes') ?: $this->fail('tempnam gagal');
        file_put_contents($berkas, $uji);

        try {
            $routes = new RouteCollection(Services::locator(), new Modules(), new Routing());
            (static function (RouteCollection $routes, string $berkas): void {
                require $berkas;
            })($routes, $berkas);
        } finally {
            unlink($berkas);
        }

        $this->assertContains('jwt', $routes->getFiltersForRoute('api/v1/pegawai/([^/]+)/riwayat/uji-jwt', 'GET'));
        // Filter per route digabung dengan jwt grup, bukan menggantinya.
        $filters = $routes->getFiltersForRoute('api/v1/pegawai/([^/]+)/riwayat/uji-role', 'POST');
        $this->assertContains('jwt', $filters);
        $this->assertContains('role:1', $filters);
    }

    public function testSemuaRouteRiwayatTerdaftarBerFilterJwt(): void
    {
        $routes = service('routes')->loadRoutes();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $from) {
                if (preg_match('#^api/v1/pegawai/[^/]+/riwayat#', (string) $from) === 1) {
                    $this->assertContains('jwt', $routes->getFiltersForRoute((string) $from, $verb), "{$verb} {$from}");
                }
            }
        }

        $this->assertMatchesRegularExpression("/'filter'\\s*=>\\s*'jwt'/", (string) file_get_contents(self::BERKAS));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pathKontrakProvider(): array
    {
        $nip = self::NIP;

        return [
            'daftar riwayat' => ['GET', "api/v1/pegawai/{$nip}/riwayat/pendidikan"],
            'detail riwayat' => ['GET', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1"],
            'tambah riwayat' => ['POST', "api/v1/pegawai/{$nip}/riwayat/pendidikan"],
            'ubah riwayat'   => ['PUT', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1"],
            'hapus riwayat'  => ['DELETE', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1"],
            'proses riwayat' => ['POST', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1/process"],
        ];
    }

    #[DataProvider('pathKontrakProvider')]
    public function testPathKontrakTanpaTokenTidakPernah500(string $method, string $path): void
    {
        try {
            $result = $this->call($method, $path);
            $status = $result->response()->getStatusCode();
            $body   = json_decode($result->getJSON() ?: 'null', true);
        } catch (PageNotFoundException $e) {
            // Di feature test exception router tidak melewati handler global; saat runtime ApiExceptionHandler
            // (Config\Exceptions::handler) mengubahnya ke envelope di bawah.
            [$status, $body] = ApiExceptionHandler::toEnvelope($e);
        }

        $this->assertContains($status, [401, 404, 501], "{$method} {$path} → {$status}");
        $this->assertIsArray($body);
        $this->assertSame('error', $body['status'] ?? null);
    }
}
