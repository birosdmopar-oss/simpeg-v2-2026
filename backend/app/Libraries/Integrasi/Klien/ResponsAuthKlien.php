<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-058 — respons kegagalan auth antar-sistem per grup endpoint (PRD Fase 6 §6.3, BP-12):
 *  - SIMAPI: HTTP 200, `{"status":"02","msg":"Authentication failed"|"Token is not valid","data":[]}` [K];
 *  - `services/*`: HTTP 401 [V2], `{"status":2,"msg":"Authentication failed","data":{}}` + `WWW-Authenticate: Basic`.
 * Pesan seragam untuk semua alasan gagal (username tak dikenal, rahasia salah, nonaktif, IP, hak); alasan hanya ke log.
 */
final class ResponsAuthKlien
{
    public const PESAN_GAGAL    = 'Authentication failed';
    public const PESAN_TOKEN    = 'Token is not valid';
    public const PESAN_THROTTLE = 'Too many requests';

    private function __construct()
    {
    }

    public static function gagal(bool $simapi, string $pesan = self::PESAN_GAGAL): ResponseInterface
    {
        $respons = service('response')
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $simapi ? '02' : 2, 'msg' => $pesan, 'data' => self::dataKosong($simapi)]);

        if ($simapi) {
            return $respons->setStatusCode(200);
        }

        return $respons->setStatusCode(401)->setHeader('WWW-Authenticate', 'Basic realm="SIMPEG"');
    }

    public static function terlaluBanyak(bool $simapi, int $tungguDetik): ResponseInterface
    {
        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) max(1, $tungguDetik))
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $simapi ? '02' : 2, 'msg' => self::PESAN_THROTTLE, 'data' => self::dataKosong($simapi)]);
    }

    /**
     * SIMAPI: `[]` seperti legacy (`$result['data'] = []`, A_auth.php:21-42 / A_employee.php:22-45; MAKE-059 review P1);
     * `services/*`: `{}` (tidak diubah).
     *
     * @return array{}|\stdClass
     */
    private static function dataKosong(bool $simapi): array|\stdClass
    {
        return $simapi ? [] : new \stdClass();
    }
}
