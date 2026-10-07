<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Libraries\Html\HtmlSanitizer;
use InvalidArgumentException;
use LogicException;

/**
 * G-09 — tipe data nilai `web_config` (DBV-006/CR-030). Kelas murni: normalisasi + validasi nilai masukan menjadi
 * string kanonik yang disimpan di `config_value`, dan konversi string tersimpan menjadi nilai bertipe untuk konsumen.
 * Definisi key berasal dari katalog Config\WebConfig.
 *
 * Tipe:
 *   text       satu baris, tanpa baris baru, ≤ maxLength karakter (bawaan 255)
 *   textarea   beberapa baris (CRLF → LF), ≤ 65.535 byte (TEXT)
 *   html       HTML disanitasi HtmlSanitizer (whitelist DBV-002), wajib punya isi terlihat, ≤ 65.535 byte
 *   integer    bilangan bulat kanonik (tanpa nol di depan), min/max
 *   decimal    angka desimal dengan titik, ≤ scale digit desimal, min/max; disimpan tanpa nol berlebih ("1.50" → "1.5")
 *   id_ref     ID baris legacy (1..2147483647, INT signed), tabel rujukan di 'ref'
 *   url        URL absolut http/https, ≤ 2.048 karakter
 *   asset_path path relatif berkas gambar (png/jpg/jpeg) di penyimpanan aset v2, tanpa `..`/path absolut, ≤ 255
 *   time       HH:MM atau HH:MM:SS (00:00..23:59:59) → disimpan HH:MM:SS
 *   email_list daftar email dipisah koma, 1..20 alamat unik → disimpan "a@x,b@y"
 *
 * Nilai kosong selalu ditolak: mengosongkan = menghapus baris (kembali ke nilai bawaan katalog).
 */
final class WebConfigTypes
{
    public const TEXT       = 'text';
    public const TEXTAREA   = 'textarea';
    public const HTML       = 'html';
    public const INTEGER    = 'integer';
    public const DECIMAL    = 'decimal';
    public const ID_REF     = 'id_ref';
    public const URL        = 'url';
    public const ASSET_PATH = 'asset_path';
    public const TIME       = 'time';
    public const EMAIL_LIST = 'email_list';

    public const TYPES = [
        self::TEXT, self::TEXTAREA, self::HTML, self::INTEGER, self::DECIMAL, self::ID_REF, self::URL, self::ASSET_PATH,
        self::TIME, self::EMAIL_LIST,
    ];

    public const TEXT_MAX_BYTES  = 65535;
    public const URL_MAX_LENGTH  = 2048;
    public const PATH_MAX_LENGTH = 255;
    public const EMAIL_MAX_COUNT = 20;
    public const ID_MAX          = 2147483647;

    /**
     * Rumusan `config_name` yang sah (key legacy memakai huruf, angka, `_`, dan `/`, mis. `TL1/PSW1`).
     */
    public const NAME_PATTERN = '#^[A-Za-z0-9_]+(/[A-Za-z0-9_]+)*\z#';

    private function __construct()
    {
    }

    /**
     * Normalisasi + validasi nilai masukan → string kanonik untuk disimpan.
     *
     * @param array<string, mixed> $def entri katalog
     *
     * @throws InvalidArgumentException nilai tidak sesuai tipe (pesan siap tampil)
     */
    public static function normalize(array $def, mixed $input, ?HtmlSanitizer $sanitizer = null): string
    {
        $type = (string) $def['type'];

        if (is_bool($input) || is_array($input) || is_object($input) || $input === null) {
            throw new InvalidArgumentException(self::emptyOrShapeMessage($input));
        }

        if (is_float($input) && ! is_finite($input)) {
            throw new InvalidArgumentException('Nilai harus angka yang valid.');
        }

        $value = is_float($input) ? self::floatToString($input) : (string) $input;

        if (trim($value) === '') {
            throw new InvalidArgumentException('Nilai wajib diisi. Untuk kembali ke nilai bawaan, hapus nilainya.');
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Nilai harus teks UTF-8 yang valid.');
        }

        return match ($type) {
            self::TEXT       => self::text($value, (int) ($def['maxLength'] ?? 255)),
            self::TEXTAREA   => self::textarea($value),
            self::HTML       => self::html($value, $sanitizer ?? new HtmlSanitizer()),
            self::INTEGER    => self::integer($value, $def),
            self::DECIMAL    => self::decimal($value, $def),
            self::ID_REF     => self::idRef($value),
            self::URL        => self::url($value),
            self::ASSET_PATH => self::assetPath($value),
            self::TIME       => self::time($value),
            self::EMAIL_LIST => self::emailList($value),
            default          => throw new LogicException("Tipe web_config tidak dikenal: {$type}."),
        };
    }

