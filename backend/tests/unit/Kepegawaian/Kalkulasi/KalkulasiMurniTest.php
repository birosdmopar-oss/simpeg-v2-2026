<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-025 (K-CR025-1) — penjaga arsitektur: kelas di `app/Libraries/Kepegawaian/Kalkulasi/` murni (tanpa DB, HTTP,
 * session, atau jam). Jam hanya boleh dibaca `TanggalBisnis::hariIni()` lewat `Time::now()`. Komentar diabaikan (dipindai
 * per token), dan pemindai diuji dulu dengan contoh pelanggaran agar penjaga ini tidak lolos karena kosong.
 *
 * CR-036 memperluas penjaga ke `app/Libraries/Kepegawaian/Nip/` (registry kolom NIP, format NIP) dan menutup celah yang
 * ditemukan di CR-027: pemanggilan berawalan `\` (`\date()`, `\random_int()`) ditokenisasi PHP 8 sebagai
 * T_NAME_FULLY_QUALIFIED sehingga lolos pemeriksaan T_STRING, begitu pula `\CodeIgniter\I18n\Time::now()`. Daftar fungsi
 * terlarang juga diperluas dengan varian `date_create*`, angka acak, dan `getenv`/`env` (mengikuti `SlipGajiMurniTest`).
 *
 * @internal
 */
final class KalkulasiMurniTest extends CIUnitTestCase
{
    /**
     * Folder kelas murni → jumlah berkas minimum (penjaga tidak boleh lolos karena folder kosong/salah path).
     */
    private const FOLDER_MURNI = [
        'Kalkulasi' => 10, // CR-025 (9) + CR-036 Pensiun
        'Nip'       => 5,  // CR-036
    ];

    /**
     * Fungsi global yang membaca jam/zona, angka acak, DB, atau konteks request.
     */
    private const FUNGSI_TERLARANG = [
        // jam dan zona
        'date', 'gmdate', 'time', 'mktime', 'gmmktime', 'strtotime', 'microtime', 'hrtime', 'date_create',
        'date_create_immutable', 'date_create_from_format', 'date_create_immutable_from_format', 'date_default_timezone_get',
        'date_default_timezone_set', 'cal_days_in_month', 'localtime', 'getdate', 'idate',
        // angka acak
        'random_bytes', 'random_int', 'rand', 'mt_rand', 'srand', 'mt_srand', 'lcg_value', 'uniqid', 'shuffle',
        'str_shuffle', 'array_rand',
        // DB, HTTP, session, lingkungan
        'db_connect', 'model', 'session', 'service', 'request', 'getenv', 'env',
    ];

    private const SUPERGLOBAL_TERLARANG = ['$_GET', '$_POST', '$_SERVER', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$GLOBALS'];

    /**
     * Potongan nama kelas/namespace yang menandakan akses DB/HTTP/session.
     */
    private const NAMA_TERLARANG = ['Database', 'Model', 'Session', 'Request', 'Services', 'Cache'];

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function folderMurni(): iterable
    {
        foreach (self::FOLDER_MURNI as $folder => $minimum) {
            yield $folder => [$folder, $minimum];
        }
    }

    #[DataProvider('folderMurni')]
    public function testSemuaKelasKalkulasiMurni(string $folder, int $minimum): void
    {
        $berkas = glob(APPPATH . "Libraries/Kepegawaian/{$folder}/*.php");

        $this->assertIsArray($berkas);
        $this->assertGreaterThanOrEqual($minimum, count($berkas), "Kelas murni di {$folder}/ tidak ditemukan");

        $pelanggaran = [];

        foreach ($berkas as $path) {
            $kode = file_get_contents($path);
            $this->assertIsString($kode);

            array_push($pelanggaran, ...self::pindai($kode, basename($path)));
        }

        $this->assertSame([], $pelanggaran);
    }

    public function testPemindaiMenangkapPelanggaran(): void
    {
        $contoh = <<<'PHP'
            <?php
            // date('Y') di komentar diabaikan
            use CodeIgniter\Database\BaseConnection;
            use CodeIgniter\I18n\Time;
            final class Contoh {
                public function a(): string { return date('Y-m-d') . strtotime('now'); }
                public function b(): string { return Time::now('UTC')->format('Y-m-d'); }
                public function c(): void { $db = db_connect(); $m = model('X'); }
                public function d(): string { return (new \DateTime('now'))->format('Y'); }
                public function e(): int { return $this->time() + self::date() + $this->random_int(); }
                public function f(): string { return \date('Y') . \random_int(1, 9) . \Foo\time() . \getenv('X'); }
                public function g(): string { return \CodeIgniter\I18n\Time::now()->format('Y'); }
                public function h(): string { return date_create_immutable('now')->format('Y') . mt_rand() . $_SERVER['X']; }
            }
            PHP;

        $hasil = self::pindai($contoh, 'Contoh.php');

        $daftarHarapan = [
            'date()', 'strtotime()', 'db_connect()', 'model()', 'CodeIgniter\Database\BaseConnection', 'Time::now', 'new \DateTime',
            '\date()', '\random_int()', '\getenv()', '\CodeIgniter\I18n\Time::now', 'date_create_immutable()', 'mt_rand()', '$_SERVER',
        ];

        foreach ($daftarHarapan as $harapan) {
            $this->assertNotEmpty(
                array_filter($hasil, static fn (string $p): bool => str_contains($p, $harapan)),
                "Pemindai tidak menangkap {$harapan}: " . implode('; ', $hasil),
            );
        }

        // Pemanggilan method bernama sama ($this->time(), self::date(), $this->random_int()), fungsi bernama sama di
        // namespace lain (`\Foo\time()`), dan komentar bukan pelanggaran.
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' date()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' \date()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' \random_int()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' random_int()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_ends_with($p, 'time()') && ! str_contains($p, 'strtotime()')));

        // `\CodeIgniter\I18n\Time::now()` di hariIni() TanggalBisnis tetap boleh, sama dengan bentuk `use` + `Time::now()`.
        $this->assertSame([], self::pindai("<?php\nfunction hariIni() { return \\CodeIgniter\\I18n\\Time::now(); }", 'TanggalBisnis.php'));

        // Time::now di luar hariIni() TanggalBisnis tetap pelanggaran walau di berkas TanggalBisnis.
        $this->assertNotSame([], self::pindai("<?php\nfunction lain() { return Time::now(); }", 'TanggalBisnis.php'));
    }

    /**
     * @return list<string>
     */
    private static function pindai(string $kode, string $namaBerkas): array
    {
        $token = array_values(array_filter(
            token_get_all($kode),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $pelanggaran = [];
        $fungsi      = null;

        foreach ($token as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            [$id, $teks, $baris] = $t;
            $sebelum             = $token[$i - 1] ?? null;
            $sesudah             = $token[$i + 1] ?? null;
            $lokasi              = "{$namaBerkas}:{$baris}";

            if ($id === T_FUNCTION && is_array($sesudah) && $sesudah[0] === T_STRING) {
                $fungsi = $sesudah[1];
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

            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ! $pemanggilanMethod) {
                foreach (self::NAMA_TERLARANG as $nama) {
                    if (preg_match('/(^|\\\\)' . $nama . '/', $teks) === 1) {
                        $pelanggaran[] = "{$lokasi} {$teks}";
                    }
                }
            }

            if ($id === T_NEW && is_array($sesudah) && preg_match('/^\\\\?DateTime(Immutable)?$/', $sesudah[1]) === 1) {
                $pelanggaran[] = "{$lokasi} new {$sesudah[1]}";
            }

            $namaTime = ($id === T_STRING && $teks === 'Time')
                || (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(^|\\\\)I18n\\\\Time$/', $teks) === 1);

            if ($namaTime && is_array($sesudah) && $sesudah[0] === T_DOUBLE_COLON) {
                $method = $token[$i + 2][1] ?? '';

                if (! ($namaBerkas === 'TanggalBisnis.php' && $fungsi === 'hariIni' && $method === 'now')) {
                    $pelanggaran[] = "{$lokasi} {$teks}::{$method} di luar TanggalBisnis::hariIni()";
                }
            }

            if ($id === T_VARIABLE && in_array($teks, self::SUPERGLOBAL_TERLARANG, true)) {
                $pelanggaran[] = "{$lokasi} {$teks}";
            }
        }

        return $pelanggaran;
    }
}
