<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Lampiran;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use ZipArchive;

/**
 * Struktur isi dokumen Office di ValidasiBerkas (MAKE-010, catatan sisa MAKE-009): ZIP biasa atau berkas non-OOXML
 * bernama `.docx`/`.xlsx` ditolak walau Config\Mimes memetakan `application/zip` ke kedua ekstensi itu.
 *
 * @internal
 */
final class ValidasiBerkasOfficeTest extends CIUnitTestCase
{
    /**
     * @var list<string>
     */
    private array $sementara = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Ekstensi zip PHP tidak tersedia.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sementara as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testDocxAsliDiterima(): void
    {
        $hasil = ValidasiBerkas::periksa($this->unggah($this->ooxml('word'), 'surat.docx'), $this->aturan(['docx']));

        $this->assertSame('docx', $hasil['ekstensi']);
    }

    public function testXlsxAsliDiterima(): void
    {
        $hasil = ValidasiBerkas::periksa($this->unggah($this->ooxml('excel'), 'rekap.xlsx'), $this->aturan(['xlsx']));

        $this->assertSame('xlsx', $hasil['ekstensi']);
    }

    public function testZipBiasaBernamaDocxDitolak(): void
    {
        $this->assertDitolak($this->unggah($this->zip(['catatan.txt' => 'bukan dokumen']), 'surat.docx'), $this->aturan(['docx']));
    }

    public function testZipBiasaBernamaXlsxDitolak(): void
    {
        $this->assertDitolak($this->unggah($this->zip(['catatan.txt' => 'bukan dokumen']), 'rekap.xlsx'), $this->aturan(['xlsx']));
    }

    public function testDokumenWordBernamaXlsxDitolak(): void
    {
        // Folder aplikasi harus milik jenis ekstensinya: dokumen Word (word/) tidak lolos sebagai xlsx.
        $this->assertDitolak($this->unggah($this->ooxml('word'), 'rekap.xlsx'), $this->aturan(['xlsx']));
    }

    public function testZipDenganContentTypesTanpaBagianAplikasiDitolak(): void
    {
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/></Types>';

        $this->assertDitolak($this->unggah($this->zip(['[Content_Types].xml' => $types]), 'surat.docx'), $this->aturan(['docx']));
    }

    public function testFolderAplikasiTanpaContentTypesDitolak(): void
    {
        $this->assertDitolak($this->unggah($this->zip(['word/document.xml' => '<w:document/>']), 'surat.docx'), $this->aturan(['docx']));
    }

    public function testDokumenOleBernamaDocxDitolak(): void
    {
        // Tanda tangan OLE Compound File (Word/Excel lama) + isi sembarang: bukan paket OOXML.
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 504) . str_repeat('A', 1024);

        $this->assertDitolak($this->unggah($ole, 'surat.docx'), $this->aturan(['docx']));
        $this->assertDitolak($this->unggah($ole, 'rekap.xlsx'), $this->aturan(['xlsx']));
    }

    public function testPdfTetapDiterimaTanpaPemeriksaanOffice(): void
    {
        $pdf   = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $hasil = ValidasiBerkas::periksa($this->unggah($pdf, 'sk.pdf'), $this->aturan(['pdf', 'docx']));

        $this->assertSame('pdf', $hasil['ekstensi']);
    }

    /**
     * @param list<string> $ekstensi
     */
    private function aturan(array $ekstensi): AturanLampiran
    {
        return new AturanLampiran(11, false, 5, $ekstensi);
    }

    private function assertDitolak(UploadedFile $berkas, AturanLampiran $aturan): void
    {
        try {
            ValidasiBerkas::periksa($berkas, $aturan);
            $this->fail('Berkas seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('berkas', $e->getErrors());
        }
    }

    /**
     * Isi OOXML minimal: `[Content_Types].xml` + bagian utama Word (`word/`) atau Excel (`xl/`).
     */
    private function ooxml(string $jenis): string
    {
        $utama = $jenis === 'word'
            ? ['/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml']
            : ['/xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'];

        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="' . $utama[0] . '" ContentType="' . $utama[1] . '"/></Types>';

        return $this->zip([
            '[Content_Types].xml' => $types,
            ltrim($utama[0], '/') => $jenis === 'word' ? '<w:document/>' : '<workbook/>',
        ]);
    }

    /**
     * @param array<string, string> $entri
     */
    private function zip(array $entri): string
    {
        $path = $this->tempPath();
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP uji gagal dibuat.');
        }

        foreach ($entri as $nama => $isi) {
            $zip->addFromString($nama, $isi);
        }

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
        $path = tempnam(sys_get_temp_dir(), 'office');

        if ($path === false) {
            throw new RuntimeException('Berkas sementara gagal dibuat.');
        }

        $this->sementara[] = $path;

        return $path;
    }
}
