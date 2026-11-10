<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Simapi\AturanTrigger;
use App\Libraries\Integrasi\Simapi\AturanTriggerUpdate;
use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MAKE-060 A5b P2 + MAKE-076 — test arsitektur penulis log SIMAPI, PER TABEL LOG:
 *  - `simapi_ch`: 15 tabel sumber ({@see AturanTrigger::tabel()});
 *  - `simapi_update`: `pegawai`, `pegawai_mutasi_jabatan`, `pengguna` ({@see AturanTriggerUpdate::TABEL}).
 * Setiap berkas di `app/` yang MENULIS ke tabel sumber suatu log wajib memanggil `PencatatPerubahan::` atau tercatat di daftar
 * pengecualian log ITU (beralasan), dan tidak boleh memakai `transException(true)` (insert log bersavepoint tidak bisa menyelamatkan
 * transaksi itu). Penulis baru tanpa pemancar → test merah (data perubahan tidak boleh hilang diam-diam).
 *
 * Deteksi tulisan (heuristik statis): `table('<tabel>')…->insert|update|delete|replace|insertBatch|updateBatch(` dalam satu ekspresi,
 * atau model `PegawaiModel`/`PenggunaModel` yang dipakai menulis. Penulis bertabel dinamis (`$jenis->tabel()`, rencana tarik,
 * registry NIP, snapshot riwayat) terdaftar per log dan wajib memanggil pemancar.
 *
 * @internal
 */
final class PemancarSimapiArsitekturTest extends CIUnitTestCase
{
    private const MODEL = [
        'Models/Auth/PenggunaModel.php'       => 'definisi model, bukan penulis',
        'Models/Kepegawaian/PegawaiModel.php' => 'definisi model, bukan penulis',
    ];

    /** Berkas penulis yang sengaja TIDAK memancar `simapi_ch` (relatif `app/`), dengan alasan. */
    private const PENGECUALIAN_CH = self::MODEL + [
        'Libraries/Auth/AuthService.php'                   => 'hanya last_login_at; trigger pengguna legacy menulis simapi_ch hanya bila email berubah',
        'Libraries/Auth/BootstrapAdmin.php'                => 'akun admin tanpa NIP (CR-093)',
        'Libraries/Integrasi/AkunSistemIntegrasi.php'      => 'akun sistem integrasi tanpa NIP (CR-093)',
        'Models/Kepegawaian/RiwayatModel.php'              => 'definisi model, bukan penulis (penulis: RiwayatEngine/AnakKeluarga)',
        'Libraries/Integrasi/Simapi/PencatatPerubahan.php' => 'pencatat itu sendiri (membaca)',
    ];

    /** Berkas penulis yang sengaja TIDAK memancar `simapi_update`, dengan alasan. PasswordService/Reset/Verifier WAJIB memancar. */
    private const PENGECUALIAN_UPDATE = self::MODEL + [
        'Libraries/Auth/AuthService.php'                   => 'hanya last_login_at; trigger pengguna legacy hanya membandingkan username|password|email',
        'Libraries/Auth/BootstrapAdmin.php'                => 'akun admin tanpa NIP (CR-093) dan bukan level pegawai',
        'Libraries/Integrasi/AkunSistemIntegrasi.php'      => 'akun sistem integrasi tanpa NIP (CR-093)',
        'Libraries/Integrasi/Simapi/PencatatPerubahan.php' => 'pencatat itu sendiri (membaca)',
    ];

    /** Penulis bertabel dinamis per log yang wajib memancar. */
    private const DINAMIS_CH = [
        'Libraries/Kepegawaian/Riwayat/RiwayatEngine.php',
        'Libraries/Kepegawaian/Riwayat/AnakKeluarga.php',
        'Libraries/Kepegawaian/NipCascadeService.php',
        'Libraries/Siasn/Tarik/PenulisTarik.php',
        'Libraries/Siasn/Dorong/DorongSiasn.php',
    ];

    private const DINAMIS_UPDATE = [
        'Libraries/Kepegawaian/Riwayat/SnapshotSync.php', // snapshot pegawai_mutasi_jabatan (AturanSnapshot Jabatan)
        'Libraries/Kepegawaian/NipCascadeService.php',
    ];

    /** Impor cutover sengaja tidak memancar (keputusan Horii 09-11 opsi a). */
    private const DIKECUALIKAN_FOLDER = ['Libraries/Impor/'];

