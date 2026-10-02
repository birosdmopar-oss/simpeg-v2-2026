<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-037 — penjaga arsitektur: kelas di `app/Libraries/Presensi/Tukin/` murni (tanpa DB, HTTP, session, atau jam
 * sistem). Pola sama dengan `Tests\Unit\Kepegawaian\Kalkulasi\KalkulasiMurniTest` (CR-025), tetapi lebih ketat:
 * `Time::*` sama sekali tidak boleh dipakai karena "hari ini" diinjeksikan lewat parameter `hariIni`.
 *
 * @internal
 */
final class KalkulasiMurniTest extends CIUnitTestCase
{
    private const FUNGSI_TERLARANG = [
        'date', 'gmdate', 'time', 'mktime', 'strtotime', 'microtime', 'hrtime', 'date_create', 'date_create_immutable',
        'date_default_timezone_get', 'date_default_timezone_set', 'cal_days_in_month', 'getdate', 'localtime',
        'db_connect', 'model', 'session', 'service', 'request', 'config', 'env', 'getenv', 'file_get_contents', 'curl_init',
    ];

    private const NAMA_TERLARANG = ['Database', 'Model', 'Session', 'Request', 'Services', 'Cache', 'Config', 'Time'];

    public function testSemuaKelasTukinMurni(): void
    {
        $berkas = glob(APPPATH . 'Libraries/Presensi/Tukin/*.php');

        $this->assertIsArray($berkas);
        $this->assertGreaterThanOrEqual(9, count($berkas), 'Kelas kalkulasi Tukin CR-037 tidak ditemukan');

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
                public function b(): string { return Time::now('Asia/Jakarta')->format('Y-m-d'); }
                public function c(): void { $db = db_connect(); $m = model('X'); $s = service('session'); }
                public function d(): string { return (new \DateTimeImmutable())->format('Y'); }
                public function e(): int { return $this->time() + self::date(); }
            }
            PHP;

        $hasil = self::pindai($contoh, 'Contoh.php');

        foreach (['date()', 'strtotime()', 'db_connect()', 'model()', 'service()', 'CodeIgniter\Database\BaseConnection', 'Time', 'new \DateTimeImmutable'] as $harapan) {
            $this->assertNotEmpty(
                array_filter($hasil, static fn (string $p): bool => str_contains($p, $harapan)),
                "Pemindai tidak menangkap {$harapan}: " . implode('; ', $hasil),
            );
        }

        // Pemanggilan method bernama sama dan komentar bukan pelanggaran.
        $this->assertCount(1, array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' date()')));
        $this->assertSame([], array_filter($hasil, static fn (string $p): bool => str_ends_with($p, ' time()')));
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
                && in_array($sebelum[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);

            if ($id === T_STRING && $sesudah === '(' && ! $pemanggilanMethod
                && in_array(strtolower($teks), self::FUNGSI_TERLARANG, true)) {
                $pelanggaran[] = "{$lokasi} {$teks}()";
            }

            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ! $pemanggilanMethod) {
                foreach (self::NAMA_TERLARANG as $nama) {
                    if (preg_match('/(^|\\\\)' . $nama . '(\\\\|$)/', $teks) === 1) {
                        $pelanggaran[] = "{$lokasi} {$teks}";
                    }
                }
            }

            if ($id === T_NEW && is_array($sesudah) && preg_match('/^\\\\?DateTime(Immutable)?$/', $sesudah[1]) === 1) {
                $pelanggaran[] = "{$lokasi} new {$sesudah[1]}";
            }
        }

        return $pelanggaran;
    }
}
