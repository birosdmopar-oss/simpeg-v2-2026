<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * CR-040 (U-8) — uji diri pemindai kemurnian bersama (`Tests\Support\Libraries\PemindaiKemurnian`). Menyatukan contoh
 * pelanggaran dari tiga penjaga lama (CR-025/CR-036 Kepegawaian, CR-027 Slip Gaji, CR-037 Tukin) agar penjaga modul
 * tidak lolos karena pemindainya kosong/rusak.
 *
 * @internal
 */
final class PemindaiKemurnianTest extends CIUnitTestCase
{
    private const CONTOH = <<<'PHP'
        <?php
        // date('Y'), random_bytes(32), dan $_GET di komentar diabaikan
        /** Time::now() di docblock juga diabaikan */
        use CodeIgniter\Database\BaseConnection;
        use CodeIgniter\I18n\Time;
        use PhpOffice\PhpSpreadsheet\IOFactory;
        use Config\App;
        final class Contoh {
            public function a(): string { return date('Y-m-d') . strtotime('now'); }
            public function b(): string { return Time::now('Asia/Jakarta')->format('Y-m-d') . Time::parse('2026-01-01'); }
            public function c(): void { $db = db_connect(); $m = model('X'); $s = service('session'); $k = config('App'); }
            public function d(): string { return (new \DateTimeImmutable('now'))->format('Y') . (new \DateTime())->format('Y'); }
            public function e(): int { return $this->time() + self::date() + $this->random_int() + $o?->rand(); }
            public function f(): string { return \date('Y') . \random_int(1, 9) . \Foo\time() . \getenv('X') . \gmdate('Y'); }
            public function g(): string { return \CodeIgniter\I18n\Time::now()->format('Y'); }
            public function h(): string { return date_create_immutable('now')->format('Y') . mt_rand() . $_SERVER['X']; }
            public function i(): string { return bin2hex(random_bytes(32)) . random_int(1, 9) . uniqid(); }
            public function j(): string { return \DateTimeImmutable::createFromFormat('Y-m-d', '2026-01-01')->format('H'); }
            public function k(): string { return TanggalBisnis::hariIni(); }
            public function l(string $s): string { return TanggalBisnis::hariIni($s); }
            public function m(): string { return $_GET['x'] . $_POST['y'] . $_SESSION['z'] . $_ENV['w'] . $GLOBALS['v']; }
            public function n(): void { file_put_contents('x', 'y'); log_message('error', 'x'); curl_init(); }
            public function o(): string { return env('X') . getenv('Y') . file_get_contents('z'); }
            public function p(Session $s, \CodeIgniter\Cache\CacheInterface $c): ?Request { return null; }
            public function date(): void {}
        }
        PHP;

    /**
     * Potongan yang wajib muncul di laporan pemindai untuk CONTOH.
     *
     * @return iterable<string, array{string}>
     */
    public static function harapanPelanggaran(): iterable
    {
        $daftar = [
            // fungsi jam, termasuk berawalan `\` (T_NAME_FULLY_QUALIFIED)
            ' date()', 'strtotime()', '\date()', '\gmdate()', 'date_create_immutable()',
            // angka acak
            'random_bytes()', ' random_int()', '\random_int()', 'mt_rand()', 'uniqid()',
            // DB, HTTP, session, konfigurasi, lingkungan
            'db_connect()', 'model()', 'service()', 'config()', ' env()', ' getenv()', '\getenv()',
            // I/O dan log
            'file_put_contents()', 'log_message()', 'curl_init()', 'file_get_contents()',
            // nama kelas/namespace terlarang
            'CodeIgniter\Database\BaseConnection', 'CodeIgniter\I18n\Time', 'PhpOffice\PhpSpreadsheet\IOFactory', 'Config\App',
            ' Session', 'CodeIgniter\Cache\CacheInterface', ' Request',
            // jam lewat objek
            'Time::now', 'Time::parse', '\CodeIgniter\I18n\Time::now', 'new \DateTimeImmutable', 'new \DateTime',
            '::createFromFormat', 'hariIni() tanpa argumen',
            // superglobal
            '$_SERVER', '$_GET', '$_POST', '$_SESSION', '$_ENV', '$GLOBALS',
        ];

        foreach ($daftar as $potongan) {
            yield $potongan => [$potongan];
        }
    }

    #[DataProvider('harapanPelanggaran')]
    public function testPemindaiMenangkapPelanggaran(string $potongan): void
    {
        $hasil = (new PemindaiKemurnian())->pindai(self::CONTOH, 'Contoh.php');

        $this->assertNotEmpty(
            array_filter($hasil, static fn (string $p): bool => str_contains($p, $potongan)),
            "Pemindai tidak menangkap {$potongan}: " . implode('; ', $hasil),
        );
    }

    /**
     * Daftar ditulis ulang di sini (bukan dibaca dari konstanta pemindai) supaya penghapusan satu larangan pun
     * membuat test gagal.
     */
    public function testSetiapFungsiTerlarangTertangkapDenganDanTanpaAwalanBackslash(): void
    {
        $fungsi = [
            'date', 'gmdate', 'time', 'mktime', 'gmmktime', 'strtotime', 'microtime', 'hrtime', 'date_create',
            'date_create_immutable', 'date_create_from_format', 'date_create_immutable_from_format',
            'date_default_timezone_get', 'date_default_timezone_set', 'cal_days_in_month', 'localtime', 'getdate', 'idate',
            'random_bytes', 'random_int', 'rand', 'mt_rand', 'srand', 'mt_srand', 'lcg_value', 'uniqid', 'shuffle',
            'str_shuffle', 'array_rand',
            'db_connect', 'model', 'session', 'service', 'request', 'config', 'getenv', 'env',
            'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'file', 'unlink', 'header', 'curl_init',
            'log_message', 'error_log',
        ];
        $pemindai = new PemindaiKemurnian();

        foreach ($fungsi as $nama) {
            foreach (["{$nama}()", '\\' . "{$nama}()", strtoupper($nama) . '()'] as $panggilan) {
                $this->assertNotSame([], $pemindai->pindai("<?php\n\$x = {$panggilan};", 'A.php'), "{$panggilan} lolos");
            }
        }
    }

