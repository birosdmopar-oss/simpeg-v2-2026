<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kode galat mesin opsional di envelope (Fase 5A MAKE-030, aditif): tanpa kode envelope tidak berubah; dengan
 * `denganKode()` envelope memuat `code` di samping `message`/`errors`.
 *
 * @internal
 */
final class ApiExceptionKodeTest extends CIUnitTestCase
{
    public function testTanpaKodeEnvelopeTetap(): void
    {
        $this->assertSame([404, ['status' => 'error', 'message' => 'Tidak ada.']], ApiExceptionHandler::toEnvelope(new NotFoundException('Tidak ada.')));
        $this->assertSame(
            [422, ['status' => 'error', 'message' => 'X', 'errors' => ['a' => ['X']]]],
            ApiExceptionHandler::toEnvelope(ValidationException::forField('a', 'X')),
        );
    }

    public function testDenganKode(): void
    {
        $e = (new NotFoundException('Pratinjau tidak ditemukan.'))->denganKode('pratinjau_tidak_ditemukan');
        $this->assertInstanceOf(NotFoundException::class, $e);
        $this->assertSame('pratinjau_tidak_ditemukan', $e->getKode());
        $this->assertSame(
            [404, ['status' => 'error', 'code' => 'pratinjau_tidak_ditemukan', 'message' => 'Pratinjau tidak ditemukan.']],
            ApiExceptionHandler::toEnvelope($e),
        );

        $konflik = (new ApiException('Bentrok.', 409, ['nip_periode' => ['1 2026-09']]))->denganKode('konflik_data');
        $this->assertSame(
            [409, ['status' => 'error', 'code' => 'konflik_data', 'message' => 'Bentrok.', 'errors' => ['nip_periode' => ['1 2026-09']]]],
            ApiExceptionHandler::toEnvelope($konflik),
        );
    }
}
