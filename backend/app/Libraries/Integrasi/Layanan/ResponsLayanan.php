<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Layanan;

use CodeIgniter\HTTP\ResponseInterface;
use stdClass;

/**
 * Fase 6 Jalur A — amplop respons bisnis endpoint kompatibilitas `services/*` (`s_stat` A6 MAKE-061, `s_dm` A4
 * MAKE-059, `s_esign` B4b), PRD Fase 6 §6.3 [K]: `{"status":1,"msg":"","data":…}` sukses, `{"status":2,"msg":"…","data":…}`
 * gagal bisnis — `status` INT (bukan string "01"/"02" SIMAPI), HTTP 200 untuk respons bisnis. Kegagalan auth (401)
 * dan throttle (429) ditangani filter A3 ({@see \App\Libraries\Integrasi\Klien\ResponsAuthKlien}), bukan di sini.
 *
 * Berkas bersama Jalur A (keputusan lead 05-11-2026): siapa merge duluan membuat, yang lain memakai tanpa membuat kembar.
 */
final class ResponsLayanan
{
    public const SUKSES = 1;

    public const GAGAL = 2;

    private function __construct()
    {
    }

    /**
     * @param array<mixed>|stdClass $data
     */
    public static function sukses(array|stdClass $data, string $msg = ''): ResponseInterface
    {
        return self::amplop(self::SUKSES, $msg, $data, 200);
    }

    /**
     * Gagal bisnis (HTTP 200 [K], mis. parameter tidak valid: legacy `{"status":2,"msg":"Invalid parameter."}`).
     *
     * @param array<mixed>|stdClass|null $data
     */
    public static function gagal(string $msg, array|stdClass|null $data = null): ResponseInterface
    {
        return self::amplop(self::GAGAL, $msg, $data ?? new stdClass(), 200);
    }

    /**
     * Sumber daya tidak ada / tidak boleh diakses — 404 SERAGAM dengan amplop INT (endpoint unduh berkas Q6-38).
     */
    public static function tidakDitemukan(string $msg = 'Berkas tidak ditemukan.'): ResponseInterface
    {
        return self::amplop(self::GAGAL, $msg, new stdClass(), 404);
    }

    /**
     * @param array<mixed>|stdClass $data
     */
    private static function amplop(int $status, string $msg, array|stdClass $data, int $http): ResponseInterface
    {
        return service('response')
            ->setStatusCode($http)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $status, 'msg' => $msg, 'data' => $data]);
    }
}
