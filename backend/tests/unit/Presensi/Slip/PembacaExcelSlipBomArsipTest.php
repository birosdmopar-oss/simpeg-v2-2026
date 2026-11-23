<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\Slip\PemindaiXlsx;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Presensi\XlsxMentah;

/**
 * CR-188 — ukuran isi arsip diperiksa SEBELUM reader vendor menyentuh berkas. Mengenali jenis berkas saja
 * (`IOFactory::createReaderForFile` → `Xlsx::canRead`) sudah mendekompresi `_rels/.rels` utuh ke memori, dan
 * `listWorksheetNames` menampung `[Content_Types].xml`, relasi workbook, dan `xl/workbook.xml`; semuanya sebelum
 * `PemindaiXlsx::pindai`. Berkas < 1 MB dengan salah satu entri itu mengembang ratusan MB dulu berakhir fatal memori
 * (500, tidak tertangkap), bukan 422.
 *
 * Berkas dibaca di proses PHP tersendiri (`tests/_support/Presensi/anak_baca_slip.php`) dengan `memory_limit` 512M (syarat
 * deploy) dan 256M, supaya galat fatal terlihat sebagai kegagalan test, bukan mematikan PHPUnit.
 *
 * @internal
 */
final class PembacaExcelSlipBomArsipTest extends CIUnitTestCase
{
    private const NIP          = '199001012020011001';
    private const PESAN_ISI    = 'Isi berkas terlalu besar sesudah dibuka (maksimal 32 MB).';
    private const MB           = 1024 * 1024;
    private const BATAS_PUNCAK = 48 * self::MB;
    private const BATAS_DETIK  = 10.0;
    private const BATAS_MEMORI = ['512M', '256M'];

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