    public function testSetiapNamaTerlarangTertangkap(): void
    {
        $nama     = ['Database', 'Model', 'Session', 'Request', 'Services', 'Cache', 'Config', 'I18n', 'Time', 'PhpOffice', 'IOFactory'];
        $pemindai = new PemindaiKemurnian();

        foreach ($nama as $n) {
            $this->assertNotSame([], $pemindai->pindai("<?php\nuse Vendor\\{$n}\\Lain;", 'A.php'), "segmen {$n} lolos");
            $this->assertNotSame([], $pemindai->pindai("<?php\nfunction f({$n}X \$a): void {}", 'A.php'), "awalan {$n} lolos");
        }

        foreach (['$_GET', '$_POST', '$_SERVER', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$GLOBALS'] as $sg) {
            $this->assertNotSame([], $pemindai->pindai("<?php\n\$x = {$sg}['a'];", 'A.php'), "{$sg} lolos");
        }
    }

    public function testBukanPelanggaran(): void
    {
        $hasil = (new PemindaiKemurnian())->pindai(self::CONTOH, 'Contoh.php');

        // Komentar, pemanggilan method bernama sama ($this->time(), self::date(), $this->random_int(), $o?->rand()),
        // deklarasi method `date()`, fungsi bernama sama di namespace lain (`\Foo\time()`), dan hariIni($s) bukan
        // pelanggaran.
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' date()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' \date()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' random_int()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' \random_int()')));
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_contains($p, 'hariIni()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' rand()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_ends_with($p, 'time()') && ! str_contains($p, 'strtotime()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_starts_with($p, 'Contoh.php:2 ') || str_starts_with($p, 'Contoh.php:3 ')));

        $this->assertSame([], (new PemindaiKemurnian())->pindai("<?php\nfinal class Bersih { public function a(int \$x): int { return \$x + 1; } }", 'Bersih.php'));
    }

    public function testPengecualianTimeSempit(): void
    {
        $pemindai = new PemindaiKemurnian([['berkas' => 'TanggalBisnis.php', 'fungsi' => 'hariIni', 'method' => 'now']]);

        // Diizinkan: impor + Time::now() / \CodeIgniter\I18n\Time::now() di hariIni() TanggalBisnis.
        $this->assertSame([], $pemindai->pindai(
            "<?php\nuse CodeIgniter\\I18n\\Time;\nfunction hariIni(\$s = null) { return Time::now(); }",
            'TanggalBisnis.php',
        ));
        $this->assertSame([], $pemindai->pindai(
            "<?php\nfunction hariIni(\$s = null) { return \\CodeIgniter\\I18n\\Time::now(); }",
            'TanggalBisnis.php',
        ));

        // Tetap pelanggaran: method lain, fungsi lain, berkas lain, closure di dalam hariIni(), impor di berkas lain,
        // dan tanpa pengecualian sama sekali.
        $kasus = [
            ["<?php\nfunction hariIni(\$s = null) { return Time::parse('x'); }", 'TanggalBisnis.php', $pemindai],
            ["<?php\nfunction lain() { return Time::now(); }", 'TanggalBisnis.php', $pemindai],
            ["<?php\nfunction hariIni(\$s = null) { return Time::now(); }", 'Lain.php', $pemindai],
            ["<?php\nfunction hariIni(\$s = null) { return (function () { return Time::now(); })(); }", 'TanggalBisnis.php', $pemindai],
            ["<?php\nfunction hariIni(\$s = null) { return (fn () => Time::now())(); }", 'TanggalBisnis.php', $pemindai],
            ["<?php\nuse CodeIgniter\\I18n\\Time;", 'Lain.php', $pemindai],
            ["<?php\nfunction hariIni(\$s = null) { return Time::now(); }", 'TanggalBisnis.php', new PemindaiKemurnian()],
        ];

        foreach ($kasus as $n => [$kode, $berkas, $p]) {
            $this->assertNotSame([], $p->pindai($kode, $berkas), "Kasus {$n} seharusnya pelanggaran");
        }
    }

    public function testCreateFromFormatBerjangkar(): void
    {
        $longgar = new PemindaiKemurnian([], true);

        $this->assertSame([], $longgar->pindai("<?php\n\\DateTimeImmutable::createFromFormat('!Y-m-d', \$t);", 'A.php'));
        $this->assertSame([], $longgar->pindai("<?php\n\\DateTimeImmutable::createFromFormat('Y-m-d|', \$t);", 'A.php'));
        $this->assertNotSame([], $longgar->pindai("<?php\n\\DateTimeImmutable::createFromFormat('Y-m-d', \$t);", 'A.php'));
        $this->assertNotSame([], $longgar->pindai("<?php\n\\DateTimeImmutable::createFromFormat(\$f, \$t);", 'A.php'));

        // Bawaan: semua createFromFormat dilarang, termasuk yang berjangkar.
        $this->assertNotSame([], (new PemindaiKemurnian())->pindai("<?php\n\\DateTimeImmutable::createFromFormat('!Y-m-d', \$t);", 'A.php'));
    }
}
