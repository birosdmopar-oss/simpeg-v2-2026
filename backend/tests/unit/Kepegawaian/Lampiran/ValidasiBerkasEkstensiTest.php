<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Lampiran;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use ZipArchive;

/**
 * CR-054 (QA R2-I4, keputusan user 16-10-2026: tolak, ikut legacy Upload CI3 `allowed_types`): ekstensi nama kiriman
 * wajib setara dengan jenis hasil deteksi isi. Pasangan setara menurut Config\Mimes (jpg/jpeg/jpe, xlsx/docx yang
 * terdeteksi zip, csv sebagai teks) tetap diterima.
 *
 * @internal
 */
final class ValidasiBerkasEkstensiTest extends CIUnitTestCase
{
    private const PESAN = 'Jenis isi berkas tidak sesuai dengan ekstensinya.';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    /**
     * @var list<string>
     */
    private array $sementara = [];

    protected function tearDown(): void
    {
        foreach ($this->sementara as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testPdfBernamaXlsxDitolak(): void
    {
        $this->assertDitolak($this->unggah(self::PDF, 'rekap.xlsx'), ['pdf', 'xlsx'], self::PESAN);
    }

    public function testPngBernamaJpgDitolak(): void
    {
        $this->assertDitolak($this->unggah($this->png(), 'foto.jpg'), ['jpg', 'png'], self::PESAN);
    }

    public function testJpegBernamaPdfDitolak(): void
    {
        $this->assertDitolak($this->unggah($this->jpeg(), 'sk.pdf'), ['pdf', 'jpg'], self::PESAN);
    }

    public function testDokumenWordBernamaPdfDitolak(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Ekstensi zip PHP tidak tersedia.');
        }

        $this->assertDitolak($this->unggah($this->docx(), 'sk.pdf'), ['pdf', 'docx']);
    }

    /**
     * Pasangan setara: ekstensi kiriman yang memang terdaftar untuk MIME isi tetap diterima apa adanya.
     *
     * @return iterable<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    public static function pasanganSah(): iterable
    {
        yield 'jpg' => ['jpeg', 'foto.jpg', ['jpg', 'jpeg'], 'jpg'];
        yield 'jpeg' => ['jpeg', 'foto.jpeg', ['jpg', 'jpeg'], 'jpeg'];
        yield 'JPG huruf besar' => ['jpeg', 'FOTO.JPG', ['jpg'], 'jpg'];
        yield 'pdf' => ['pdf', 'sk.pdf', ['pdf'], 'pdf'];
        yield 'PDF huruf besar' => ['pdf', 'SK.PDF', ['pdf'], 'pdf'];
        yield 'png' => ['png', 'foto.png', ['png', 'jpg'], 'png'];
        yield 'csv teks' => ['csv', 'data.csv', ['csv'], 'csv'];
    }

    /**
     * @param list<string> $ekstensi
     */
    #[DataProvider('pasanganSah')]
    public function testPasanganSahDiterima(string $isi, string $nama, array $ekstensi, string $harapan): void
    {
        $hasil = ValidasiBerkas::periksa($this->unggah($this->isi($isi), $nama), $this->aturan($ekstensi));

        $this->assertSame($harapan, $hasil['ekstensi']);
        $this->assertSame($nama, $hasil['nama_tampil']);
    }

    public function testOoxmlTerdeteksiZipTetapDiterimaSesuaiEkstensi(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Ekstensi zip PHP tidak tersedia.');
        }

        $this->assertSame('docx', ValidasiBerkas::periksa($this->unggah($this->docx(), 'surat.docx'), $this->aturan(['docx', 'xlsx']))['ekstensi']);
        $this->assertSame('xlsx', ValidasiBerkas::periksa($this->unggah($this->xlsx(), 'rekap.xlsx'), $this->aturan(['docx', 'xlsx']))['ekstensi']);
    }

    public function testNamaTanpaEkstensiMengikutiJenisIsi(): void
    {
        $hasil = ValidasiBerkas::periksa($this->unggah(self::PDF, 'sk'), $this->aturan(['pdf']));

        $this->assertSame('pdf', $hasil['ekstensi']);
        $this->assertSame('sk.pdf', $hasil['nama_tampil'], 'nama tampil diberi ekstensi jenis isi');
    }

    /**
     * @param list<string> $ekstensi
     */
    private function assertDitolak(UploadedFile $berkas, array $ekstensi, ?string $pesan = null): void
    {
        try {
            ValidasiBerkas::periksa($berkas, $this->aturan($ekstensi));
            $this->fail('Berkas seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertArrayHasKey('berkas', $e->getErrors());

            if ($pesan !== null) {
                $this->assertSame([$pesan], $e->getErrors()['berkas']);
            }
        }
    }

    /**
     * @param list<string> $ekstensi
     */
    private function aturan(array $ekstensi): AturanLampiran
    {
        return new AturanLampiran(11, false, 5, $ekstensi);
    }

    private function isi(string $jenis): string
    {
        return match ($jenis) {
            'jpeg'  => $this->jpeg(),
            'png'   => $this->png(),
            'csv'   => "nip,nama\n199001012015011001,Pegawai Uji\n",
            default => self::PDF,
        };
    }

    private function jpeg(): string
    {
        return self::JPEG . str_repeat("\x00", 100) . "\xFF\xD9";
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    private function docx(): string
    {
        return $this->ooxml('/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', '<w:document/>');
    }

    private function xlsx(): string
    {
        return $this->ooxml('/xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', '<workbook/>');
    }

    private function ooxml(string $bagian, string $tipe, string $isi): string
    {
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="' . $bagian . '" ContentType="' . $tipe . '"/></Types>';

        $path = $this->tempPath();
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP uji gagal dibuat.');
        }

        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString(ltrim($bagian, '/'), $isi);
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function unggah(string $isi, string $nama): UploadedFile
    {
        $path = $this->tempPath();
        file_put_contents($path, $isi);

        return new UploadedFile($path, $nama, 'application/octet-stream', strlen($isi), UPLOAD_ERR_OK);
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ekst');

        if ($path === false) {
            throw new RuntimeException('Berkas sementara gagal dibuat.');
        }

        $this->sementara[] = $path;

        return $path;
    }
}