    /**
     * Entri yang ditampung utuh sebelum pemindai berjalan, mengembang jauh di atas `memory_limit`: 422 dengan pesan batas
     * isi, cepat, dan puncak memori kecil. Isi entri tetap XML yang sah (isi asli + spasi), jadi satu-satunya yang tidak
     * wajar adalah ukurannya.
     */
    #[DataProvider('entriBom')]
    public function testEntriMengembangDitolakSebelumReaderDibuka(string $entri, int $mb): void
    {
        $path = $this->temp('.xlsx');
        XlsxMentah::tulisMengembang($path, self::entriSah(), $entri, $mb * self::MB);

        $this->assertLessThan(5 * self::MB, filesize($path), 'berkas di bawah batas unggah 5 MB');

        foreach (self::BATAS_MEMORI as $batas) {
            $awal  = microtime(true);
            $hasil = $this->bacaDiProsesAnak($path, $batas);

            $this->assertSame('DITOLAK', $hasil['status'], $entri . ' pada memory_limit ' . $batas);
            $this->assertSame(self::PESAN_ISI, $hasil['pesan']);
            $this->assertLessThan(self::BATAS_PUNCAK, $hasil['puncak'], 'puncak memori pada memory_limit ' . $batas);
            $this->assertLessThan(self::BATAS_DETIK, microtime(true) - $awal);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function entriBom(): iterable
    {
        yield '_rels/.rels 700 MB (dibaca Xlsx::canRead)' => ['_rels/.rels', 700];
        yield '_rels/.rels 300 MB (di bawah memory_limit 512M, tetap tidak boleh ditampung)' => ['_rels/.rels', 300];
        yield '[Content_Types].xml 300 MB' => ['[Content_Types].xml', 300];
        yield 'xl/_rels/workbook.xml.rels 300 MB' => ['xl/_rels/workbook.xml.rels', 300];
        yield 'xl/workbook.xml 300 MB (listWorksheetNames)' => ['xl/workbook.xml', 300];
        yield 'entri di luar yang dibaca reader 300 MB' => ['docProps/app.xml', 300];
    }

    /**
     * Pagar: berkas sah, dan berkas sah yang `_rels/.rels`-nya diberi spasi sampai 1 MB (di bawah batas isi), tetap
     * dibaca lewat jalur proses anak yang sama.
     */
    public function testBerkasWajarTetapDiterimaDiProsesAnak(): void
    {
        $sah = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($sah, self::entriSah());

        $longgar = $this->temp('.xlsx');
        XlsxMentah::tulisMengembang($longgar, self::entriSah(), '_rels/.rels', self::MB);

        foreach ([$sah, $longgar] as $path) {
            foreach (self::BATAS_MEMORI as $batas) {
                $this->assertSame('DITERIMA', $this->bacaDiProsesAnak($path, $batas)['status']);
            }
        }
    }

    /**
     * Batasnya sama dengan batas isi CR-177 dan dihitung dari SEMUA entri: tepat di batas diterima, satu byte di atasnya
     * ditolak dengan pesan yang sama, walau entri yang mengembang bukan bagian workbook.
     */
    public function testBatasMemakaiJumlahSemuaEntri(): void
    {
        $entri  = self::entriSah();
        $jumlah = array_sum(array_map(strlen(...), $entri));
        $path   = $this->temp('.xlsx');
        XlsxMentah::tulisMengembang($path, $entri, 'docProps/app.xml', 4096);

        $this->assertSame($jumlah + 4096, PemindaiXlsx::ukuranTerdekompresi($path));
        $this->assertTrue((new PembacaExcelSlip(10000, maksByteIsi: $jumlah + 4096))->baca($path)->valid);

        try {
            (new PembacaExcelSlip(10000, maksByteIsi: $jumlah + 4095))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame('Isi berkas terlalu besar sesudah dibuka (maksimal 0 MB).', $e->getMessage());
        }
    }

    /**
     * Berkas yang bukan arsip ZIP tidak diperiksa di sini: `.xls` biner dibaca seperti sebelumnya, dan berkas yang bukan
     * Excel tetap mendapat pesan "tidak dapat dibaca".
     */
    public function testBerkasBukanZipBerjalanSepertiSebelumnya(): void
    {
        $buku = new Spreadsheet();
        $gpp  = $buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP);
        $gpp->fromArray([self::headerGpp(), self::dataGpp()], null, 'A1', true);
        $gpp->setCellValueExplicit('A2', self::NIP, 's');
        $tk = $buku->createSheet()->setTitle(KolomGaji::SHEET_TK);
        $tk->fromArray([self::headerTk(), self::dataTk()], null, 'A1', true);
        $tk->setCellValueExplicit('A2', self::NIP, 's');

        $xls = $this->temp('.xls');
        IOFactory::createWriter($buku, 'Xls')->save($xls);
        $buku->disconnectWorksheets();

        $this->assertNull(PemindaiXlsx::ukuranTerdekompresi($xls));
        $this->assertTrue((new PembacaExcelSlip(10000))->baca($xls)->valid);

        $teks = $this->temp('.xlsx');
        file_put_contents($teks, 'bukan berkas excel');

        $this->assertNull(PemindaiXlsx::ukuranTerdekompresi($teks));

        try {
            (new PembacaExcelSlip(10000))->baca($teks);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertStringContainsString('tidak dapat dibaca', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @return array{status: string, pesan: string, puncak: int}
     */
    private function bacaDiProsesAnak(string $path, string $batasMemori): array
    {
        $perintah = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $batasMemori . ' -d display_errors=1 '
            . escapeshellarg(SUPPORTPATH . 'Presensi/anak_baca_slip.php') . ' ' . escapeshellarg($path) . ' 2>&1';
        $baris = [];
        $kode  = 0;
        exec($perintah, $baris, $kode);

        $hasil = json_decode((string) end($baris), true);

        if ($kode !== 0 || ! is_array($hasil) || ! isset($hasil['status'], $hasil['pesan'], $hasil['puncak'])) {
            $this->fail('proses anak (memory_limit ' . $batasMemori . ') tidak selesai: ' . mb_substr(implode(' | ', $baris), 0, 300));
        }

        return ['status' => (string) $hasil['status'], 'pesan' => (string) $hasil['pesan'], 'puncak' => (int) $hasil['puncak']];
    }

    /**
     * @return array<string, string>
     */
    private static function entriSah(): array
    {
        return XlsxMentah::entri([
            'GPP' => XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()),
            'TK'  => XlsxMentah::baris(1, self::headerTk()) . XlsxMentah::baris(2, self::dataTk()),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function headerGpp(): array
    {
        return ['nip', 'bulan', 'tahun', 'nama', ...array_keys(KolomGaji::SUMBER_GPP)];
    }

    /**
     * @return list<int|string>
     */
    private static function dataGpp(): array
    {
        return [self::NIP, 9, 2026, 'Budi Uji', ...array_fill(0, count(KolomGaji::SUMBER_GPP), 0)];
    }

    /**
     * @return list<string>
     */
    private static function headerTk(): array
    {
        return ['nip', 'bulan', 'tahun', ...array_keys(KolomGaji::SUMBER_TK)];
    }

    /**
     * @return list<int|string>
     */
    private static function dataTk(): array
    {
        return [self::NIP, 9, 2026, ...array_fill(0, count(KolomGaji::SUMBER_TK), 0)];
    }

    private function temp(string $ekstensi): string
    {
        $dasar = tempnam(sys_get_temp_dir(), 'slip');

        if ($dasar === false) {
            throw new RuntimeException('Berkas sementara gagal dibuat.');
        }

        @unlink($dasar);
        $path              = $dasar . $ekstensi;
        $this->sementara[] = $path;

        return $path;
    }
}
