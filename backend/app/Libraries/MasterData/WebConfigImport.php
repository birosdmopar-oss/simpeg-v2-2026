<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Libraries\Html\HtmlSanitizer;
use InvalidArgumentException;

/**
 * G-09 — aturan impor satu baris `simpeg01.web_config` legacy ke v2 (DBV-006, G-09 Bagian 5). Kelas murni, dipakai
 * runbook impor Tier 1 (J1.08) dan diuji unit. Tidak menulis DB.
 *
 * Urutan aturan:
 *   1. Semua kolom teks (`config_name`, `config_value`, `remark`) di-`stripslashes` — legacy menyimpan bentuk
 *      `addslashes` (`Lm_web.php:204-210`).
 *   2. Key yang tidak ada di katalog → REVIEW (keputusan TL: impor apa adanya sebagai key tak dikenal, atau buang).
 *   3. Nilai kosong → SKIP (legacy memperlakukan '' sama dengan tidak ada; v2 memakai nilai bawaan katalog).
 *   4. `asset_path` (logo PDF) → SKIP: legacy berisi path absolut server web lama; v2 memakai aset bawaan sampai admin
 *      mengunggah/menunjuk berkas di penyimpanan aset v2.
 *   5. Key yang ditandai REVIEW_KEYS (URL logo yang menunjuk server lama, data email cron mati) → REVIEW setelah lolos
 *      validasi tipe, supaya diperiksa manusia sebelum dipakai.
 *   6. Nilai dinormalisasi lewat WebConfigTypes (HTML disanitasi ulang); gagal validasi → REVIEW dengan pesannya.
 */
final class WebConfigImport
{
    public const IMPORT = 'import';
    public const SKIP   = 'skip';
    public const REVIEW = 'review';

    /**
     * Key yang tetap diperiksa manusia walau nilainya sah (G-09 Bagian 5 #5).
     */
    public const REVIEW_KEYS = ['logo_kementerian_url', 'logo_wonderful_url', 'email_sent_time', 'email_ultah_ad'];

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, mixed>> $catalog Config\WebConfig::$keys
     *
     * @return array{action: string, config_name: string, config_value: string|null, remark: string|null, reason: string}
     */
    public static function row(array $catalog, string $name, ?string $value, ?string $remark, ?HtmlSanitizer $sanitizer = null): array
    {
        $name   = stripslashes($name);
        $value  = $value === null ? null : stripslashes($value);
        $remark = $remark === null ? null : self::remark(stripslashes($remark));
        $result = static fn (string $action, ?string $v, string $reason): array => [
            'action' => $action, 'config_name' => $name, 'config_value' => $v, 'remark' => $remark, 'reason' => $reason,
        ];

        $def = $catalog[$name] ?? null;

        if ($def === null) {
            return $result(self::REVIEW, $value, 'Key tidak ada di katalog v2 — putuskan bersama TL: impor sebagai key tak dikenal atau buang.');
        }

        if ($value === null || trim($value) === '') {
            return $result(self::SKIP, null, 'Nilai kosong — v2 memakai nilai bawaan katalog.');
        }

        if ($def['type'] === WebConfigTypes::ASSET_PATH) {
            return $result(self::SKIP, null, 'Path berkas absolut server legacy tidak diimpor — pakai aset bawaan v2 lalu atur ulang lewat admin.');
        }

        try {
            $normalized = WebConfigTypes::normalize($def, $value, $sanitizer);
        } catch (InvalidArgumentException $e) {
            return $result(self::REVIEW, $value, 'Nilai tidak sesuai tipe ' . $def['type'] . ': ' . $e->getMessage());
        }

        if (in_array($name, self::REVIEW_KEYS, true)) {
            return $result(self::REVIEW, $normalized, 'Nilai sah, tetapi wajib diperiksa manusia (URL server lama / data cron yang dimatikan).');
        }

        return $result(self::IMPORT, $normalized, $normalized === $value ? 'Nilai sah.' : 'Nilai dinormalisasi.');
    }

    /**
     * `remark` VARCHAR(255): spasi berlebih dirapikan, kosong → null.
     */
    private static function remark(string $remark): ?string
    {
        $remark = trim((string) preg_replace('/\s+/u', ' ', $remark));

        return $remark === '' ? null : mb_substr($remark, 0, 255);
    }
}
