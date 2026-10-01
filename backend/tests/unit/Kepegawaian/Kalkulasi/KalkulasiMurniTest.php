<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-025 (K-CR025-1) — penjaga arsitektur: kelas di `app/Libraries/Kepegawaian/Kalkulasi/` murni (tanpa DB, HTTP,
 * session, atau jam). Jam hanya boleh dibaca `TanggalBisnis::hariIni()` lewat `Time::now()`. Komentar diabaikan (dipindai
 * per token), dan pemindai diuji dulu dengan contoh pelanggaran agar penjaga ini tidak lolos karena kosong.
 *
 * @internal
 */
final class KalkulasiMurniTest extends CIUnitTestCase
{
    /**
     * Fungsi global yang membaca jam/zona, DB, atau konteks request.
     */
    private const FUNGSI_TERLARANG = [
        'date', 'gmdate', 'time', 'mktime', 'strtotime', 'microtime', 'hrtime', 'date_create', 'date_default_timezone_get',
        'date_default_timezone_set', 'cal_days_in_month', 'db_connect', 'model', 'session', 'service', 'request',
    ];

    /**
     * Potongan nama kelas/namespace yang menandakan akses DB/HTTP/session.
     */
    private const NAMA_TERLARANG = ['Database', 'Model', 'Session', 'Request', 'Services', 'Cache'];

    public function testSemuaKelasKalkulasiMurni(): void
    {
        $berkas = glob(APPPATH . 'Libraries/Kepegawaian/Kalkulasi/*.php');

        $this->assertIsArray($berkas);
        $this->assertGreaterThanOrEqual(9, count($berkas), 'Kelas kalkulasi CR-025 tidak ditemukan');

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
                public function e(): int { return $this->time() + self::date(); }
            }
            PHP;

        $hasil = self::pindai($contoh, 'Contoh.php');

        foreach (['date()', 'strtotime()', 'db_connect()', 'model()', 'CodeIgniter\Database\BaseConnection', 'Time::now', 'new \DateTime'] as $harapan) {
            $this->assertNotEmpty(
                array_filter($hasil, static fn (string $p): bool => str_contains($p, $harapan)),
                "Pemindai tidak menangkap {$harapan}: " . implode('; ', $hasil),
            );
        }

        // Pemanggilan method bernama sama ($this->time(), self::date()) dan komentar bukan pelanggaran.
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_contains($p, 'date()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_contains($p, 'time()') && ! str_contains($p, 'strtotime()')));

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

            if ($id === T_STRING && $sesudah === '(' && ! $pemanggilanMethod
                && in_array(strtolower($teks), self::FUNGSI_TERLARANG, true)) {
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

            if ($id === T_STRING && $teks === 'Time' && is_array($sesudah) && $sesudah[0] === T_DOUBLE_COLON) {
                $method = $token[$i + 2][1] ?? '';

                if (! ($namaBerkas === 'TanggalBisnis.php' && $fungsi === 'hariIni' && $method === 'now')) {
                    $pelanggaran[] = "{$lokasi} Time::{$method} di luar TanggalBisnis::hariIni()";
                }
            }
        }

        return $pelanggaran;
    }
}
