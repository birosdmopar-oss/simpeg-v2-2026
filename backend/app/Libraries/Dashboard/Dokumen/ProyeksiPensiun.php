<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use App\Exceptions\ValidationException;

/**
 * Rentang proyeksi pensiun F-09 (PRD Fase 7 F7-20) — kalkulasi murni. Legacy hanya tahun berjalan
 * (`L_document.php:223-224`); v2 [V2] `tahun` (bawaan tahun berjalan) + `sampai` opsional (≥ `tahun`, maks
 * `tahun + 4`). BUP/TMT per pegawai = `KolomDokumen::pensiun()` (PNS aktif berjabatan).
 */
final class ProyeksiPensiun
{
    public const TAHUN_MIN = 2000;

    public const TAHUN_MAX = 2100;

    public const RENTANG_MAKS = 4;

    private function __construct()
    {
    }

    /**
     * @return array{tahun: int, sampai: int}
     *
     * @throws ValidationException 422 bila bentuk/rentang tidak valid
     */
    public static function rentang(string $tahunRaw, string $sampaiRaw, string $hariIni): array
    {
        $tahun  = $tahunRaw === '' ? (int) substr($hariIni, 0, 4) : self::tahun($tahunRaw, 'tahun');
        $sampai = $sampaiRaw === '' ? $tahun : self::tahun($sampaiRaw, 'sampai');

        if ($sampai < $tahun) {
            throw ValidationException::forField('sampai', 'Tahun akhir harus sama atau sesudah tahun awal.');
        }

        if ($sampai > $tahun + self::RENTANG_MAKS) {
            throw ValidationException::forField('sampai', 'Rentang proyeksi maksimal ' . (self::RENTANG_MAKS + 1) . ' tahun.');
        }

        return ['tahun' => $tahun, 'sampai' => $sampai];
    }

    /**
     * Baris pegawai masuk proyeksi bila TMT pensiun jatuh pada rentang tahun.
     *
     * @param array<string, mixed> $r baris kueri
     */
    public static function masuk(array $r, int $tahun, int $sampai): bool
    {
        $p = KolomDokumen::pensiun($r);

        if ($p === null) {
            return false;
        }

        $y = (int) substr($p['tmt'], 0, 4);

        return $y >= $tahun && $y <= $sampai;
    }

    /**
     * Urut TMT pensiun ASC, satker, NIP (legacy `tgl_pensiun DESC` dibalik supaya yang terdekat di atas [V2]).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function bandingkan(array $a, array $b): int
    {
        $pa = KolomDokumen::pensiun($a)['tmt'] ?? '';
        $pb = KolomDokumen::pensiun($b)['tmt'] ?? '';

        return [$pa, (int) ($a['id_satker'] ?? 0), (string) ($a['nip'] ?? '')] <=> [$pb, (int) ($b['id_satker'] ?? 0), (string) ($b['nip'] ?? '')];
    }

    private static function tahun(string $raw, string $field): int
    {
        if (preg_match('/^[0-9]{4}$/', $raw) !== 1 || (int) $raw < self::TAHUN_MIN || (int) $raw > self::TAHUN_MAX) {
            throw ValidationException::forField($field, 'Tahun harus 4 digit angka antara ' . self::TAHUN_MIN . ' dan ' . self::TAHUN_MAX . '.');
        }

        return (int) $raw;
    }
}
