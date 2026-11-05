<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Layanan;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-059/MAKE-061 — amplop respons bisnis `services/*` legacy (PRD Fase 6 §6.3): HTTP 200, `{"status":1|2,"msg":"…","data":…}`
 * dengan `status` INT [K `S_dm.php`/`S_stat.php`: `'status' => 1, // 1: Success, 2: Failed`]. Sukses `status` 1 `msg` "";
 * gagal bisnis `status` 2. `data` bawaan legacy = array kosong → JSON `[]` [K]. Kegagalan auth ditangani filter
 * `klienLayanan` ({@see \App\Libraries\Integrasi\Klien\ResponsAuthKlien}, HTTP 401 [V2]); controller tidak memeriksa auth lagi.
 * Dipakai bersama `s_dm` (A4) dan `s_stat` (A6) — jangan membuat kembaran.
 */
final class ResponsLayanan
{
    public const SUKSES = 1;
    public const GAGAL  = 2;

    private function __construct()
    {
    }

    public static function sukses(mixed $data, string $pesan = ''): ResponseInterface
    {
        return self::kirim(self::SUKSES, $pesan, $data);
    }

    public static function gagal(string $pesan): ResponseInterface
    {
        return self::kirim(self::GAGAL, $pesan, []);
    }

    private static function kirim(int $status, string $pesan, mixed $data): ResponseInterface
    {
        return service('response')
            ->setStatusCode(200)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $status, 'msg' => $pesan, 'data' => $data]);
    }
}
