<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\ApiException;
use CodeIgniter\Database\Exceptions\DatabaseException;
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
    /**
     * Kode error "data" MySQL/MariaDB (CR-007): di koneksi strict (strictOn=true) nilai yang tidak muat/tidak sesuai
     * kolom ditolak alih-alih dipotong diam-diam. Itu kesalahan input (422), bukan kerusakan server (500).
     *   1406 terlalu panjang · 1264 di luar rentang · 1366 nilai/encoding tidak valid · 1292 tanggal/angka tidak valid
     *   1265 data terpotong (ENUM, angka berekor teks) · 1364 kolom NOT NULL tanpa default tidak diisi
     * Error DB lain (1062 yang tidak diterjemahkan service, lock wait, deadlock, koneksi) tetap 500.
     */
    public const DATA_ERROR_CODES = [1406, 1264, 1366, 1292, 1265, 1364];

    /**
     * Pesan generik: pesan MySQL (nama kolom, nilai) tidak pernah dikirim ke klien, hanya ke log.
     */
    public const DATA_ERROR_MESSAGE = 'Data tidak dapat diproses karena ada isian yang tidak valid.';

    /**
     * Pesan exception 4xx di luar ApiException/PageNotFoundException — mis. segmen URI yang ditolak Router CI4
     * (CodeIgniter\HTTP\Exceptions\BadRequestException 400: byte non-UTF-8 atau karakter di luar permittedURIChars).
     * Pesan asli (memuat segmen mentah) hanya di log.
     */
    public const CLIENT_ERROR_MESSAGE = 'Permintaan tidak valid.';

    public const SERVER_ERROR_MESSAGE = 'Terjadi kesalahan pada server.';

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

        // Detail sudah dicatat: oleh handler global CI4 (Exceptions::exceptionHandler) atau ApiController::_remap().
        if (self::isDataError($exception)) {
            return [422, ['status' => 'error', 'message' => self::DATA_ERROR_MESSAGE]];
        }

        $status = $fallbackStatus >= 400 && $fallbackStatus < 600 ? $fallbackStatus : 500;

        // Error klien dari framework (mis. Router 400): pesan generik, segmen/nilai mentah tidak dipantulkan.
        if ($status < 500) {
            return [$status, ['status' => 'error', 'message' => self::CLIENT_ERROR_MESSAGE]];
        }

        // Server error: tidak silent (ter-log oleh CI4), pesan detail hanya di luar production.
        $message = ENVIRONMENT === 'production' ? self::SERVER_ERROR_MESSAGE : $exception->getMessage();

        return [$status, ['status' => 'error', 'message' => $message]];
    }

    /**
     * Error data DB (DATA_ERROR_CODES) — dari query builder (kode errno MySQL) maupun DatabaseException yang dibangun
     * model dari $db->error() (MasterModel, TokenModel, FaqRateModel).
     */
    public static function isDataError(Throwable $exception): bool
    {
        return $exception instanceof DatabaseException
            && in_array($exception->getCode(), self::DATA_ERROR_CODES, true);
    }
}
