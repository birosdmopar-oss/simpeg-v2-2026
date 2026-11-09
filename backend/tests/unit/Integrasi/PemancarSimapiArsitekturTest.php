<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Simapi\AturanTrigger;
use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MAKE-060 A5b P2 — test arsitektur penulis log SIMAPI `simapi_ch`: setiap berkas di `app/` yang MENULIS ke salah satu dari 15 tabel
 * sumber trigger legacy ({@see AturanTrigger::tabel()}) wajib memanggil `PencatatPerubahan::` atau tercatat di PENGECUALIAN beralasan,
 * dan tidak boleh memakai `transException(true)` (insert log bersavepoint tidak bisa menyelamatkan transaksi itu). Penulis baru tanpa
 * pemancar → test merah (data perubahan tidak boleh hilang diam-diam).
 *
 * Deteksi tulisan (heuristik statis): `table('<tabel>')…->insert|update|delete|replace|insertBatch|updateBatch(` dalam satu ekspresi,
 * model `PegawaiModel`/`PenggunaModel` yang dipakai menulis, atau `RiwayatModel` (riwayat dinamis). Penulis bertabel dinamis
 * (`$jenis->tabel()`, rencana tarik, registry NIP) terdaftar di PENULIS_DINAMIS dan wajib memanggil pemancar.
 *
 * @internal
 */
final class PemancarSimapiArsitekturTest extends CIUnitTestCase
{
    /**
     * Berkas penulis yang sengaja TIDAK memancar simapi_ch (relatif `app/`), dengan alasan.
     * Catatan: daftar ini khusus log `simapi_ch`; penulis log `simapi_update` (MAKE-076) punya daftarnya sendiri.
     */
    private const PENGECUALIAN = [
        'Libraries/Auth/AuthService.php'                   => 'hanya last_login_at; trigger pengguna legacy menulis simapi_ch hanya bila email berubah',
        'Libraries/Auth/PasswordService.php'               => 'hanya password; idem (simapi_update = MAKE-076)',
        'Libraries/Auth/BootstrapAdmin.php'                => 'akun admin tanpa NIP (CR-093)',
        'Libraries/Auth/PasswordVerifier.php'              => 'hanya rehash password saat login; email tidak berubah (simapi_update = MAKE-076)',
        'Libraries/Auth/ResetPasswordService.php'          => 'hanya reset password; email tidak berubah (simapi_update = MAKE-076)',
        'Libraries/Integrasi/AkunSistemIntegrasi.php'      => 'akun sistem integrasi tanpa NIP (CR-093)',
        'Models/Auth/PenggunaModel.php'                    => 'definisi model, bukan penulis',
        'Models/Kepegawaian/PegawaiModel.php'              => 'definisi model, bukan penulis',
        'Models/Kepegawaian/RiwayatModel.php'              => 'definisi model, bukan penulis (penulis: RiwayatEngine/AnakKeluarga)',
        'Libraries/Integrasi/Simapi/PencatatPerubahan.php' => 'pencatat itu sendiri (membaca)',
    ];

    /** Penulis bertabel dinamis yang wajib memancar. */
    private const PENULIS_DINAMIS = [
        'Libraries/Kepegawaian/Riwayat/RiwayatEngine.php',
        'Libraries/Kepegawaian/Riwayat/AnakKeluarga.php',
        'Libraries/Kepegawaian/NipCascadeService.php',
        'Libraries/Siasn/Tarik/PenulisTarik.php',
        'Libraries/Siasn/Dorong/DorongSiasn.php',
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

    private static function menulis(string $isi): bool
    {
        $tabel = implode('|', array_map('preg_quote', AturanTrigger::tabel()));

        if (preg_match("/table\\(\\s*'(?:{$tabel})'\\s*\\)[^;]*->(?:insert|update|delete|replace|insertBatch|updateBatch)\\(/s", $isi) === 1) {
            return true;
        }

        return preg_match('/new (?:PegawaiModel|PenggunaModel)\(/', $isi) === 1 && preg_match('/->(?:insert|update|delete|save)\(/', $isi) === 1
            || preg_match('/\$this->pengguna->(?:insert|update)\(/', $isi) === 1;
    }

    public function testSetiapPenulisMemancarAtauDikecualikan(): void
    {
        $tanpa = [];

        foreach (self::berkasApp() as $rel => $isi) {
            $penulis = self::menulis($isi) || in_array($rel, self::PENULIS_DINAMIS, true);

            if (! $penulis || isset(self::PENGECUALIAN[$rel])) {
                continue;
            }

            // Penulis baca-saja: hanya `new PegawaiModel(...)` untuk find/where tanpa insert/update pada model itu tetap diperiksa manual.
            if (! str_contains($isi, 'PencatatPerubahan::')) {
                $tanpa[] = $rel;
            }
        }

        $this->assertSame([], $tanpa, 'Penulis 15 tabel sumber tanpa PencatatPerubahan (tambahkan pemancar atau pengecualian beralasan).');
    }

    public function testPenulisDinamisMemancar(): void
    {
        foreach (self::PENULIS_DINAMIS as $rel) {
            $this->assertStringContainsString('PencatatPerubahan::', (string) file_get_contents(APPPATH . $rel), $rel);
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
        foreach (array_keys(self::PENGECUALIAN) as $rel) {
            $this->assertFileExists(APPPATH . $rel, 'pengecualian basi: ' . $rel);
        }
    }
}
