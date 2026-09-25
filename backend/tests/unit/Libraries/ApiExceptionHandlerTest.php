<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

/**
 * CR-007 — envelope handler global untuk exception di luar ApiException: 4xx dari framework (mis. segmen URI yang
 * ditolak Router CI4) → pesan klien generik tanpa memantulkan input; 5xx → pesan server.
 *
 * @internal
 */
final class ApiExceptionHandlerTest extends CIUnitTestCase
{
    public function testRouterBadRequestBecomesClientErrorWithoutEchoingTheSegment(): void
    {
        // Pesan persis Router::checkDisallowedChars(), memuat byte mentah segmen.
        $exception = new BadRequestException("The URI you submitted has disallowed characters: \"abc\xC3(\"");

        // Status yang diteruskan Exceptions::exceptionHandler() = kode exception HTTP (400).
        [$status, $body] = ApiExceptionHandler::toEnvelope($exception, $exception->getCode());

        $this->assertSame(400, $status);
        $this->assertSame(['status' => 'error', 'message' => ApiExceptionHandler::CLIENT_ERROR_MESSAGE], $body);

        // Sama dengan handle(): setJSON() tidak boleh gagal encode.
        $json = (string) Services::response(null, false)->setStatusCode($status)->setJSON($body)->getBody();
        $this->assertSame($body, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testOtherFrameworkClientErrorsGetClientMessage(): void
    {
        foreach ([400, 403, 405, 413, 499] as $code) {
            [$status, $body] = ApiExceptionHandler::toEnvelope(new RuntimeException('detail internal'), $code);

            $this->assertSame($code, $status);
            $this->assertSame(['status' => 'error', 'message' => ApiExceptionHandler::CLIENT_ERROR_MESSAGE], $body, (string) $code);
        }

        // 404 tetap pesan endpoint.
        $this->assertSame(
            [404, ['status' => 'error', 'message' => 'Endpoint tidak ditemukan.']],
            ApiExceptionHandler::toEnvelope(PageNotFoundException::forPageNotFound(), 404),
        );
    }

    public function testServerErrorsKeepServerMessage(): void
    {
        // Di luar production (test = 'testing') pesan detail tetap dikirim untuk debugging; status di luar 4xx/5xx → 500.
        $this->assertNotSame('production', ENVIRONMENT);

        foreach ([500 => 500, 503 => 503, 200 => 500, 0 => 500] as $fallback => $expected) {
            [$status, $body] = ApiExceptionHandler::toEnvelope(new RuntimeException('detail internal'), $fallback);

            $this->assertSame($expected, $status, (string) $fallback);
            $this->assertSame(['status' => 'error', 'message' => 'detail internal'], $body, (string) $fallback);
        }
    }
}