    /**
     * Apakah string tersimpan sah untuk tipe key (mis. hasil impor). HTML dianggap sah bila tidak berubah oleh sanitasi.
     *
     * @param array<string, mixed> $def
     */
    public static function isValidStored(array $def, ?string $raw, ?HtmlSanitizer $sanitizer = null): bool
    {
        if ($raw === null) {
            return false;
        }

        try {
            return self::normalize($def, $raw, $sanitizer) === $raw;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * String tersimpan → nilai bertipe untuk konsumen (Fase 3-5). null = belum diatur (atau bawaan null).
     *
     * @param array<string, mixed> $def
     *
     * @return float|int|list<string>|string|null
     */
    public static function cast(array $def, ?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ((string) $def['type']) {
            self::INTEGER, self::ID_REF => $raw === '' ? null : (int) $raw,
            self::DECIMAL               => $raw === '' ? null : (float) $raw,
            self::EMAIL_LIST            => $raw === '' ? [] : explode(',', $raw),
            default                     => $raw,
        };
    }

    /**
     * Validasi katalog Config\WebConfig (dipanggil service & test): nama, tipe, batasan, dan nilai bawaan harus sah.
     *
     * @param array<string, array<string, mixed>> $keys
     *
     * @throws LogicException
     */
    public static function assertCatalog(array $keys): void
    {
        foreach ($keys as $name => $def) {
            if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) > 255) {
                throw new LogicException("Nama key web_config tidak sah: {$name}.");
            }

            foreach (['label', 'group', 'type', 'description'] as $required) {
                if (! isset($def[$required]) || ! is_string($def[$required]) || $def[$required] === '') {
                    throw new LogicException("Key web_config {$name}: '{$required}' wajib diisi.");
                }
            }

            if (! in_array($def['type'], self::TYPES, true)) {
                throw new LogicException("Key web_config {$name}: tipe {$def['type']} tidak dikenal.");
            }

            if (! array_key_exists('default', $def) || ($def['default'] !== null && ! is_string($def['default']))) {
                throw new LogicException("Key web_config {$name}: 'default' wajib string atau null.");
            }

            if (in_array($def['type'], [self::INTEGER, self::DECIMAL], true) && (! isset($def['min'], $def['max']) || $def['min'] > $def['max'])) {
                throw new LogicException("Key web_config {$name}: 'min'/'max' wajib dan min ≤ max.");
            }

            if ($def['type'] === self::DECIMAL && (! isset($def['scale']) || ! is_int($def['scale']) || $def['scale'] < 1)) {
                throw new LogicException("Key web_config {$name}: 'scale' wajib bilangan bulat ≥ 1.");
            }

            if ($def['type'] === self::ID_REF && (! isset($def['ref']) || ! is_string($def['ref']))) {
                throw new LogicException("Key web_config {$name}: 'ref' (tabel rujukan) wajib diisi.");
            }

            if (is_string($def['default']) && $def['default'] !== '' && ! self::isValidStored($def, $def['default'])) {
                throw new LogicException("Key web_config {$name}: nilai bawaan tidak sesuai tipe.");
            }
        }
    }

    // ------------------------------------------------------------------
    // Per tipe
    // ------------------------------------------------------------------

    private static function text(string $value, int $maxLength): string
    {
        $value = trim($value);

        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException('Nilai harus satu baris (tanpa baris baru).');
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Nilai maksimal {$maxLength} karakter.");
        }

