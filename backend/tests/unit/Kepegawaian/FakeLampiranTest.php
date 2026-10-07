<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\Kepegawaian\FakeAttachmentService;
use Tests\Support\Kepegawaian\FakeStorageAdapter;

/**
 * S0-A (MAKE-002) — fake lampiran untuk test WS mengikuti kontrak AttachmentServiceInterface/StorageAdapterInterface:
 * ekstensi dari isi berkas, batas MB, 422 `errors.berkas`, lampiran terikat NIP (404 lintas NIP).
 *
 * @internal
 */
final class FakeLampiranTest extends CIUnitTestCase
{
    private const NIP = '199001012015011001';

    private const NIP_LAIN = '199001012015011002';

    /**
     * @var list<string>
     */
    private array $berkasSementara = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->berkasSementara);
        $this->berkasSementara = [];

        parent::tearDown();
    }

    public function testSimpanAmbilDaftarHapusTerikatNip(): void
    {
        $fake   = new FakeAttachmentService();
        $aturan = new AturanLampiran(14, true, 5, ['pdf']);

        $baris = $fake->simpan(self::NIP, 14, 7, $this->unggah("%PDF-1.4\n%uji\n", 'ijazah.PDF'), $aturan);

        $this->assertSame(self::NIP, $baris['NIP']);
        $this->assertSame('7', $baris['id_entri']);
        $this->assertSame('pdf', $baris['file_ext']);
        $this->assertSame('ijazah.PDF', $baris['display_name']);
        $this->assertNotSame('ijazah.PDF', $baris['basename'], 'nama simpan dibangkitkan, bukan nama klien');

        $this->assertCount(1, $fake->daftar(self::NIP, 14, 7));
        $this->assertSame([], $fake->daftar(self::NIP_LAIN, 14, 7));

        $ambil = $fake->ambil(self::NIP, $baris['id_attachment']);
        $this->assertSame($baris, $ambil['lampiran']);
        $this->assertStringStartsWith('%PDF', $ambil['isi']);

        foreach (['ambil', 'hapus'] as $method) {
            try {
                $fake->{$method}(self::NIP_LAIN, $baris['id_attachment']);
                $this->fail("{$method} lintas NIP harus 404");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $fake->hapus(self::NIP, $baris['id_attachment']);
        $this->assertSame([], $fake->daftar(self::NIP, 14, 7));

        $this->expectException(NotFoundException::class);
        $fake->ambil(self::NIP, $baris['id_attachment']);
    }

    public function testEkstensiDariIsiBerkas(): void
    {
        $fake = new FakeAttachmentService();

        try {
            // Nama berakhiran .pdf tetapi isinya teks biasa.
            $fake->simpan(self::NIP, 14, 1, $this->unggah('bukan pdf', 'palsu.pdf'), new AturanLampiran(14, true, 5, ['pdf']));
            $this->fail('isi bukan PDF harus 422');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertArrayHasKey('berkas', $e->getErrors() ?? []);
        }
    }

    public function testBatasUkuran(): void
    {
        $fake  = new FakeAttachmentService();
        $besar = $this->unggah("%PDF-1.4\n", 'besar.pdf', 2 * 1024 * 1024 + 1);

        try {
            $fake->simpan(self::NIP, 14, 1, $besar, new AturanLampiran(14, true, 2, ['pdf']));
            $this->fail('melebihi 2 MB harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('berkas', $e->getErrors() ?? []);
        }

        $this->assertSame('pdf', $fake->simpan(self::NIP, 14, 1, $besar, new AturanLampiran(14, true, 5, ['pdf']))['file_ext']);
    }

    public function testFakeStorageAdapter(): void
    {
        $storage = new FakeStorageAdapter();

        $this->assertFalse($storage->ada('a/b.pdf'));
        $storage->simpan('a/b.pdf', 'isi');
        $this->assertTrue($storage->ada('a/b.pdf'));
        $this->assertSame('isi', $storage->baca('a/b.pdf'));

        $storage->hapus('a/b.pdf');
        $storage->hapus('a/b.pdf'); // tidak error bila sudah tidak ada
        $this->assertFalse($storage->ada('a/b.pdf'));

        $this->expectException(RuntimeException::class);
        $storage->baca('a/b.pdf');
    }

    private function unggah(string $isi, string $nama, ?int $ukuran = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lampiran') ?: $this->fail('tempnam gagal');
        file_put_contents($path, $isi);
        $this->berkasSementara[] = $path;

        return new UploadedFile($path, $nama, null, $ukuran ?? strlen($isi), UPLOAD_ERR_OK);
    }
}
