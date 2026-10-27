<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Lampiran;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/**
 * Fase 5A (PRD 5A §7.2.2, aditif): `ValidasiBerkas::periksaImpor()` untuk berkas impor Excel Slip Gaji — aturan sama
 * dengan lampiran (MIME dari isi, ekstensi ≙ isi, tanpa ekstensi ditolak, struktur OOXML `xl/` / OLE, ukuran dari disk)
 * dengan pesan impor PRD. Perilaku `periksa()` lampiran dijaga test lampiran yang sudah ada.
 *
 * @internal
 */
final class ValidasiBerkasImporTest extends CIUnitTestCase
{
    private const FORMAT = 'Format berkas harus .xlsx atau .xls.';
    private const UKURAN = 'Ukuran berkas maksimal 5 MB.';
    private const ISI    = 'Isi berkas tidak sesuai dengan ekstensinya.';

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

    public function testXlsxDanXlsAsliDiterima(): void
    {
        $this->assertSame('xlsx', $this->periksa($this->excel('Xlsx'), 'gaji-sept.xlsx')['ekstensi']);
        $this->assertSame('xls', $this->periksa($this->excel('Xls'), 'gaji-sept.xls')['ekstensi']);
        $this->assertSame('xlsx', $this->periksa($this->excel('Xlsx'), 'GAJI.XLSX')['ekstensi'], 'ekstensi huruf besar');
    }

    public function testFormatSelainExcelDitolak(): void
    {
        $this->assertDitolak("%PDF-1.4\n%%EOF", 'gaji.pdf', self::FORMAT);
        $this->assertDitolak("nip,bulan\n1,2\n", 'gaji.csv', self::FORMAT);
    }

    public function testIsiTidakSesuaiEkstensi(): void
    {
        $this->assertDitolak("%PDF-1.4\n%%EOF", 'gaji.xlsx', self::FORMAT, 'PDF bukan jenis yang diizinkan');
        $this->assertDitolak($this->excel('Xls'), 'gaji.xlsx', self::ISI, 'OLE bernama .xlsx');
        $this->assertDitolak($this->excel('Xlsx'), 'gaji.xls', self::ISI, 'OOXML bernama .xls');
        $this->assertDitolak($this->excel('Xlsx'), 'gaji', self::ISI, 'tanpa ekstensi');
    }

    public function testUkuranDariDisk(): void
    {
        $isi = $this->excel('Xlsx');

        try {
            ValidasiBerkas::periksaImpor($this->unggah($isi, 'gaji.xlsx'), ['xlsx', 'xls'], strlen($isi) - 1, self::FORMAT, self::UKURAN);
            $this->fail('melebihi batas harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(['berkas' => [self::UKURAN]], $e->getErrors());
        }
    }

    /**
     * @return array{isi: string, ekstensi: string, mime: string, ukuran: int, nama_tampil: string}
     */
    private function periksa(string $isi, string $nama): array
    {
        return ValidasiBerkas::periksaImpor($this->unggah($isi, $nama), ['xlsx', 'xls'], 5 * 1024 * 1024, self::FORMAT, self::UKURAN);
    }

    private function assertDitolak(string $isi, string $nama, string $pesan, string $label = ''): void
    {
        try {
            $this->periksa($isi, $nama);
            $this->fail("seharusnya ditolak: {$label}");
        } catch (ValidationException $e) {
            $this->assertSame(['berkas' => [$pesan]], $e->getErrors(), $label);
        }
    }

    private function excel(string $format): string
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->setTitle('GPP')->setCellValue('A1', 'nip');
        $path = $this->temp();
        IOFactory::createWriter($buku, $format)->save($path);
        $buku->disconnectWorksheets();

        return (string) file_get_contents($path);
    }

    private function unggah(string $isi, string $nama): UploadedFile
    {
        $path = $this->temp();
        file_put_contents($path, $isi);

        return new UploadedFile($path, $nama, 'application/octet-stream', strlen($isi), UPLOAD_ERR_OK);
    }

    private function temp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'impor');

        if ($path === false) {
            throw new RuntimeException('Berkas sementara gagal dibuat.');
        }

        $this->sementara[] = $path;

        return $path;
    }
}
