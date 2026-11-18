<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Klien\ResponsAuthKlien;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-151: responder integrasi memasang `Cache-Control` persis `no-store`, tanpa gabungan bawaan `noCache()` CI4. Tiap kasus
 * memakai `service('response')` baru supaya header bawaan benar-benar ada sebelum responder berjalan.
 *
 * @internal
 */
final class ResponsCacheControlTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{callable(): ResponseInterface}>
     */
    public static function responder(): iterable
    {
        yield 'auth klien gagal (layanan)' => [static fn (): ResponseInterface => ResponsAuthKlien::gagal(false)];
        yield 'auth klien gagal (simapi)' => [static fn (): ResponseInterface => ResponsAuthKlien::gagal(true)];
        yield 'auth klien throttle (layanan)' => [static fn (): ResponseInterface => ResponsAuthKlien::terlaluBanyak(false, 30)];
        yield 'auth klien throttle (simapi)' => [static fn (): ResponseInterface => ResponsAuthKlien::terlaluBanyak(true, 30)];
        yield 'simapi sukses' => [static fn (): ResponseInterface => ResponsSimapi::sukses(['a' => 1])];
        yield 'simapi gagal' => [static fn (): ResponseInterface => ResponsSimapi::gagal('x')];
        yield 'simapi kosong' => [static fn (): ResponseInterface => ResponsSimapi::kosong()];
    }

    /**
     * @param callable(): ResponseInterface $buat
     */
    #[DataProvider('responder')]
    public function testCacheControlPersisNoStore(callable $buat): void
    {
        Services::resetSingle('response');
        $this->assertNotSame('', service('response')->getHeaderLine('Cache-Control'), 'header bawaan noCache ada sebelum responder');

        $this->assertSame('no-store', $buat()->getHeaderLine('Cache-Control'));
    }
}
