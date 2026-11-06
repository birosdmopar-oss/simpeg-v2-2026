<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

/**
 * MAKE-059 — bentuk nilai keluaran kompatibilitas legacy (SIMAPI & `services/*`). Legacy mengirim hasil `row_array()` MySQLi
 * apa adanya: angka/tanggal sebagai STRING, kolom NULL sebagai `null` [K]. Field yang dilewatkan `stripslashes()` di legacy
 * menjadi `""` bila NULL (PHP 7 mengubah null → string kosong) [K], jadi dibedakan:
 *  - {@see mentah()}: field tanpa `stripslashes` → string atau null;
 *  - {@see teks()}: field dengan `stripslashes` → selalu string (null → "").
 * `stripslashes` sendiri tidak diulang: impor UP-1 sudah menormalkan teks (Impor README #5) [V2].
 */
final class Nilai
{
    private function __construct()
    {
    }

    public static function mentah(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    public static function teks(mixed $v): string
    {
        return $v === null ? '' : (string) $v;
    }

    /**
     * Seluruh nilai baris → {@see mentah()} (baris `SELECT *` legacy yang dikirim utuh).
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, string|null>
     */
    public static function mentahSemua(array $baris): array
    {
        return array_map(self::mentah(...), $baris);
    }
}
