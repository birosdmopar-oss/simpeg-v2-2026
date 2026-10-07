<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Routing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * S0-A (MAKE-002) — route Fase 3 dipisah ke RoutesRiwayat.php (WS-1) dan RoutesPegawai.php (WS-2), keduanya termuat;
 * path kontrak yang belum didaftarkan menjawab 404 terkendali (envelope error), bukan 500.
 *
 * @internal
 */
final class RouteKontrakTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const NIP = '199001012015011001';

    public function testBerkasRouteTerdaftarDanTermuat(): void
    {
        $files = config(Routing::class)->routeFiles;

        $this->assertSame(APPPATH . 'Config/Routes.php', $files[0]);
        $this->assertContains(APPPATH . 'Config/RoutesRiwayat.php', $files);
        $this->assertContains(APPPATH . 'Config/RoutesPegawai.php', $files);

        foreach ($files as $file) {
            $this->assertFileExists($file);
        }

        $routes = service('routes')->loadRoutes();
        // Route Routes.php tetap termuat bersama berkas route Fase 3.
        $this->assertArrayHasKey('api/v1/health', $routes->getRoutes('GET'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pathKontrakProvider(): array
    {
        $nip = self::NIP;

        return [
            'daftar pegawai'  => ['GET', 'api/v1/pegawai'],
            'detail pegawai'  => ['GET', "api/v1/pegawai/{$nip}"],
            'daftar riwayat'  => ['GET', "api/v1/pegawai/{$nip}/riwayat/pendidikan"],
            'detail riwayat'  => ['GET', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1"],
            'tambah riwayat'  => ['POST', "api/v1/pegawai/{$nip}/riwayat/pendidikan"],
            'ubah riwayat'    => ['PUT', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1"],
            'hapus riwayat'   => ['DELETE', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1"],
            'proses riwayat'  => ['POST', "api/v1/pegawai/{$nip}/riwayat/pendidikan/1/process"],
            'daftar lampiran' => ['GET', "api/v1/pegawai/{$nip}/lampiran"],
            'unduh lampiran'  => ['GET', "api/v1/pegawai/{$nip}/lampiran/1/unduh"],
            'hapus lampiran'  => ['DELETE', "api/v1/pegawai/{$nip}/lampiran/1"],
        ];
    }

    #[DataProvider('pathKontrakProvider')]
    public function testPathKontrakBelumTerdaftarMenjawab404Terkendali(string $method, string $path): void
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

        $this->assertContains($status, [404, 501], "{$method} {$path} → {$status}");
        $this->assertIsArray($body);
        $this->assertSame('error', $body['status'] ?? null);
    }
}
