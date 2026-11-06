<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-059 — amplop respons SIMAPI legacy (PRD Fase 6 §6.3): selalu HTTP 200, `{"status":"01"|"02","msg":"…","data":…}`
 * dengan `status` STRING [K `A_employee.php`/`A_dm.php`/`A_auth.php`: `$result = ['status' => "01", 'msg' => '', 'data' => []]`].
 * `data` bawaan legacy = array kosong → JSON `[]` (juga pada gagal) [K]. Kegagalan auth (filter) memakai
 * {@see \App\Libraries\Integrasi\Klien\ResponsAuthKlien}.
 */
final class ResponsSimapi
{
    public const SUKSES = '01';
    public const GAGAL  = '02';

    public const PESAN_SUKSES    = 'Success';
    public const PESAN_PARAMETER = 'Invalid paramater'; // [K] ejaan legacy dipertahankan (`AE:157`)
    public const PESAN_NIP       = 'NIP not valid';
    public const PESAN_SERVER    = 'Server error, please try again.'; // [K] `A_auth.php:95`

    private function __construct()
    {
    }

    public static function sukses(mixed $data, string $pesan = self::PESAN_SUKSES): ResponseInterface
    {
        return self::kirim(self::SUKSES, $pesan, $data);
    }

    /**
     * Gagal bisnis: `status` "02", `data` `[]` [K].
     */
    public static function gagal(string $pesan): ResponseInterface
    {
        return self::kirim(self::GAGAL, $pesan, []);
    }

    /**
     * Klien bukan type 2 pada endpoint khusus type 2: `{"status":"01","msg":"","data":[]}` [K `AE:48-50`, `A_dm.php:48-50`].
     */
    public static function kosong(): ResponseInterface
    {
        return self::kirim(self::SUKSES, '', []);
    }

    private static function kirim(string $status, string $pesan, mixed $data): ResponseInterface
    {
        return service('response')
            ->setStatusCode(200)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $status, 'msg' => $pesan, 'data' => $data]);
    }
}
