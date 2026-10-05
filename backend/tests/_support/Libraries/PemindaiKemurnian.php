<?php

declare(strict_types=1);

namespace Tests\Support\Libraries;

use RuntimeException;

/**
 * CR-040 (U-8) — pemindai kemurnian bersama untuk penjaga arsitektur kelas kalkulasi murni (tanpa DB, HTTP, session,
 * jam sistem, angka acak, lingkungan, atau I/O). Menggantikan tiga salinan pemindai di `KalkulasiMurniTest`
 * Kepegawaian (CR-025/CR-036), `SlipGajiMurniTest` (CR-027), dan `KalkulasiMurniTest` Tukin (CR-037).
 *
 * Daftar larangan adalah **gabungan (superset)** ketiga salinan lama dan berlaku sama untuk semua modul. Satu-satunya
 * yang dapat diatur per modul adalah pengecualian sempit yang dicatat di test modul masing-masing:
 *
 * - `$izinTime`: `Time::<method>` (bentuk `Time::` maupun `\CodeIgniter\I18n\Time::`) hanya boleh di fungsi tertentu
 *   di berkas tertentu, mis. `TanggalBisnis::hariIni()` → `Time::now()`. Impor `CodeIgniter\I18n\Time` pun hanya
 *   boleh di berkas itu. Tanpa pengecualian, semua `Time::` dan nama `I18n`/`Time` dilarang.
 * - `$createFromFormatBerjangkar`: `::createFromFormat` dilarang seluruhnya (bawaan), atau — bila `true` — hanya boleh
 *   dengan format literal berawalan `!` atau memuat `|` (bagian tanggal/jam yang tidak disebut diisi nol, bukan jam
 *   sekarang). Format dinamis/non-literal tetap pelanggaran.
 *
 * Pemindaian per token sehingga komentar diabaikan. Pemanggilan method/static bernama sama (`$this->time()`,
 * `self::date()`), deklarasi fungsi, dan fungsi bernama sama di namespace lain (`\Foo\time()`) bukan pelanggaran.
 * Perilaku pemindai diuji di `Tests\Unit\Libraries\PemindaiKemurnianTest`.
 */
final class PemindaiKemurnian
{
    /**
     * Fungsi global terlarang (dicocokkan tanpa membedakan huruf besar/kecil, termasuk bentuk berawalan `\`).
     */
    public const FUNGSI_TERLARANG = [
        // jam dan zona
        'date', 'gmdate', 'time', 'mktime', 'gmmktime', 'strtotime', 'microtime', 'hrtime', 'date_create',
        'date_create_immutable', 'date_create_from_format', 'date_create_immutable_from_format', 'date_default_timezone_get',
        'date_default_timezone_set', 'cal_days_in_month', 'localtime', 'getdate', 'idate',
        // angka acak
        'random_bytes', 'random_int', 'rand', 'mt_rand', 'srand', 'mt_srand', 'lcg_value', 'uniqid', 'shuffle',
        'str_shuffle', 'array_rand',
        // DB, HTTP, session, konfigurasi, lingkungan
        'db_connect', 'model', 'session', 'service', 'request', 'config', 'getenv', 'env',
        // I/O, jaringan, dan log
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'file', 'unlink', 'header', 'curl_init',
        'log_message', 'error_log',
    ];

    /**
     * Awal segmen nama kelas/namespace terlarang (peka huruf besar/kecil): akses DB/HTTP/session/konfigurasi/jam atau
     * pembaca berkas Excel. `Config` juga mengenai `ConfigReader`, `Time` juga mengenai `Timer` — sengaja ketat.
     */
    public const NAMA_TERLARANG = [
        'Database', 'Model', 'Session', 'Request', 'Services', 'Cache', 'Config', 'I18n', 'Time', 'PhpOffice', 'IOFactory',
    ];

    public const SUPERGLOBAL_TERLARANG = [
        '$_GET', '$_POST', '$_SERVER', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$GLOBALS',
    ];

    /**
     * @param list<array{berkas: string, fungsi: string, method: string}> $izinTime
     */
    public function __construct(
        private readonly array $izinTime = [],
        private readonly bool $createFromFormatBerjangkar = false,
    ) {
    }

    /**
     * Pindai semua `*.php` langsung di folder.
     *
     * @return array{jumlahBerkas: int, pelanggaran: list<string>}
     */
    public function pindaiFolder(string $folder): array
    {
        $berkas = glob(rtrim($folder, '/\\') . '/*.php');

        if ($berkas === false) {
            throw new RuntimeException("Folder {$folder} tidak dapat dibaca");
        }

        $pelanggaran = [];

        foreach ($berkas as $path) {
            $kode = file_get_contents($path);

            if ($kode === false) {
                throw new RuntimeException("Berkas {$path} tidak dapat dibaca");
            }

            array_push($pelanggaran, ...$this->pindai($kode, basename($path)));
        }

        return ['jumlahBerkas' => count($berkas), 'pelanggaran' => $pelanggaran];
    }