    /**
     * @return array<string, string> path relatif => isi
     */
    private static function berkasApp(): array
    {
        $akar  = APPPATH;
        $hasil = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar)) as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($akar)));

            foreach (self::DIKECUALIKAN_FOLDER as $folder) {
                if (str_starts_with($rel, $folder)) {
                    continue 2;
                }
            }

            $hasil[$rel] = (string) file_get_contents($f->getPathname());
        }

        return $hasil;
    }

    /**
     * @param list<string> $tabelSumber
     */
    private static function menulis(string $isi, array $tabelSumber): bool
    {
        $tabel = implode('|', array_map('preg_quote', $tabelSumber));

        if (preg_match("/table\\(\\s*'(?:{$tabel})'\\s*\\)[^;]*->(?:insert|update|delete|replace|insertBatch|updateBatch)\\(/s", $isi) === 1) {
            return true;
        }

        return preg_match('/new (?:PegawaiModel|PenggunaModel)\(/', $isi) === 1 && preg_match('/->(?:insert|update|delete|save)\(/', $isi) === 1
            || preg_match('/\$this->pengguna->(?:insert|update)\(/', $isi) === 1;
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: array<string, string>, 2: list<string>}>
     */
    public static function log(): iterable
    {
        yield 'simapi_ch' => [AturanTrigger::tabel(), self::PENGECUALIAN_CH, self::DINAMIS_CH];

        yield 'simapi_update' => [AturanTriggerUpdate::TABEL, self::PENGECUALIAN_UPDATE, self::DINAMIS_UPDATE];
    }

    /**
     * @dataProvider log
     *
     * @param list<string>          $tabel
     * @param array<string, string> $pengecualian
     * @param list<string>          $dinamis
     */
    public function testSetiapPenulisMemancarAtauDikecualikan(array $tabel, array $pengecualian, array $dinamis): void
    {
        $tanpa = [];

        foreach (self::berkasApp() as $rel => $isi) {
            $penulis = self::menulis($isi, $tabel) || in_array($rel, $dinamis, true);

            if (! $penulis || isset($pengecualian[$rel])) {
                continue;
            }

            if (! str_contains($isi, 'PencatatPerubahan::')) {
                $tanpa[] = $rel;
            }
        }

        $this->assertSame([], $tanpa, 'Penulis tabel sumber tanpa PencatatPerubahan (tambahkan pemancar atau pengecualian beralasan).');
    }

    /**
     * @dataProvider log
     *
     * @param list<string>          $tabel
     * @param array<string, string> $pengecualian
     * @param list<string>          $dinamis
     */
    public function testPenulisDinamisMemancar(array $tabel, array $pengecualian, array $dinamis): void
    {
        foreach ($dinamis as $rel) {
            $this->assertStringContainsString('PencatatPerubahan::', (string) file_get_contents(APPPATH . $rel), $rel);
        }
    }

    public function testPasswordServiceDanResetWajibMemancarSimapiUpdate(): void
    {
        foreach (['Libraries/Auth/PasswordService.php', 'Libraries/Auth/ResetPasswordService.php', 'Libraries/Auth/PasswordVerifier.php'] as $rel) {
            $this->assertArrayNotHasKey($rel, self::PENGECUALIAN_UPDATE, $rel);
            $this->assertStringContainsString('PencatatPerubahan::sesudahUbah', (string) file_get_contents(APPPATH . $rel), $rel);
        }
    }

    public function testPenulisTidakMemakaiTransExceptionTrue(): void
    {
        $salah = [];

        foreach (self::berkasApp() as $rel => $isi) {
            if (str_contains($isi, 'PencatatPerubahan::') && preg_match('/transException\(\s*true\s*\)/', (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $isi)) === 1) {
                $salah[] = $rel;
            }
        }

        $this->assertSame([], $salah, 'transException(true) tidak boleh dipakai di jalur penulis (log bersavepoint tidak bisa menyelamatkan transaksi).');
    }

    public function testPengecualianMasihAda(): void
    {
        foreach ([...array_keys(self::PENGECUALIAN_CH), ...array_keys(self::PENGECUALIAN_UPDATE), ...self::DINAMIS_CH, ...self::DINAMIS_UPDATE] as $rel) {
            $this->assertFileExists(APPPATH . $rel, 'pengecualian/penulis dinamis basi: ' . $rel);
        }
    }
}
