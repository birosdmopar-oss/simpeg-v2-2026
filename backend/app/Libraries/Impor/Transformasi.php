<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Aturan transformasi per nilai (murni, tanpa DB). Rujukan: DBV-011 §2.2 #2–3, §2.4 langkah 5, §2.10, §3.2.
 */
final class Transformasi
{
    public const ZONA_WIB = 'WIB';
    public const ZONA_UTC = 'UTC';

    private function __construct()
    {
    }

    /**
     * `stripslashes` PHP (DBV-011 §2.2 #3: legacy menyimpan bentuk `addslashes`; hasil akhir per baris memakai PHP).
     */
    public static function stripslashes(?string $nilai): ?string
    {
        return $nilai === null ? null : stripslashes($nilai);
    }

    /**
     * Normalisasi nama = aplikasi v2 (`MasterService::normalizeName()`, DBV-011 §2.2 #2): whitespace beruntun → satu
     * spasi, lalu trim.
     */
    public static function nama(?string $nilai): ?string
    {
        return $nilai === null ? null : trim((string) preg_replace('/\s+/u', ' ', $nilai));
    }

    /**
     * Id rujukan `0` / `''` → NULL (DBV-011 §2.10, B-01/B-02 §6 #4).
     */
    public static function rujukan(mixed $nilai): mixed
    {
        if ($nilai === null) {
            return null;
        }

        $teks = trim((string) $nilai);

        return $teks === '' || $teks === '0' ? null : $nilai;
    }

    /**
     * Tanggal/stempel nol (`0000-00-00…`) atau kosong (DBV-011 R3).
     */
    public static function tanggalNol(?string $nilai): bool
    {
        return $nilai !== null && (trim($nilai) === '' || str_starts_with($nilai, '0000-00-00'));
    }

    /**
     * Stempel waktu → UTC (DBV-011 R1a/R1b/R2). `$tipeSumber` = DATA_TYPE kolom sumber:
     * - `datetime` = jam dinding server legacy: dikurangi 7 jam bila `$zonaSumber` = WIB, apa adanya bila UTC (R1a);
     * - `timestamp` = sudah UTC di penyimpanan dan dibaca di sesi `+00:00` → tidak dikonversi (R1b);
     * - tipe lain (`date`, `time`) tidak dikonversi (R2).
     *
     * Nilai nol harus sudah ditangani pemanggil (R3, {@see tanggalNol()}).
     */
    public static function waktuUtc(?string $nilai, string $tipeSumber, string $zonaSumber): ?string
    {
        if ($nilai === null || strtolower($tipeSumber) !== 'datetime' || $zonaSumber === self::ZONA_UTC) {
            return $nilai;
        }

        $waktu = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($nilai, 0, 19), new DateTimeZone('+07:00'));

        if ($waktu === false) {
            throw new ImporGagal('Format DATETIME tidak dikenal (R1a).');
        }

        return $waktu->setTimezone(new DateTimeZone('+00:00'))->format('Y-m-d H:i:s');
    }

    /**
     * Status legacy → teks pembanding (ENUM/angka, DBV-011 §2.4 #5 `CAST(status AS CHAR)`); NULL → `'null'`.
     */
    public static function kunciStatus(mixed $nilai): string
    {
        return $nilai === null ? 'null' : trim((string) $nilai);
    }
}