    /**
     * @return list<string>
     */
    public function pindai(string $kode, string $namaBerkas): array
    {
        $token = array_values(array_filter(
            token_get_all($kode),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $pelanggaran = [];
        $fungsi      = null;
        $berkasIzin  = in_array($namaBerkas, array_column($this->izinTime, 'berkas'), true);

        foreach ($token as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            [$id, $teks, $baris] = $t;
            $sebelum             = $token[$i - 1] ?? null;
            $sesudah             = $token[$i + 1] ?? null;
            $lokasi              = "{$namaBerkas}:{$baris}";

            // Fungsi yang sedang dipindai (untuk pengecualian Time::). Closure/arrow function tidak mewarisi nama
            // fungsi pembungkus, jadi pengecualian tidak berlaku di dalamnya.
            if ($id === T_FUNCTION || $id === T_FN) {
                $fungsi = is_array($sesudah) && $sesudah[0] === T_STRING ? $sesudah[1] : null;
            }

            $pemanggilanMethod = is_array($sebelum)
                && in_array($sebelum[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

            // `\date()` ditokenisasi sebagai T_NAME_FULLY_QUALIFIED, bukan T_STRING; tanpa ini pemindai bisa dilewati
            // cukup dengan awalan `\`. Nama berawalan namespace lain (`\Foo\time`) tetap tidak cocok.
            $namaFungsi = match ($id) {
                T_STRING               => $teks,
                T_NAME_FULLY_QUALIFIED => substr($teks, 1),
                default                => null,
            };

            if ($namaFungsi !== null && $sesudah === '(' && ! $pemanggilanMethod
                && in_array(strtolower($namaFungsi), self::FUNGSI_TERLARANG, true)) {
                $pelanggaran[] = "{$lokasi} {$teks}()";
            }

            $nama      = in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
            $namaTime  = $nama && preg_match('/(^|\\\\)Time$/', $teks) === 1;
            $timeAkses = $namaTime && is_array($sesudah) && $sesudah[0] === T_DOUBLE_COLON;

            if ($timeAkses) {
                $method = $token[$i + 2][1] ?? '';

                if (! $this->timeDiizinkan($namaBerkas, $fungsi, $method)) {
                    $pelanggaran[] = "{$lokasi} {$teks}::{$method}" . ($this->izinTime === [] ? '' : ' di luar pengecualian');
                }
            } elseif ($nama && ! $pemanggilanMethod
                && ! ($berkasIzin && preg_match('/^\\\\?CodeIgniter\\\\I18n\\\\Time$/', $teks) === 1)) {
                foreach (self::NAMA_TERLARANG as $terlarang) {
                    if (preg_match('/(^|\\\\)' . $terlarang . '/', $teks) === 1) {
                        $pelanggaran[] = "{$lokasi} {$teks}";
                        break;
                    }
                }
            }

            if ($id === T_NEW && is_array($sesudah) && preg_match('/^\\\\?DateTime(Immutable)?$/', $sesudah[1]) === 1) {
                $pelanggaran[] = "{$lokasi} new {$sesudah[1]}";
            }

            if ($id === T_DOUBLE_COLON && is_array($sesudah) && strtolower($sesudah[1]) === 'createfromformat'
                && ! ($this->createFromFormatBerjangkar && self::formatBerjangkar($token[$i + 2] ?? null, $token[$i + 3] ?? null))) {
                $pelanggaran[] = "{$lokasi} ::createFromFormat" . ($this->createFromFormatBerjangkar ? ' tanpa format berawalan ! atau |' : '');
            }

            // hariIni() tanpa argumen (fungsi, method, maupun static) membaca jam sistem; kelas murni wajib menerima
            // "hari ini" dari pemanggil.
            if ($id === T_STRING && $teks === 'hariIni' && $sesudah === '(' && ($token[$i + 2] ?? null) === ')') {
                $pelanggaran[] = "{$lokasi} hariIni() tanpa argumen";
            }

            if ($id === T_VARIABLE && in_array($teks, self::SUPERGLOBAL_TERLARANG, true)) {
                $pelanggaran[] = "{$lokasi} {$teks}";
            }
        }

        return $pelanggaran;
    }

    private function timeDiizinkan(string $namaBerkas, ?string $fungsi, string $method): bool
    {
        foreach ($this->izinTime as $izin) {
            if ($izin['berkas'] === $namaBerkas && $izin['fungsi'] === $fungsi && $izin['method'] === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * `createFromFormat('!Y-m-d', …)` / `'Y-m-d|'`: format literal yang mengisi bagian tak disebut dengan nol.
     *
     * @param array{int, string, int}|string|null $kurung
     * @param array{int, string, int}|string|null $format
     */
    private static function formatBerjangkar(array|string|null $kurung, array|string|null $format): bool
    {
        if ($kurung !== '(' || ! is_array($format) || $format[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return false;
        }

        $isi = substr($format[1], 1, -1);

        return str_starts_with($isi, '!') || str_contains($isi, '|');
    }
}