        return $value;
    }

    private static function textarea(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        if (strlen($value) > self::TEXT_MAX_BYTES) {
            throw new InvalidArgumentException('Nilai maksimal 65.535 byte.');
        }

        return $value;
    }

    private static function html(string $value, HtmlSanitizer $sanitizer): string
    {
        $clean = $sanitizer->sanitize($value);

        if (! HtmlSanitizer::hasVisibleContent($clean)) {
            throw new InvalidArgumentException('Nilai HTML wajib berisi teks yang terlihat.');
        }

        if (strlen($clean) > self::TEXT_MAX_BYTES) {
            throw new InvalidArgumentException('Nilai maksimal 65.535 byte.');
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function integer(string $value, array $def): string
    {
        $value = trim($value);

        if (preg_match('/^(0|-?[1-9][0-9]{0,17})\z/', $value) !== 1) {
            throw new InvalidArgumentException('Nilai harus bilangan bulat (tanpa titik, koma, atau pemisah ribuan).');
        }

        self::assertRange((float) $value, $def, number_format((float) $def['min'], 0, ',', '.'), number_format((float) $def['max'], 0, ',', '.'));

        return $value;
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function decimal(string $value, array $def): string
    {
        $value = trim($value);
        $scale = (int) $def['scale'];

        if (str_contains($value, ',')) {
            throw new InvalidArgumentException('Gunakan titik sebagai pemisah desimal, mis. 1.5.');
        }

        if (preg_match('/^-?(0|[1-9][0-9]{0,11})(\.[0-9]+)?\z/', $value, $m) !== 1) {
            throw new InvalidArgumentException('Nilai harus angka desimal, mis. 1.5.');
        }

        $fraction = rtrim(substr($m[2] ?? '', 1), '0');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Nilai maksimal {$scale} digit di belakang titik.");
        }

        $canonical = (str_starts_with($value, '-') ? '-' : '') . $m[1] . ($fraction === '' ? '' : '.' . $fraction);

        if ($canonical === '-0') {
            $canonical = '0';
        }

        self::assertRange((float) $canonical, $def, (string) $def['min'], (string) $def['max']);

        return $canonical;
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function assertRange(float $number, array $def, string $minLabel, string $maxLabel): void
    {
        if ($number < (float) $def['min'] || $number > (float) $def['max']) {
            throw new InvalidArgumentException("Nilai harus antara {$minLabel} dan {$maxLabel}.");
        }
    }

    private static function idRef(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $value) !== 1 || (int) $value > self::ID_MAX) {
            throw new InvalidArgumentException('Nilai harus ID angka positif (tanpa nol di depan).');
        }

        return $value;
    }

    private static function url(string $value): string
    {
        $value  = trim($value);
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (preg_match('/\s/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Nilai harus URL lengkap yang diawali http:// atau https://.');
        }

        if (strlen($value) > self::URL_MAX_LENGTH) {
            throw new InvalidArgumentException('URL maksimal 2.048 karakter.');
        }

        return $value;
    }

    private static function assetPath(string $value): string
    {
        $value = trim($value);

        if (strlen($value) > self::PATH_MAX_LENGTH
            || preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*\.(png|jpe?g)\z#i', $value) !== 1) {
            throw new InvalidArgumentException(
                'Nilai harus path relatif berkas gambar .png/.jpg di penyimpanan aset v2 (mis. kop/logo-kiri.png), tanpa path absolut atau "..".',
            );
        }

        return $value;
    }

    private static function time(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/', $value, $m) !== 1) {
            throw new InvalidArgumentException('Nilai harus jam dengan format HH:MM atau HH:MM:SS (00:00 s.d. 23:59:59).');
        }

        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    private static function emailList(string $value): string
    {
        $emails = [];

        foreach (explode(',', $value) as $part) {
            $email = trim($part);

            if ($email === '') {
                throw new InvalidArgumentException('Daftar email tidak boleh berisi isian kosong (cek koma ganda).');
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException("Alamat email tidak valid: {$email}.");
            }

            $key = strtolower($email);

            if (isset($emails[$key])) {
                throw new InvalidArgumentException("Alamat email ganda: {$email}.");
            }

            $emails[$key] = $email;
        }

        if (count($emails) > self::EMAIL_MAX_COUNT) {
            throw new InvalidArgumentException('Maksimal ' . self::EMAIL_MAX_COUNT . ' alamat email.');
        }

        return implode(',', $emails);
    }

    private static function floatToString(float $value): string
    {
        // Angka JSON (mis. 1.25) → representasi terpendek yang bulat-balik; notasi E ditolak validasi tipe.
        $string = var_export($value, true);

        return str_ends_with($string, '.0') ? substr($string, 0, -2) : $string;
    }

    private static function emptyOrShapeMessage(mixed $input): string
    {
        return $input === null
            ? 'Nilai wajib diisi. Untuk kembali ke nilai bawaan, hapus nilainya.'
            : 'Nilai harus teks atau angka.';
    }
}
