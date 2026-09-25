<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Exceptions;

/**
 * CR-007 — pemilihan handler exception: request API (route kosong/berprefix api/, atau Accept JSON) → ApiExceptionHandler
 * (envelope ADR-001), selain itu handler bawaan CI4.
 *
 * Deteksi memakai route path, bukan getUri()->getPath() yang ikut memuat indexPage ('index.php/api/...'): dengan yang
 * terakhir, request API tanpa `Accept: application/json` (mis. segmen URI non-UTF-8 yang ditolak Router) jatuh ke handler
 * bawaan dan berujung fatal error HTML.
 *
 * @internal
 */
final class ExceptionsTest extends CIUnitTestCase
{
    public function testApiRoutesAreDetectedWithoutAcceptHeaderDespiteIndexPage(): void
    {
        $config = config(App::class);
        $this->assertSame('index.php', $config->indexPage, 'prasyarat: indexPage bawaan terisi');

        foreach (['api/v1/master/agama/01', 'api/v1/auth/login', '/api/v1/faq', ''] as $path) {
            $request = $this->request($config, $path);

            if ($path !== '') {
                // Jebakan yang ditutup: path URI lengkap ikut memuat indexPage.
                $this->assertStringStartsWith('index.php/api/', trim($request->getUri()->getPath(), '/'), $path);
            }

            $this->assertTrue(Exceptions::isApiRequest($request), "'{$path}' tanpa Accept");
        }
    }

    public function testApiRoutesAreDetectedWithEmptyIndexPage(): void
    {
        $config            = clone config(App::class);
        $config->indexPage = '';

        $this->assertTrue(Exceptions::isApiRequest($this->request($config, 'api/v1/master/agama')));
        $this->assertFalse(Exceptions::isApiRequest($this->request($config, 'halaman/lain')));
    }

    public function testNonApiRoutesNeedJsonAcceptHeader(): void
    {
        $config = config(App::class);

        // Prefix harus segmen 'api/' utuh, bukan sekadar diawali 'api'.
        foreach (['halaman/lain', 'apidoc/v1'] as $path) {
            $this->assertFalse(Exceptions::isApiRequest($this->request($config, $path)), $path);
            $this->assertTrue(Exceptions::isApiRequest($this->request($config, $path, 'application/json, text/plain, */*')), $path);
        }
    }

    private function request(App $config, string $path, ?string $accept = null): IncomingRequest
    {
        $request = new IncomingRequest($config, new SiteURI($config, $path), null, new UserAgent());

        // Header diambil dari $_SERVER saat konstruksi; tetapkan eksplisit agar tidak dipengaruhi test lain.
        $request->removeHeader('Accept');

        if ($accept !== null) {
            $request->setHeader('Accept', $accept);
        }

        return $request;
    }
}
