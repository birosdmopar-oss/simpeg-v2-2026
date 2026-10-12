<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Lampiran;

use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * MAKE-009 — LocalStorageAdapter: simpan/baca/ada/hapus di bawah akar, hapus idempoten, path tidak bisa keluar akar.
 *
 * @internal
 */
final class LocalStorageAdapterTest extends CIUnitTestCase
{
    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = sys_get_temp_dir() . '/simpeg-lampiran-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::hapusFolder($this->akar);

        parent::tearDown();
    }

    public function testSimpanBacaAdaHapus(): void
    {
        $adapter = new LocalStorageAdapter($this->akar);

        $this->assertFalse($adapter->ada('2026/10/a.pdf'));

        $adapter->simpan('2026/10/a.pdf', "%PDF-1.4\nisi");

        $this->assertTrue($adapter->ada('2026/10/a.pdf'));
        $this->assertSame("%PDF-1.4\nisi", $adapter->baca('2026/10/a.pdf'));
        $this->assertFileExists($this->akar . '/2026/10/a.pdf');
        $this->assertSame([], glob($this->akar . '/2026/10/.unggah-*') ?: [], 'berkas sementara tidak tertinggal');

        $adapter->simpan('2026/10/a.pdf', 'ganti');
        $this->assertSame('ganti', $adapter->baca('2026/10/a.pdf'));

        $adapter->hapus('2026/10/a.pdf');
        $this->assertFalse($adapter->ada('2026/10/a.pdf'));

        // Hapus berkas yang sudah tidak ada tidak error.
        $adapter->hapus('2026/10/a.pdf');
        $this->assertFalse($adapter->ada('2026/10/a.pdf'));
    }

    public function testBacaBerkasTidakAdaMelempar(): void
    {
        $this->expectException(RuntimeException::class);

        (new LocalStorageAdapter($this->akar))->baca('tidak/ada.pdf');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathTidakValidProvider(): array
    {
        return [
            'naik folder'       => ['../luar.pdf'],
            'naik di tengah'    => ['2026/../../luar.pdf'],
            'absolut'           => ['/etc/passwd'],
            'titik'             => ['./a.pdf'],
            'segmen kosong'     => ['2026//a.pdf'],
            'garis miring kiri' => ['2026\\a.pdf'],
            'null byte'         => ["a.pdf\0.txt"],
            'spasi'             => ['nama berkas.pdf'],
            'kosong'            => [''],
        ];
    }

    #[DataProvider('pathTidakValidProvider')]
    public function testPathKeluarAkarDitolak(string $path): void
    {
        $adapter = new LocalStorageAdapter($this->akar);

        foreach ([
            static fn () => $adapter->simpan($path, 'x'),
            static fn () => $adapter->baca($path),
            static fn () => $adapter->ada($path),
            static fn () => $adapter->hapus($path),
        ] as $i => $aksi) {
            try {
                $aksi();
                $this->fail("aksi #{$i} seharusnya menolak path");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDirectoryDoesNotExist($this->akar);
    }

    private static function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
