<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (K-CR027-1) — penjaga arsitektur: kelas di `app/Libraries/Presensi/SlipGaji/` murni, yaitu tanpa DB, HTTP,
 * session, jam, angka acak, atau I/O berkas. Lebih ketat dari `KalkulasiMurniTest` (CR-025): **semua** `Time::`
 * dilarang tanpa pengecualian, `hariIni()` wajib diberi argumen, dan `::createFromFormat`/`date_create*` dilarang karena
 * mengisi bagian yang tidak ada dari jam sekarang.
 *
 * Pemindai disalin dari `KalkulasiMurniTest` lalu diperluas (ekstraksi ke `tests/_support/Libraries/` menunggu
 * keputusan reviewer). Komentar diabaikan (dipindai per token), dan pemindai diuji dulu dengan contoh pelanggaran.
 *
 * @internal
 */
final class SlipGajiMurniTest extends CIUnitTestCase
{
    /**
     * Fungsi global yang membaca jam/zona, angka acak, DB, konteks request, atau melakukan I/O.
     */
    private const FUNGSI_TERLARANG = [
        // jam dan zona (daftar CR-025 + varian date_create)
        'date', 'gmdate', 'time', 'mktime', 'gmmktime', 'strtotime', 'microtime', 'hrtime', 'date_create',
        'date_create_immutable', 'date_create_from_format', 'date_create_immutable_from_format', 'date_default_timezone_get',
        'date_default_timezone_set', 'cal_days_in_month', 'localtime', 'getdate', 'idate',
        // angka acak
        'random_bytes', 'random_int', 'rand', 'mt_rand', 'srand', 'mt_srand', 'lcg_value', 'uniqid', 'shuffle',
        'str_shuffle', 'array_rand',
        // DB, HTTP, session
        'db_connect', 'model', 'session', 'service', 'request', 'getenv', 'env',
        // I/O dan log
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'file', 'unlink', 'header', 'log_message', 'error_log',
    ];

    /**
     * Potongan nama kelas/namespace yang menandakan akses DB/HTTP/session/jam atau pembaca berkas Excel.
     */
    private const NAMA_TERLARANG = ['Database', 'Model', 'Session', 'Request', 'Services', 'Cache', 'I18n', 'PhpOffice', 'IOFactory'];

    private const SUPERGLOBAL_TERLARANG = ['$_GET', '$_POST', '$_SERVER', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$GLOBALS'];

    public function testSemuaKelasSlipGajiMurni(): void
    {
        $berkas = glob(APPPATH . 'Libraries/Presensi/SlipGaji/*.php');

        $this->assertIsArray($berkas);
        $this->assertGreaterThanOrEqual(14, count($berkas), 'Kelas Slip Gaji CR-027 tidak ditemukan');

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
            // date('Y') dan random_bytes(32) di komentar diabaikan
            use CodeIgniter\Database\BaseConnection;
            use CodeIgniter\I18n\Time;
            use PhpOffice\PhpSpreadsheet\IOFactory;
            final class Contoh {
                public function a(): string { return date('Y-m-d') . strtotime('now'); }
                public function b(): string { return Time::parse('2026-01-01')->format('Y-m-d'); }
                public function c(): void { $db = db_connect(); $m = model('X'); }
                public function d(): string { return (new \DateTimeImmutable('now'))->format('Y'); }
                public function e(): string { return bin2hex(random_bytes(32)) . random_int(1, 9) . mt_rand(); }
                public function f(): string { return \DateTimeImmutable::createFromFormat('Y-m-d', '2026-01-01')->format('H'); }
                public function g(): string { return TanggalBisnis::hariIni(); }
                public function h(): string { return $_GET['x'] . $_SERVER['REMOTE_ADDR']; }
                public function i(): void { file_put_contents('x', 'y'); log_message('error', 'x'); }
                public function j(): string { return date_create_immutable('now')->format('Y'); }
                public function k(): int { return $this->time() + self::date() + $this->random_int(); }
                public function l(string $s): string { return TanggalBisnis::hariIni($s); }
                public function m(): string { return \gmdate('Y') . \random_int(1, 9) . \Foo\time(); }
            }
            PHP;

        $hasil = self::pindai($contoh, 'Contoh.php');

        $harapan = [
            'date()', 'strtotime()', 'db_connect()', 'model()', 'CodeIgniter\Database\BaseConnection', 'CodeIgniter\I18n\Time',
            'PhpOffice\PhpSpreadsheet\IOFactory', 'Time::parse', 'new \DateTimeImmutable', 'random_bytes()', 'random_int()',
            'mt_rand()', '::createFromFormat', 'hariIni() tanpa argumen', '$_GET', '$_SERVER', 'file_put_contents()',
            'log_message()', 'date_create_immutable()', '\gmdate()',
        ];

        foreach ($harapan as $potongan) {
            $this->assertNotEmpty(
                array_filter($hasil, static fn (string $p): bool => str_contains($p, $potongan)),
                "Pemindai tidak menangkap {$potongan}: " . implode('; ', $hasil),
            );
        }

        // Pemanggilan method bernama sama ($this->time(), self::date(), $this->random_int()), komentar, dan hariIni($s)
        // bukan pelanggaran.
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' date()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' random_int()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' \random_int()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_contains($p, 'hariIni()')));
        // Fungsi bernama sama di namespace lain (`\Foo\time()`) juga bukan pelanggaran.
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' time()') || str_ends_with($p, '\time()')));
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

        foreach ($token as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            [$id, $teks, $baris] = $t;
            $sebelum             = $token[$i - 1] ?? null;
            $sesudah             = $token[$i + 1] ?? null;
            $lokasi              = "{$namaBerkas}:{$baris}";

            $pemanggilanMethod = is_array($sebelum)
                && in_array($sebelum[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

            // `\date()` ditokenisasi sebagai T_NAME_FULLY_QUALIFIED, bukan T_STRING; tanpa ini pemindai bisa dilewati
            // cukup dengan awalan `\`.
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

            // Semua Time:: dilarang, tanpa pengecualian (berbeda dengan CR-025).
            if ($id === T_STRING && $teks === 'Time' && is_array($sesudah) && $sesudah[0] === T_DOUBLE_COLON) {
                $pelanggaran[] = "{$lokasi} Time::" . ($token[$i + 2][1] ?? '');
            }

            if ($id === T_DOUBLE_COLON && is_array($sesudah) && strtolower($sesudah[1]) === 'createfromformat') {
                $pelanggaran[] = "{$lokasi} ::createFromFormat";
            }

            if ($id === T_STRING && $teks === 'hariIni' && $sesudah === '(' && ($token[$i + 2] ?? null) === ')') {
                $pelanggaran[] = "{$lokasi} hariIni() tanpa argumen";
            }

            if ($id === T_VARIABLE && in_array($teks, self::SUPERGLOBAL_TERLARANG, true)) {
                $pelanggaran[] = "{$lokasi} {$teks}";
            }
        }

        return $pelanggaran;
    }
}
