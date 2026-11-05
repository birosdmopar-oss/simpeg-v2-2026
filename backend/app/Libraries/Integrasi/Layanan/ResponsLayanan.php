<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Layanan;

use CodeIgniter\HTTP\ResponseInterface;
use stdClass;

/**
 * Fase 6 Jalur A — amplop respons bisnis endpoint kompatibilitas `services/*` (PRD Fase 6 §6.3 [K]):
 * `{"status":1,"msg":"","data":…}` sukses, `{"status":2,"msg":"…","data":…}` gagal bisnis, HTTP 200. Kegagalan auth (401)
 * dan throttle (429) ditangani filter A3 ({@see \App\Libraries\Integrasi\Klien\ResponsAuthKlien}), bukan di sini.
 *
 * Tipe `status` per sub-group [K] (diverifikasi reviewer 05-11-2026): `s_stat` (`S_stat.php:24`) dan `s_dm` (`S_dm.php:24`)
 * memakai bilangan bulat `1`/`2`; `s_esign` (`S_esign.php:12,57,122`) memakai STRING `"1"`/`"2"`. Pemanggil memilih lewat
 * parameter `$statusString` (bawaan INT); bentuk lain amplop sama. Bukan amplop SIMAPI `"01"`/`"02"`.
 *
 * Berkas bersama Jalur A (keputusan lead 05-11-2026): A6 `s_stat` dan A4 `s_dm` memakai mode INT, B4b `s_esign` mode string.
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
    public static function sukses(array|stdClass $data, string $msg = '', bool $statusString = false): ResponseInterface
    {
        return self::amplop(self::SUKSES, $msg, $data, 200, $statusString);
    }

    /**
     * Gagal bisnis (HTTP 200 [K], mis. parameter tidak valid: legacy `{"status":2,"msg":"Invalid parameter."}`).
     *
     * @param array<mixed>|stdClass|null $data
     */
    public static function gagal(string $msg, array|stdClass|null $data = null, bool $statusString = false): ResponseInterface
    {
        return self::amplop(self::GAGAL, $msg, $data ?? new stdClass(), 200, $statusString);
    }

    /**
     * Sumber daya tidak ada / tidak boleh diakses — 404 SERAGAM dengan amplop yang sama (endpoint unduh berkas Q6-38).
     */
    public static function tidakDitemukan(string $msg = 'Berkas tidak ditemukan.', bool $statusString = false): ResponseInterface
    {
        return self::amplop(self::GAGAL, $msg, new stdClass(), 404, $statusString);
    }

    /**
     * @param array<mixed>|stdClass $data
     */
    private static function amplop(int $status, string $msg, array|stdClass $data, int $http, bool $statusString): ResponseInterface
    {
        return service('response')
            ->setStatusCode($http)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $statusString ? (string) $status : $status, 'msg' => $msg, 'data' => $data]);
    }
}
