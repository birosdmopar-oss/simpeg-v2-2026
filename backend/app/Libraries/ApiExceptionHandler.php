<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\ApiException;
use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Exception handler global untuk request API (ADR-002): seluruh exception yang lolos dari
 * controller/filter/service diubah ke envelope {status:'error', message, errors?} (ADR-001).
 * Didaftarkan lewat Config\Exceptions::handler(). Kategori exception baru cukup didaftarkan di sini.
 */
class ApiExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        [$status, $body] = self::toEnvelope($exception, $statusCode);

        $response->setStatusCode($status)->setJSON($body)->send();

        exit($exitCode);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function toEnvelope(Throwable $exception, int $fallbackStatus = 500): array
    {
        if ($exception instanceof ApiException) {
            $body = ['status' => 'error', 'message' => $exception->getMessage()];

            if ($exception->getErrors() !== null) {
                $body['errors'] = $exception->getErrors();
            }

            return [$exception->getStatusCode(), $body];
        }

        if ($exception instanceof PageNotFoundException) {
            return [404, ['status' => 'error', 'message' => 'Endpoint tidak ditemukan.']];
        }

        $status = $fallbackStatus >= 400 && $fallbackStatus < 600 ? $fallbackStatus : 500;

        // Server error: tidak silent (ter-log oleh CI4), pesan detail hanya di luar production.
        $message = ENVIRONMENT === 'production' || $status < 500
            ? 'Terjadi kesalahan pada server.'
            : $exception->getMessage();

        return [$status, ['status' => 'error', 'message' => $message]];
    }
}
