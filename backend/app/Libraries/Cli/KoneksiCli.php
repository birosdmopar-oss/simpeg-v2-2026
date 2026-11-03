<?php

declare(strict_types=1);

namespace App\Libraries\Cli;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Koneksi untuk command operasional (impor, lampiran, akun): grup bawaan dengan `DBDebug = true` apa pun environment-nya,
 * supaya galat query menjadi exception, bukan `false` yang terbaca sebagai "kosong"/"tidak ada" (R-1 review PR #32,
 * review MAKE-069 B-1).
 */
final class KoneksiCli
{
    private function __construct()
    {
    }

    /**
     * Command impor sumber tetap memakai koneksi terpisah (skema lain); untuk tujuan, koneksi bersama dipakai bila sudah
     * `DBDebug`, selain itu dibuat koneksi baru dengan `DBDebug = true`.
     *
     * @param string|null $skema null = DB grup bawaan apa adanya; selain itu skema lain di server yang sama, tanpa prefix
     */
    public static function buka(?string $skema = null): BaseConnection
    {
        if ($skema === null) {
            $bersama = Database::connect();

            // Koneksi bersama dipakai bila sudah DBDebug (mis. grup test) — satu sesi/transaksi dengan pemanggil.
            if ($bersama->DBDebug) {
                return $bersama;
            }
        }

        return self::baru($skema);
    }

    /**
     * Selalu koneksi baru dengan `DBDebug = true` (alat impor: sesi R4 tidak boleh mengubah koneksi bersama).
     */
    public static function baru(?string $skema = null): BaseConnection
    {
        $grup            = config(Database::class)->{config(Database::class)->defaultGroup};
        $grup['DBDebug'] = true;

        if ($skema !== null) {
            $grup['database'] = $skema;
            $grup['DBPrefix'] = '';
        }

        return Database::connect($grup, false);
    }
}
