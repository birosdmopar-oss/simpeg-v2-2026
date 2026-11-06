<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Presensi\DirPratinjauUji;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * Regresi CR-108: pembersih test impor Slip Gaji tidak boleh menyapu direktori kerja.
 *
 * Dulu `bersihkanImporSlip()` menjalankan `glob('' . '*')` bila setUp gagal sebelum `siapkanImporSlip()` (mis. DB scratch
 * dipakai dua proses PHPUnit), sehingga berkas root `backend/` (composer.json, spark, phpunit.dist.xml, ...) terhapus.
 *
 * Seluruh test — termasuk tearDown() — berjalan dengan cwd = folder kanari milik test di temp sistem; cwd asli baru
 * dikembalikan sesudah pembersihan terakhir. Jadi bila regresi terjadi lagi, yang terhapus hanya kanari, bukan berkas
 * repo. Tanpa database.
 *
 * @internal
 */
final class ImporSlipUjiPembersihTest extends CIUnitTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;

    private const AWALAN_KANARI = 'kanari-cwd-';
    private const KANARI        = ['composer.json', 'spark', 'phpunit.dist.xml', 'env'];

    /**
     * Folder kanari (cwd selama test); null = belum dibuat.
     */
    private ?string $kanari = null;

    private ?string $cwdAsli = null;

    protected function setUp(): void
    {
        parent::setUp();

        $asli = getcwd();
        $dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::AWALAN_KANARI . bin2hex(random_bytes(4));

        if ($asli === false || ! mkdir($dir)) {
            throw new RuntimeException('Folder kanari gagal disiapkan.');
        }

        $this->kanari = $dir;

        foreach (self::KANARI as $nama) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $nama, 'kanari');
        }

        if (! chdir($dir)) {
            throw new RuntimeException('Gagal pindah ke folder kanari.');
        }

        $this->cwdAsli = $asli;
    }

    protected function tearDown(): void
    {
        try {
            // Masih di cwd kanari: pembersih versi rusak pun hanya bisa mengenai kanari.
            $this->bersihkanImporSlip();
        } finally {
            if ($this->cwdAsli !== null) {
                chdir($this->cwdAsli);
                $this->cwdAsli = null;
            }

            $this->hapusKanari();
            parent::tearDown();
        }
    }

    public function testSetUpGagalSebelumSiapkanTidakMenghapusBerkasDiCwd(): void
    {
        $this->assertSame(realpath((string) $this->kanari), realpath((string) getcwd()));

        // Simulasi tearDown sesudah setUp gagal: siapkanImporSlip() tidak pernah berjalan.
        $this->bersihkanImporSlip();

        $this->assertSame([], $this->isiDirPratinjau());
        $this->assertKanariUtuh();
    }

    public function testHelperMenolakDirKosongNullAtauCwd(): void
    {
        DirPratinjauUji::bersihkan(null, []);
        DirPratinjauUji::bersihkan('', ['', '.']);
        DirPratinjauUji::bersihkan('.', []);
        DirPratinjauUji::bersihkan('./', ['composer.json', 'spark']);

        $this->assertKanariUtuh();
        $this->assertSame([], DirPratinjauUji::isi(null));
        $this->assertSame([], DirPratinjauUji::isi(''));
        $this->assertSame([], DirPratinjauUji::isi('.'));
    }

    public function testDirTanpaAwalanAtauTidakLangsungDiTempDilewati(): void
    {
        $kanari = (string) $this->kanari;

        // Langsung di temp tetapi tanpa awalan slip-impor-uji-.
        DirPratinjauUji::bersihkan($kanari, []);
        $this->assertKanariUtuh();

        // Berawalan slip-impor-uji- tetapi tidak langsung di temp (bersarang di folder kanari).
        $bersarang = $kanari . DIRECTORY_SEPARATOR . DirPratinjauUji::AWALAN_DIR . 'bersarang';
        mkdir($bersarang);
        file_put_contents($bersarang . DIRECTORY_SEPARATOR . 'a.json', '{}');

        DirPratinjauUji::bersihkan($bersarang, [$kanari . DIRECTORY_SEPARATOR . 'composer.json']);

        $this->assertFileExists($bersarang . DIRECTORY_SEPARATOR . 'a.json');
        $this->assertKanariUtuh();
    }

    public function testBerkasSementaraDiLuarPolaDilewati(): void
    {
        $kanari = (string) $this->kanari;

        // Berawalan slipuji tetapi tidak langsung di temp.
        $bersarang = $kanari . DIRECTORY_SEPARATOR . DirPratinjauUji::AWALAN_BERKAS . 'x.xlsx';
        file_put_contents($bersarang, 'x');

        // Langsung di temp tetapi tanpa awalan slipuji.
        $tanpaAwalan = $kanari . '.lepas';
        file_put_contents($tanpaAwalan, 'x');

        try {
            DirPratinjauUji::bersihkan(null, [$bersarang, $tanpaAwalan, 'spark', $kanari . DIRECTORY_SEPARATOR . 'env']);

            $this->assertFileExists($bersarang);
            $this->assertFileExists($tanpaAwalan);
            $this->assertKanariUtuh();
        } finally {
            @unlink($tanpaAwalan);
        }
    }

    public function testDirPratinjauSahDanBerkasSementaraDibersihkan(): void
    {
        $this->siapkanImporSlip();

        $dir = config(Presensi::class)->direktoriPratinjau;
        mkdir($dir);
        file_put_contents($dir . 'pratinjau.json', '{}');
        file_put_contents($dir . 'pratinjau.xlsx', 'x');

        $sementara = $this->berkasMentah('isi', 'slip.xlsx')->getTempName();

        $this->assertSame(['pratinjau.json', 'pratinjau.xlsx'], $this->isiDirPratinjau());
        $this->assertFileExists($sementara);

        $this->bersihkanImporSlip();

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFileDoesNotExist($sementara);
        $this->assertSame(WRITEPATH . 'uploads/slip-impor/', config(Presensi::class)->direktoriPratinjau);
        $this->assertKanariUtuh();
    }

    private function assertKanariUtuh(): void
    {
        foreach (self::KANARI as $nama) {
            $this->assertFileExists((string) $this->kanari . DIRECTORY_SEPARATOR . $nama, "kanari {$nama} tidak boleh terhapus");
        }
    }

    /**
     * Hapus folder kanari milik test ini saja (path disimpan saat dibuat; awalan diperiksa ulang).
     */
    private function hapusKanari(): void
    {
        $dir          = $this->kanari;
        $this->kanari = null;

        if ($dir === null || ! is_dir($dir) || ! str_starts_with(basename($dir), self::AWALAN_KANARI)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
