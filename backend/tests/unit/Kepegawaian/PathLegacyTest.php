<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian;

use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * MAKE-069 K-M3 opsi A — path legacy di DB dibaca apa adanya dari storage v2; normalisasi mengikuti `fixArsipPath`
 * legacy dan menolak semua yang bisa keluar dari akar (traversal, absolut, ter-encode, byte kontrol, symlink).
 *
 * @internal
 */
final class PathLegacyTest extends CIUnitTestCase
{
    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = WRITEPATH . 'uji_path_legacy_' . bin2hex(random_bytes(4));
        mkdir($this->akar . '/arsip/rw jabatan', 0750, true);
        file_put_contents($this->akar . '/arsip/rw jabatan/SK 01.pdf', 'isi-sk');
    }

    protected function tearDown(): void
    {
        foreach (['/arsip/rw jabatan/SK 01.pdf', '/arsip/tautan'] as $f) {
            if (is_link($this->akar . $f) || is_file($this->akar . $f)) {
                @unlink($this->akar . $f);
            }
        }

        @rmdir($this->akar . '/arsip/rw jabatan');
        @rmdir($this->akar . '/arsip');
        @rmdir($this->akar);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sah(): iterable
    {
        yield 'awalan /' => ['/arsip/rw jabatan/SK 01.pdf', 'arsip/rw jabatan/SK 01.pdf'];
        yield 'backslash' => ['arsip\\rw jabatan\\SK 01.pdf', 'arsip/rw jabatan/SK 01.pdf'];
        yield 'titik-garis' => ['./arsip/./rw jabatan//SK 01.pdf', 'arsip/rw jabatan/SK 01.pdf'];
        yield 'aplikasi' => ['assets/upload/foto/a.jpg', 'assets/upload/foto/a.jpg'];
        // `//host` tanpa skema = path relatif biasa, selalu di bawah akar / alamat dasar.
        yield 'garis ganda di awal' => ['//host-lain/x.pdf', 'host-lain/x.pdf'];
    }

    #[DataProvider('sah')]
    public function testNormalisasiSah(string $mentah, string $hasil): void
    {
        $this->assertSame($hasil, PathLegacy::normalisasi($mentah));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tidakSah(): iterable
    {
        yield 'traversal' => ['arsip/../../etc/passwd'];
        yield 'traversal backslash' => ['arsip\\..\\rahasia'];
        yield 'ter-encode' => ['arsip/%2e%2e/rahasia'];
        yield 'skema' => ['http://host-lain/x.pdf'];
        yield 'titik dua di tengah' => ['arsip/a:b.pdf'];
        yield 'UTF-8 tidak sah' => ["arsip/\xff\x00.pdf"];
        yield 'UTF-8 tidak sah tanpa NUL' => ["arsip/\xc3\x28.pdf"];
        yield 'U+2028' => ["arsip/a\u{2028}b.pdf"];
        yield 'NBSP' => ["arsip/a\u{00A0}b.pdf"];
        yield 'menjadi kosong' => ['./'];
        yield 'huruf drive' => ['C:/Windows/win.ini'];
        yield 'byte kontrol' => ["arsip/a\0.pdf"];
        yield 'baris baru' => ["arsip/a\n.pdf"];
        yield 'zero width' => ["arsip/a\u{200B}.pdf"];
        yield 'kosong' => ['  '];
        yield 'titik saja' => ['arsip/.../x'];
    }

    #[DataProvider('tidakSah')]
    public function testNormalisasiMenolak(string $mentah): void
    {
        $this->assertNull(PathLegacy::normalisasi($mentah));
    }

    public function testSumberDariAwalanPath(): void
    {
        $this->assertSame('arsip', PathLegacy::sumber('arsip/a.pdf'));
        $this->assertSame('aplikasi', PathLegacy::sumber('assets/upload/a.pdf'));
    }

    public function testAdapterMembacaPathLegacyTetapiMenulisKetat(): void
    {
        $adapter = new LocalStorageAdapter($this->akar);

        $this->assertTrue($adapter->ada('/arsip/rw jabatan/SK 01.pdf'));
        $this->assertSame('isi-sk', $adapter->baca('arsip\\rw jabatan\\SK 01.pdf'));
        $this->assertFalse($adapter->ada('/arsip/rw jabatan/tidak-ada.pdf'));

        $this->expectException(InvalidArgumentException::class);
        $adapter->simpan('/arsip/rw jabatan/baru.pdf', 'x');
    }

    public function testAdapterMenolakTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LocalStorageAdapter($this->akar))->baca('arsip/../../rahasia.txt');
    }

    public function testAdapterMenolakSymlinkKeLuarAkar(): void
    {
        $luar = WRITEPATH . 'uji_path_legacy_luar.txt';
        file_put_contents($luar, 'rahasia');

        try {
            if (! @symlink($luar, $this->akar . '/arsip/tautan')) {
                $this->markTestSkipped('OS uji tidak mengizinkan symlink.');
            }

            $adapter = new LocalStorageAdapter($this->akar);
            $this->assertFalse($adapter->ada('/arsip/tautan'));
            $this->expectException(RuntimeException::class);
            $adapter->baca('/arsip/tautan');
        } finally {
            @unlink($luar);
        }
    }
}
