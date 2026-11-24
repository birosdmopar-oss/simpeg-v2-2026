<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use App\Libraries\HaloSimpeg\Chatbot\PembacaQnaXlsx;
use CodeIgniter\HTTP\Files\UploadedFile;
use Composer\InstalledVersions;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\OLE;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\File;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\Root;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;
use ZipArchive;

/**
 * Impor QnA lewat pembaca mandiri {@see PembacaQnaXlsx} (CR-182 R5/R6). Berkas dirakit dari template resmi dengan XML yang
 * diubah; tanpa fixture biner. Mencakup: berkas serangan dari putaran R0-R6 (bom dekompresi, `<col>` tidak sah, baris
 * kosong/acak, sheet raksasa di path lain, rujukan sheet/relasi berulang, prefiks/encoding, rumus shared, UTF-7) yang
 * harus selesai cepat tanpa fatal; paritas nilai terhadap logika main (load() + read filter A-D/E1 + rangeToArray) yang
 * dijalankan di dalam test; dan berkas sah (template, Excel/LibreOffice, banyak sheet, border luas, jawaban panjang).
 *
 * @internal
 */
final class ImporQnaPemindaiTest extends DatabaseTestCase
{
    use ChatbotFixtureTrait;

    private const RUSAK = 'Berkas Excel rusak atau tidak dapat dibaca.';

    /** Pagar waktu longgar (target ±2-3 s di mesin pengembang); asersi utama test serangan adalah memori atau pesan. */
    private const DETIK_CI = 10.0;

    /** Ruang waktu test kasus terburuk teks panjang (terukur ±3,1-3,4 s di mesin pengembang, R2-4). */
    private const DETIK_CI_TERBURUK = 15.0;

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    /**
     * `<col min="5" max="1">`: di main fatal memori (perulangan `ColumnAndRowAttributes` vendor tanpa akhir). Desain R5 tidak
     * memakai `load()`, jadi definisi kolom tidak dibaca sama sekali: berkas diproses biasa (proses anak berbatas memori
     * tetap dipakai supaya kegagalan fatal tidak memutus PHPUnit).
     */
    public function testColMaxKurangDariMinTidakFatal(): void
    {
        $berkas = $this->rakit('<cols><col min="5" max="1" width="10" customWidth="1"/></cols>', '', [], '', true);
        $this->assertLessThan(10_000, (int) filesize($berkas), 'berkas kecil');

        $hasil = $this->jalankanWorker($berkas, '128M');
        $this->assertSame(200, $hasil['status'] ?? null, 'tanpa fatal: ' . json_encode($hasil));
        $this->assertLessThan(64 * 1024 * 1024, $hasil['puncak'], 'puncak memori PHP proses anak');

        if ($hasil['rss'] !== null) {
            $this->assertLessThan(160 * 1024 * 1024, $hasil['rss'], 'puncak RSS proses anak');
        }
    }

    public function testColSelebarLembarDenganBarisAcakCepatDanPesanBaris(): void
    {
        // Baris 3..1.502 diacak lalu baris 3 terakhir: listWorksheetInfo melihat totalRows 3, padahal ada 1.500 baris.
        // Sebelum CR-182 dimuat lambat (readCell per kolom × baris karena read filter).
        $nomor = range(4, 1502);
        mt_srand(182);
        shuffle($nomor);
        $nomor[] = 3;
        $baris   = '';

        foreach ($nomor as $r) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c><c r=\"B{$r}\" t=\"inlineStr\"><is><t>J</t></is></c>"
                . "<c r=\"C{$r}\" t=\"inlineStr\"><is><t>Cuti</t></is></c></row>";
        }

        $berkas = $this->rakit('<cols><col min="1" max="16383" width="10" customWidth="1"/></cols>', $baris);

        // Desain R5: pembacaan berhenti begitu ada sel berisi di baris > 1.001 → pesan jumlah baris (main: baris lebih
        // dibuang diam-diam karena totalRows listWorksheetInfo tertipu urutan `r`).
        $mulai = hrtime(true);
        $this->assertGalatBerkas('Jumlah baris data maksimal 1000.', $berkas);
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    /**
     * `<col 1..2048>` + baris kosong ber-`r` (verifikator R1-3): sebelum R1 varian 20 ribu baris dimuat 7,5-8,6 detik dan
     * varian 130 ribu baris fatal (bukti merah utama). Dijalankan di proses anak supaya PHPUnit tidak ikut berhenti. Baris
     * contoh template diganti baris tanpa kategori → galat baris, transaksi dibatalkan (tidak ada yang tersimpan).
     */
    #[DataProvider('barisKosongBanyak')]
    public function testColLebarDenganBarisKosongBanyakCepatTanpaFatal(int $jumlah): void
    {
        $baris = '';

        for ($i = 0; $i < $jumlah; $i++) {
            $baris .= '<row r="' . (1003 + $i) . '"/>';
        }

        $berkas = $this->rakit('<cols><col min="1" max="2048" width="9"/></cols>', $baris . '<row r="3"/>', [], '', true);

        $hasil = $this->jalankanWorker($berkas, '256M', 30);
        $this->assertSame(200, $hasil['status'] ?? null, 'tanpa fatal: ' . json_encode($hasil));
        $this->assertLessThan(self::DETIK_CI, $hasil['detik'] ?? INF);
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function barisKosongBanyak(): iterable
    {
        yield '20 ribu baris' => [20_000];

        yield '130 ribu baris' => [130_000];
    }

    /**
     * Template bergaya LibreOffice ≤ 7.3 (`<col max="1025">` di setiap sheet) dengan 2 dan 3 sheet: langkah `<col>`
     * dijumlah dari semua sheet (2.050 / 3.075) dan dulu ditolak "rusak" oleh batas 2.048 (R1-2).
     */
    #[DataProvider('jumlahSheetLibreOffice')]
    public function testLibreOfficeBanyakSheetDiterima(int $sheet): void
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->fromArray([ImporQna::JUDUL_KOLOM, ImporQna::CONTOH], null, 'A1');

        for ($i = 2; $i <= $sheet; $i++) {
            $buku->createSheet()->setCellValue('A1', "Petunjuk {$i}");
        }

        $path        = (string) tempnam(sys_get_temp_dir(), 'cbl');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        for ($i = 1; $i <= $sheet; $i++) {
            $nama = "xl/worksheets/sheet{$i}.xml";
            $xml  = (string) preg_replace('~<cols>.*?</cols>~s', '', (string) $zip->getFromName($nama));
            $zip->addFromString($nama, str_replace('<sheetData', '<cols><col min="1" max="1025" width="11.52" style="0"/></cols><sheetData', $xml));
        }

        $zip->close();

        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function jumlahSheetLibreOffice(): iterable
    {
        yield '2 sheet' => [2];

        yield '3 sheet' => [3];
    }

    /**
     * Kasus terburuk yang masih lolos batas sel (RUANG_SEL 10, tabel ukur di docblock ImporQna): 1.000 baris × 50 kolom
     * teks = ±50 ribu sel sheet 1, semuanya dibangun karena muat tanpa read filter. Angka terukur di docblock RUANG_SEL.
     */
    public function testKasusTerburukLolosTetapCepat(): void
    {
        $baris = '';

        for ($r = 3; $r <= 1001; $r++) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c><c r=\"B{$r}\" t=\"inlineStr\"><is><t>J</t></is></c>";

            for ($k = 5; $k <= 50; $k++) {
                $baris .= '<c r="' . Coordinate::stringFromColumnIndex($k) . $r . "\" t=\"inlineStr\"><is><t>isi{$r}</t></is></c>";
            }

            $baris .= '</row>';
        }

        $berkas = $this->rakit('', $baris, [], '', true);

        memory_reset_peak_usage();
        $awal  = memory_get_peak_usage();
        $mulai = hrtime(true);
        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage() - $awal);
        $this->assertCount(1000, $hasil['galat'], 'kategori kosong di semua baris: galat, tidak tersimpan');
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    /**
     * Berkas padat dengan part sheet raksasa (R2-1): unggahan kecil berisi sheet ±32 MB `<c/>`. Di d073a642 jalur pesan
     * baris (R1-4) menjalankan listWorksheetInfo pada sheet itu (7-12 detik) sebelum "rusak". Sekarang langsung "rusak".
     */
    public function testPadatDenganSheetRaksasaDitolakCepat(): void
    {
        $berkas = $this->rakit('', '<row r="3">' . str_repeat('<c/>', 7_500_000) . '</row>', [], '', true);
        $this->assertLessThan(100 * 1024, (int) filesize($berkas), 'unggahan kecil');

        // Ditolak sebelum reader vendor dibuka (terukur 1,1-1,4 s, hampir seluruhnya biaya pemindai); listWorksheetInfo
        // pada sheet ini menampung part 30 MB.
        $this->assertDitolakTanpaMembukaSheet($berkas);
    }

    /**
     * Varian R2-1/R3-2: part sheet raksasa di luar `xl/worksheets/` (Target relasi workbook menunjuk path lain, atau nama
     * entri ber-backslash). listWorksheetInfo tetap membacanya, jadi batas ukuran berlaku untuk entri mana pun.
     */
    #[DataProvider('letakSheetLain')]
    public function testPadatDenganSheetRaksasaDiPathLainDitolakCepat(string $entri, string $target): void
    {
        $berkas = $this->rakit('', '<row r="3">' . str_repeat('<c/>', 7_500_000) . '</row>', [], '', true);
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->deleteName('xl/worksheets/sheet1.xml');
        $zip->addFromString($entri, $sheet);

        foreach (['xl/_rels/workbook.xml.rels' => ['"worksheets/sheet1.xml"', '"' . $target . '"'], '[Content_Types].xml' => ['/xl/worksheets/sheet1.xml', '/' . str_replace('\\', '/', $entri)]] as $nama => [$lama, $baru]) {
            $isi = (string) $zip->getFromName($nama);
            $this->assertStringContainsString($lama, $isi);
            $zip->addFromString($nama, str_replace($lama, $baru, $isi));
        }

        $zip->close();

        $this->assertDitolakTanpaMembukaSheet($berkas);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function letakSheetLain(): iterable
    {
        yield 'xl/lain/data.xml' => ['xl/lain/data.xml', 'lain/data.xml'];

        yield 'Target data/s.xml' => ['xl/data/s.xml', 'data/s.xml'];

        yield 'entri ber-backslash' => ['xl\\worksheets\\sheet1.xml', 'worksheets/sheet1.xml'];
    }

    /**
     * R3-1: listWorksheetInfo mengurai part untuk SETIAP `<sheet>` di workbook, termasuk rujukan berulang ke part yang
     * sama. Jalur padat: 20 / 100 `<sheet>` → satu part ±7,9 MB `<row/>` (di 19ffcb0d: 31 s / fatal 60 s).
     */
    #[DataProvider('rujukanBerulangPadat')]
    public function testRujukanSheetBerulangPadatDitolakCepat(int $jumlah): void
    {
        $berkas = $this->rakit('', str_repeat('<row/>', 1_300_000), [], '', true);
        $this->rujukSheetBerulang($berkas, $jumlah);

        $this->assertDitolakTanpaMembukaSheet($berkas);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rujukanBerulangPadat(): iterable
    {
        yield '20 rujukan' => [20];

        yield '100 rujukan' => [100];
    }

    /**
     * R3-1 jalur TIDAK padat (sudah ada di main): 50 / 500 `<sheet>` → satu part 28 MB berisi spasi (sel/bobot kecil;
     * unggahan ±35 KB). Di 19ffcb0d dan main: 10-13 s / fatal 60 s.
     */
    #[DataProvider('rujukanBerulangTidakPadat')]
    public function testRujukanSheetBerulangTidakPadatSelesaiCepat(int $jumlah): void
    {
        $berkas = $this->rakit('', str_repeat(' ', 28 * 1024 * 1024), [], '', true);
        $this->rujukSheetBerulang($berkas, $jumlah);

        // Desain R5: hanya satu part (sheet pertama) yang dialirkan, satu kali.
        $this->assertSelesaiRingan($berkas);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rujukanBerulangTidakPadat(): iterable
    {
        yield '50 rujukan' => [50];

        yield '500 rujukan' => [500];
    }

    /**
     * Varian R2-1 banyak sheet, masing-masing di bawah 8 MB (4 × ±7,8 MB `<row/>`): listWorksheetInfo membaca SEMUA sheet
     * (terukur 6,3 s), jadi jalur padat dibatasi jumlah isi semua entri, bukan entri terbesar.
     */
    public function testPadatBanyakSheetMasingMasingKecilDitolakCepat(): void
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->fromArray(ImporQna::JUDUL_KOLOM, null, 'A1');

        for ($i = 2; $i <= 4; $i++) {
            $buku->createSheet();
        }

        $path        = (string) tempnam(sys_get_temp_dir(), 'cbs');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $baris = str_repeat('<row/>', 1_300_000);

        for ($i = 1; $i <= 4; $i++) {
            $nama = "xl/worksheets/sheet{$i}.xml";
            $xml  = (string) $zip->getFromName($nama);
            $xml  = str_contains($xml, '<sheetData/>') ? str_replace('<sheetData/>', "<sheetData>{$baris}</sheetData>", $xml) : str_replace('</sheetData>', "{$baris}</sheetData>", $xml);
            $zip->addFromString($nama, $xml);
        }

        $zip->close();
        $this->assertLessThan(100 * 1024, (int) filesize($path));

        $this->assertDitolakTanpaMembukaSheet($path);
    }

    /**
     * Penjaga memori opsi A (R2-2): baris ≤ batas, tetapi sel sheet 1 melewati batas (999 baris × 62 kolom ±61 ribu sel)
     * → "rusak" tanpa memuat. Tanpa penjaga ini seluruh sel dimuat karena tidak ada read filter.
     */
    public function testBanyakSelDiLuarAdDiterimaDenganMemoriKecil(): void
    {
        $baris = '';

        for ($r = 3; $r <= 1001; $r++) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c>";

            for ($k = 5; $k <= 66; $k++) {
                $baris .= '<c r="' . Coordinate::stringFromColumnIndex($k) . $r . "\" t=\"inlineStr\"><is><t>isi{$r}</t></is></c>";
            }

            $baris .= '</row>';
        }

        $berkas = $this->rakit('', $baris, [], '', true);

        // Desain R5: hanya A-E yang dibangun, jadi berkas ini (diterima di main) diterima lagi dengan memori kecil.
        memory_reset_peak_usage();
        $awal  = memory_get_peak_usage();
        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $awal);
        $this->assertCount(999, $hasil['dilewati'], 'baris 3..1.001 tanpa jawaban');
    }

    /**
     * xlsx bersandi (paket OLE berisi EncryptionInfo + EncryptedPackage, bentuk yang ditulis Excel; PhpSpreadsheet tidak
     * bisa menulis xlsx bersandi): bukan ZIP, tetapi `canRead` menerimanya → "rusak" seperti main (R2-3). Berkas yang
     * benar-benar bukan xlsx tetap mendapat pesan format (ImporQnaTest::testBerkasDitolak).
     */
    public function testXlsxBersandiPesanRusakSepertiMain(): void
    {
        $info  = new File(OLE::ascToUcs('EncryptionInfo'));
        $paket = new File(OLE::ascToUcs('EncryptedPackage'));
        $info->append(str_repeat("\x04", 600));
        $paket->append(str_repeat("\x01", 5000));
        $path        = (string) tempnam(sys_get_temp_dir(), 'cbo');
        $this->tmp[] = $path;
        $h           = fopen($path, 'wb');
        $this->assertIsResource($h);
        (new Root(time(), time(), [$info, $paket]))->save($h);
        fclose($h);

        $this->assertGalatBerkas(self::RUSAK, $path);
    }

    /**
     * Trade-off yang diterima reviewer (R2-6): entri yang tidak pernah dimuat tetapi berdeklarasi encoding selain UTF-8
     * (mis. SVG di `xl/media`) membuat PemindaiXlsx menolak berkas → "rusak". Di main berkas ini diterima.
     */
    public function testEntriBukanUtf8YangTidakDibacaDiterimaSepertiMain(): void
    {
        $svg = '<?xml version="1.0" encoding="ISO-8859-1"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';

        $hasil = (new ImporQna($this->db))->impor($this->unggah($this->rakit('', '', ['xl/media/image1.svg' => $svg])));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * Kasus terburuk yang LOLOS (R2-4): ±48 ribu sel teks inline ±600 byte (999 baris × kolom E-AX), sel dan isi
     * mendekati batas (50.050 sel, 32 MB). Terukur ±2,8-3,3 s, puncak PHP ±76 MB. Asersi utama memori; waktu diberi
     * ruang lebar (DETIK_CI_TERBURUK).
     */
    public function testKasusTerburukTeksPanjangLolosDenganMemoriTerbatas(): void
    {
        $isi   = str_repeat('teks panjang ', 46);
        $baris = '';

        for ($r = 3; $r <= 1001; $r++) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c><c r=\"B{$r}\" t=\"inlineStr\"><is><t>J</t></is></c>";

            for ($k = 5; $k <= 50; $k++) {
                $baris .= '<c r="' . Coordinate::stringFromColumnIndex($k) . $r . "\" t=\"inlineStr\"><is><t>{$isi}{$r}</t></is></c>";
            }

            $baris .= '</row>';
        }

        $berkas = $this->rakit('', $baris, [], '', true);
        $this->assertLessThan(2 * 1024 * 1024, (int) filesize($berkas));

        memory_reset_peak_usage();
        $awal  = memory_get_peak_usage();
        $mulai = hrtime(true);
        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage() - $awal);
        $this->assertLessThan(self::DETIK_CI_TERBURUK, (hrtime(true) - $mulai) / 1e9);
        $this->assertCount(1000, $hasil['galat']);
    }

    /**
     * R4 B1: berkas QnA sah dengan beberapa sheet yang di R3 salah ditolak (sel/bobot dikalikan jumlah sheet) dan di main
     * diterima: 1.000 baris ber-border A-T + 2 sheet petunjuk, 10 sheet polos, dan sheet Referensi 15.000 baris.
     */
    #[DataProvider('banyakSheetSah')]
    public function testBerkasSahBanyakSheetDiterima(int $sheet, string $kolomBorder, int $barisReferensi): void
    {
        $buku = new Spreadsheet();
        $data = [ImporQna::JUDUL_KOLOM];

        for ($i = 1; $i <= 1000; $i++) {
            $data[] = ["Pertanyaan {$i}?", "Jawaban {$i}", 'Cuti', ''];
        }

        $buku->getActiveSheet()->fromArray($data, null, 'A1');

        if ($kolomBorder !== '') {
            $buku->getActiveSheet()->getStyle("A1:{$kolomBorder}1001")->getBorders()->getAllBorders()->setBorderStyle('thin');
        }

        for ($i = 2; $i <= $sheet; $i++) {
            $lain = $buku->createSheet();
            $lain->setTitle($i === 2 && $barisReferensi > 0 ? 'Referensi' : "Petunjuk {$i}");
            $lain->setCellValue('A1', 'Petunjuk');

            if ($i === 2 && $barisReferensi > 0) {
                $ref = [];

                for ($r = 1; $r <= $barisReferensi; $r++) {
                    $ref[] = ["Kode {$r}", "Nama {$r}"];
                }

                $lain->fromArray($ref, null, 'A2');
            }

        }

        $path        = (string) tempnam(sys_get_temp_dir(), 'cbm');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertSame([1000, []], [$hasil['ditambah'], $hasil['galat']]);
        $this->assertSame('Pertanyaan 1000?', $this->db->table('qna')->orderBy('id_qna', 'DESC')->get(1)->getRow('pertanyaan'));
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function banyakSheetSah(): iterable
    {
        yield '3 sheet + border A-T' => [3, 'T', 0];

        yield '10 sheet polos' => [10, '', 0];

        yield 'sheet Referensi 15.000 baris' => [2, '', 15_000];
    }

    /**
     * R4 B1: nama sheet ganda (tanpa peka huruf) membuat pemuat memuat part yang sama lebih dari sekali lewat
     * setLoadSheetsOnly → "rusak". Excel/LibreOffice/Google tidak menulis nama ganda.
     */
    public function testNamaSheetNumerikSetaraDibacaSekali(): void
    {
        $berkas = $this->rakit('');
        $this->gantiWorkbook($berkas, '<sheet name="1" sheetId="1" r:id="rId1"/><sheet name="01" sheetId="2" r:id="rId1"/><sheet name="1.0" sheetId="3" r:id="rId1"/>');

        // Desain R5: hanya part pertama yang cocok yang dibaca, satu kali (main memuat semua yang cocok secara longgar).
        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * R4 B2: bentuk yang mengelabui hitungan `<sheet>` berbasis regex di R3. Semua → 422 tanpa membuka part 28 MB:
     * (a) `_rels/.rels` dengan 100 relasi officeDocument ke workbook yang sama (vendor mengurai workbook dan sheet untuk
     * SETIAP relasi); (b) prefiks namespace non-ASCII; (c) workbook UTF-16 ber-BOM; (d) prefiks > 64 byte.
     */
    #[DataProvider('pengelabuHitunganSheet')]
    public function testPengelabuHitunganSheetSelesaiCepat(string $varian): void
    {
        $berkas = $this->rakit('', str_repeat(' ', 28 * 1024 * 1024), [], '', true);
        $sheet  = '';

        for ($i = 1; $i <= 500; $i++) {
            $sheet .= "<P:sheet name=\"S{$i}\" sheetId=\"{$i}\" r:id=\"rId1\"/>";
        }

        $wb = static fn (string $p): string => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "<{$p}:workbook xmlns:{$p}=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\""
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<{$p}:sheets>" . str_replace('P:', "{$p}:", $sheet) . "</{$p}:sheets></{$p}:workbook>";

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($berkas));

        if ($varian === 'relasi') {
            $rel = '';

            for ($i = 1; $i <= 100; $i++) {
                $rel .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/>";
            }

            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rel . '</Relationships>');
        } elseif ($varian === 'nonascii') {
            $zip->addFromString('xl/workbook.xml', $wb('ä'));
        } elseif ($varian === 'utf16') {
            $xml = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $wb('x'));
            $zip->addFromString('xl/workbook.xml', "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
        } else {
            $zip->addFromString('xl/workbook.xml', $wb(str_repeat('p', 100)));
        }

        $zip->close();
        $this->assertLessThan(200 * 1024, (int) filesize($berkas));

        // Desain R5: hanya relasi officeDocument pertama dan sheet pertama yang dibaca; bentuk prefiks/encoding ditangani
        // parser XML, bukan hitungan teks. Relasi > 32 ditolak (R6 B3).
        if ($varian === 'relasi') {
            $this->assertGalatBerkas(self::RUSAK, $berkas);

            return;
        }

        $this->assertSelesaiRingan($berkas);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pengelabuHitunganSheet(): iterable
    {
        yield '(a) 100 relasi officeDocument' => ['relasi'];

        yield '(b) prefiks non-ASCII' => ['nonascii'];

        yield '(c) workbook UTF-16 ber-BOM' => ['utf16'];

        yield '(d) prefiks 100 byte' => ['panjang'];
    }

    /**
     * R4 B3: rumus shared panjang di luar A-D (induk 700 byte berisi 100-an acuan sel × ±40 ribu sel anak di kolom F-AS, mirip sharedf3, tidak padat). Vendor menerjemahkan
     * rumus induk per sel anak walau readDataOnly; dulu read filter melewati sel di luar A-D (main 0,6-1,4 s, R3 33-60 s).
     */
    public function testRumusSharedPanjangDiLuarAdSelesaiCepat(): void
    {
        $acuan = [];

        for ($i = 1; $i <= 400; $i++) {
            $acuan[] = Coordinate::stringFromColumnIndex($i % 16000 + 1) . ($i % 1000 + 1);
        }

        $induk = substr(implode('+', $acuan), 0, 700);
        $baris = '';

        for ($r = 3; $r <= 1001; $r++) {
            $baris .= "<row r=\"{$r}\">";

            for ($k = 6; $k <= 45; $k++) {
                $ref = Coordinate::stringFromColumnIndex($k) . $r;
                $baris .= $r === 3 && $k === 6
                    ? "<c r=\"{$ref}\"><f t=\"shared\" ref=\"F3:AS1001\" si=\"0\">{$induk}</f><v>0</v></c>"
                    : "<c r=\"{$ref}\"><f t=\"shared\" si=\"0\"/><v>0</v></c>";
            }

            $baris .= '</row>';
        }

        $berkas = $this->rakit('', $baris, [], '', true);
        $this->assertLessThan(2 * 1024 * 1024, (int) filesize($berkas));

        // Desain R5: sel di luar A-D tidak diterjemahkan (hanya induknya didaftarkan), seperti main dengan read filter.
        $this->assertSelesaiRingan($berkas);
    }

    /**
     * Pagar B3: berkas sah dengan jawaban panjang (8.000 karakter, shared strings) dan rumus biasa tetap diterima.
     */
    public function testJawabanPanjangDanRumusBiasaTetapDiterima(): void
    {
        $buku = new Spreadsheet();
        $data = [ImporQna::JUDUL_KOLOM];

        for ($i = 1; $i <= 1000; $i++) {
            $data[] = ["Pertanyaan {$i}?", str_repeat('jawab ', 1333) . $i, 'Cuti', ''];
        }

        $buku->getActiveSheet()->fromArray($data, null, 'A1');
        $buku->getActiveSheet()->setCellValue('F2', '=A2&"!"');
        $path        = (string) tempnam(sys_get_temp_dir(), 'cbj');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertSame([1000, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * Paritas nilai R5: PembacaQnaXlsx vs logika main (load() dengan read filter A-D/E1 + rangeToArray(A1:D, null, false,
     * false, false)) untuk berkas tulisan PhpSpreadsheet: rich text (shared strings ber-run), angka bulat/pecahan/notasi E,
     * boolean, galat, tanggal serial, rumus, teks biasa, sel kosong, dan judul ekstra di E1.
     */
    public function testParitasNilaiBerkasTulisanPhpSpreadsheet(): void
    {
        $buku  = new Spreadsheet();
        $sheet = $buku->getActiveSheet();
        $sheet->fromArray(ImporQna::JUDUL_KOLOM, null, 'A1');
        $kaya = new RichText();
        $kaya->createText('Biasa ');
        $kaya->createTextRun('tebal')->getFont()?->setBold(true);
        $sheet->setCellValue('A2', $kaya);
        $sheet->setCellValue('B2', 42);
        $sheet->setCellValue('C2', 3.25);
        $sheet->setCellValueExplicit('D2', '1E3', DataType::TYPE_NUMERIC);
        $sheet->setCellValue('A3', true);
        $sheet->setCellValueExplicit('B3', '#N/A', DataType::TYPE_ERROR);
        $sheet->setCellValue('C3', 45292.5);
        $sheet->setCellValue('D3', '=A2&"!"');
        $sheet->setCellValue('A5', '  spasi  ');
        $sheet->setCellValue('E1', 'Ekstra');
        $sheet->setCellValue('F2', 'di luar');
        $path        = (string) tempnam(sys_get_temp_dir(), 'cbp');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $this->assertSamaDenganMain($path);
    }

    /**
     * Paritas nilai R5 untuk XML mentah: shared string dengan fonetik `<rPh>` dan `_xHHHH_`, inlineStr ber-run, `t="str"`,
     * `t="d"`, `t="e"`, `t="b"`, angka berspasi, sel tanpa `r`, rumus shared yang induknya di luar A-D dan anaknya di A-D
     * (diterjemahkan seperti vendor), dan sel kosong ber-gaya.
     */
    public function testParitasNilaiXmlMentah(): void
    {
        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<si><t>Pertanyaan</t></si><si><t>Jawaban</t></si><si><t>Kategori</t></si><si><t>Tag (Pisahkan dengan koma)</t></si>'
            . '<si><r><t>Run </t></r><r><rPr><b/></rPr><t>dua</t></r><rPh sb="0" eb="1"><t>FONETIK</t></rPh></si>'
            . '<si><t xml:space="preserve">baris_x000D_baru</t></si><si/></sst>';
        $baris = '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2" t="s"><v>6</v></c>'
            . '<c r="D2" t="inlineStr"><is><r><t>in</t></r><r><t>line</t></r></is></c><c r="F2"><f t="shared" ref="F2:F4" si="0">G2*2</f><v>0</v></c></row>'
            . '<row r="3"><c r="A3" t="str"><f>"a"&amp;"b"</f><v>ab</v></c><c r="B3" t="d"><v>2024-01-02T00:00:00</v></c>'
            . '<c r="C3" t="e"><v>#DIV/0!</v></c><c r="D3" t="b"><v>1</v></c></row>'
            . '<row r="4"><c><v> 12 </v></c><c t="n"><v>1.5E2</v></c><c r="C4"><f t="shared" si="0"/><v>0</v></c><c r="D4" s="1"/></row>';
        $berkas = $this->rakit('', $baris, ['xl/sharedStrings.xml' => $sst]);
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', (string) preg_replace('~<row r="1">.*?</row>~s', '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>', (string) preg_replace('~<row r="2">.*?</row>~s', '', $sheet, 1)));
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', $rels));
        $zip->close();

        $this->assertSamaDenganMain($berkas);
    }

    /**
     * Nilai (sel A1:D, E1) dan total baris PembacaQnaXlsx sama dengan logika main yang dijalankan di sini dengan vendor.
     */
    private function assertSamaDenganMain(string $path): void
    {
        $reader = new XlsxReader();
        $reader->setReadEmptyCells(false);
        $info = $reader->listWorksheetInfo($path)[0];
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([(string) $info['worksheetName']]);
        $reader->setReadFilter(new class () implements IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return ($row <= 1001 && in_array($columnAddress, ['A', 'B', 'C', 'D'], true)) || ($row === 1 && $columnAddress === 'E');
            }
        });
        $buku  = $reader->load($path);
        $sheet = $buku->getSheet(0);
        $main  = [$sheet->rangeToArray('A1:D' . max(1, min($sheet->getHighestDataRow(), 1001)), null, false, false, false), $sheet->getCell('E1')->getValue()];
        $buku->disconnectWorksheets();

        $baru = (new PembacaQnaXlsx())->bacaQna($path, 1000);
        $this->assertNotNull($baru);
        $this->assertFalse($baru['gagal']);
        $this->assertSame($main, [$baru['sel'], $baru['ekstra']]);
        $this->assertSame((int) $info['totalRows'] - 1 > 1000, $baru['total_baris'] - 1 > 1000, 'pesan jumlah baris');
    }

    /**
     * R6 B1: deklarasi encoding yang tidak kompatibel ASCII (UTF-7, EBCDIC) di part sheet atau shared strings → "rusak"
     * sebelum diurai: dengan UTF-7, DOCTYPE dan entitas internal bisa disamarkan dari pemeriksaan byte lalu diekspansi
     * parser. Berkas kecil, jadi asersi utama adalah pesan; waktu hanya pagar longgar.
     */
    #[DataProvider('encodingTerlarang')]
    public function testEncodingTidakKompatibelAsciiDitolak(string $part, string $encoding): void
    {
        $berkas = $this->rakit('');
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));

        if ($part === 'sheet') {
            $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('encoding="UTF-8"', "encoding=\"{$encoding}\"", $xml));
        } else {
            $zip->addFromString('xl/sharedStrings.xml', "<?xml version=\"1.0\" encoding=\"{$encoding}\"?><sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><si><t>x</t></si></sst>");
            $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
            $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', $rels));
            $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<c r="A2" t="inlineStr"><is><t xml:space="preserve">Bagaimana cara mengajukan cuti?</t></is></c>', '<c r="A2" t="s"><v>0</v></c>', $xml));
        }

        $zip->close();

        $mulai = hrtime(true);
        $this->assertGalatBerkas(self::RUSAK, $berkas);
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodingTerlarang(): iterable
    {
        yield 'sheet UTF-7' => ['sheet', 'UTF-7'];

        yield 'sheet EBCDIC' => ['sheet', 'IBM037'];

        yield 'sst UTF-7' => ['sst', 'UTF-7'];
    }

    /**
     * Pagar R6 B1: shared strings berdeklarasi ISO-8859-1 dan windows-1252 tetap dibaca identik dengan main.
     */
    #[DataProvider('encodingSah')]
    public function testEncodingKompatibelAsciiTetapIdentikMain(string $encoding): void
    {
        $sst = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?><sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">"
            . '<si><t>Pertanyaan</t></si><si><t>Jawaban</t></si><si><t>Kategori</t></si><si><t>Tag (Pisahkan dengan koma)</t></si><si><t>caf' . "\xE9" . '?</t></si></sst>';
        $berkas = $this->rakit('', '', ['xl/sharedStrings.xml' => $sst]);
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = (string) preg_replace('~<row r="1">.*?</row>~s', '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<c r="A2" t="inlineStr"><is><t xml:space="preserve">Bagaimana cara mengajukan cuti?</t></is></c>', '<c r="A2" t="s"><v>4</v></c>', $xml));
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', $rels));
        $zip->close();

        $this->assertSamaDenganMain($berkas);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodingSah(): iterable
    {
        yield 'ISO-8859-1' => ['ISO-8859-1'];

        yield 'windows-1252' => ['windows-1252'];
    }

    /**
     * R6 B1 pertahanan berlapis: satu sel di dalam filter dengan isi > 2 MB (Excel sendiri membatasi 32.767 karakter per
     * sel) → "rusak" tanpa menampung isinya; di luar filter tidak direkam sehingga tetap diterima.
     */
    public function testRekamanSelRaksasaDitolak(): void
    {
        $isi    = str_repeat('a', 3 * 1024 * 1024);
        $berkas = $this->rakit('', "<row r=\"3\"><c r=\"A3\" t=\"inlineStr\"><is><t>{$isi}</t></is></c></row>");
        $this->assertGalatBerkas(self::RUSAK, $berkas);

        $luar  = $this->rakit('', "<row r=\"3\"><c r=\"Z3\" t=\"inlineStr\"><is><t>{$isi}</t></is></c></row>");
        $hasil = (new ImporQna($this->db))->impor($this->unggah($luar));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * R6 B2: anak rumus shared di A-D dengan induk > 1.024 karakter, atau Σ(100 + panjang induk) > 1 MB → "rusak" cepat
     * (di 5e929043: fatal timeout). Kontrol: rumus shared pendek wajar di A-D tetap identik dengan main.
     */
    #[DataProvider('rumusSharedDiAd')]
    public function testRumusSharedDiAdBerbatas(int $panjangInduk, int $anak, bool $ditolak): void
    {
        $acuan = [];

        for ($i = 1; $i <= 2000 && strlen(implode('+', $acuan)) < $panjangInduk; $i++) {
            $acuan[] = Coordinate::stringFromColumnIndex($i % 200 + 1) . ($i % 900 + 1);
        }

        $induk = substr(implode('+', $acuan), 0, $panjangInduk);
        $baris = '';

        for ($r = 3; $r < 3 + $anak; $r++) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c>"
                . ($r === 3 ? "<c r=\"B{$r}\"><f t=\"shared\" ref=\"B3:B" . (2 + $anak) . "\" si=\"0\">{$induk}</f><v>0</v></c>" : "<c r=\"B{$r}\"><f t=\"shared\" si=\"0\"/><v>0</v></c>")
                . '</row>';
        }

        $berkas = $this->rakit('', $baris, [], '', true);

        if ($ditolak) {
            $mulai = hrtime(true);
            $this->assertGalatBerkas(self::RUSAK, $berkas);
            $this->assertLessThan(2.5, (hrtime(true) - $mulai) / 1e9);
        } else {
            $this->assertSamaDenganMain($berkas);
        }
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function rumusSharedDiAd(): iterable
    {
        yield 'induk 1.025 karakter × 999 anak' => [1025, 999, true];

        yield 'induk 8 KB × 999 anak' => [8192, 999, true];

        yield 'Σ biaya > 1 MB (1.000 × 999)' => [1000, 999, true];

        yield 'kontrol: induk 20 karakter × 999 anak' => [20, 999, false];
    }

    /**
     * R6 B3: 200 Relationship officeDocument di `_rels/.rels` ke workbook yang sama → "rusak" (batas 32). Relasi berulang
     * di bawah batas dibaca sekali (cache per entri).
     */
    public function testRelasiBerulangDibatasi(): void
    {
        foreach ([200 => true, 20 => false] as $jumlah => $ditolak) {
            $berkas = $this->rakit('');
            $zip    = new ZipArchive();
            $this->assertTrue($zip->open($berkas));
            $rel = '';

            for ($i = 1; $i <= $jumlah; $i++) {
                $rel .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/>";
            }

            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rel . '</Relationships>');
            $zip->close();

            if ($ditolak) {
                $this->assertGalatBerkas(self::RUSAK, $berkas);
            } else {
                $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
                $this->assertSame(1, $hasil['ditambah']);
                $this->bersihkanChatbot();
            }
        }
    }

    /**
     * R6 B4: border A1:Z20000 (±520 ribu sel kosong bergaya) + 1.000 baris data → diterima seperti main.
     */
    public function testBorderLuasDenganSeribuBarisDiterima(): void
    {
        $buku  = new Spreadsheet();
        $sheet = $buku->getActiveSheet();
        $data  = [ImporQna::JUDUL_KOLOM];

        for ($i = 1; $i <= 1000; $i++) {
            $data[] = ["Pertanyaan {$i}?", "Jawaban {$i}", 'Cuti', ''];
        }

        $sheet->fromArray($data, null, 'A1');
        $sheet->getStyle('A1:Z20000')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $path        = (string) tempnam(sys_get_temp_dir(), 'cbz');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $mulai = hrtime(true);
        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
        $this->assertSame([1000, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * R6 C1: sheet pertama chartsheet (tanpa part worksheet) → "rusak" seperti main.
     */
    public function testSheetPertamaTanpaPartWorksheetRusak(): void
    {
        $berkas = $this->rakit('');
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('relationships/worksheet"', 'relationships/chartsheet"', $rels));
        $zip->close();

        $this->assertGalatBerkas(self::RUSAK, $berkas);
    }

    /**
     * R6 C2 + C5: teks rumus berisi `&#13;`/`&#10;`/`&#9;`, atribut berisi tab, E1 bukan teks, dan Strict OOXML — identik
     * dengan main.
     */
    public function testParitasRujukanKarakterDanE1BukanTeks(): void
    {
        $baris = '<row r="2"><c r="A2" t="str"><f>"a"&amp;CHAR(13)&amp;"&#13;b&#10;c&#9;d"</f><v>x</v></c>'
            . '<c r="B2" t="inlineStr"><is><t xml:space="preserve">baris&#13;&#10;dua&#9;tab</t></is></c><c r="C2" t="inlineStr"><is><t>Cuti</t></is></c></row>';
        $berkas = $this->rakit('', $baris);
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = (string) preg_replace('~<row r="2">.*?</row>~s', '', $xml, 1);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</row>', '<c r="E1"><v>12.5</v></c></row>', $xml));
        $zip->close();

        $this->assertSamaDenganMain($berkas);
    }

    /**
     * R6 C5: Strict OOXML (namespace purl) — identik dengan main.
     */
    public function testParitasStrictOoxml(): void
    {
        $berkas = $this->rakit('');
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $ganti = [
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'                          => 'http://purl.oclc.org/ooxml/spreadsheetml/main',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument' => 'http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet'      => 'http://purl.oclc.org/ooxml/officeDocument/relationships/worksheet',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles'         => 'http://purl.oclc.org/ooxml/officeDocument/relationships/styles',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships'                => 'http://purl.oclc.org/ooxml/officeDocument/relationships',
        ];

        foreach (['_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $nama) {
            $isi = $zip->getFromName($nama);

            if ($isi !== false) {
                $zip->addFromString($nama, strtr($isi, $ganti));
            }
        }

        $zip->close();

        $this->assertSamaDenganMain($berkas);
    }

    /**
     * R6 C6: PembacaQnaXlsx meniru potongan Reader\Xlsx phpoffice/phpspreadsheet 5.10.x (baris tercatat di docblock
     * kelas). Test ini gagal bila versi vendor berubah.
     */
    public function testVersiPhpSpreadsheetTerkunci(): void
    {
        $versi = (string) InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet');

        $this->assertMatchesRegularExpression('/^v?5\.10\./', $versi, "phpoffice/phpspreadsheet {$versi}: tinjau ulang PembacaQnaXlsx sebelum menaikkan versi");
    }

    /**
     * Isi data identik dengan saat masih memakai read filter (R1-3 syarat 2): `rangeToArray` tidak menghitung rumus, jadi
     * rumus di A-D yang merujuk kolom di luar A-D (yang dulu tidak dimuat) tetap terbaca sebagai teks rumusnya.
     */
    public function testRumusMerujukLuarAdTetapTeksRumus(): void
    {
        $buku  = new Spreadsheet();
        $sheet = $buku->getActiveSheet();
        $sheet->fromArray(ImporQna::JUDUL_KOLOM, null, 'A1');
        $sheet->setCellValue('F2', 'dari F');
        $sheet->setCellValue('G2', 'jawab');
        $sheet->setCellValue('A2', '=F2&"?"');
        $sheet->setCellValue('B2', '=UPPER(G2)');
        $sheet->setCellValue('C2', 'Cuti');

        $path        = (string) tempnam(sys_get_temp_dir(), 'cbf');
        $this->tmp[] = $path;
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();

        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
        $q = $this->db->table('qna')->get()->getRowArray();
        $this->assertSame('=F2&"?"', $q['pertanyaan'] ?? null);
        $this->assertStringContainsString('UPPER(G2)', (string) ($q['jawaban'] ?? ''));
    }

    public function testBerkasGayaExcelDanLibreOfficeTetapDiterima(): void
    {
        // Bentuk <col> yang lazim ditulis Excel 365 (sisa lembar sampai 16.384) dan LibreOffice lama (sampai 1.025), VML
        // komentar dengan <br> tanpa penutup dan kata "sheetData" di teksnya, serta relasi hyperlink yang URL-nya memuat
        // "sheetData": harus tetap diterima (pagar regresi CR-177).
        $vml  = '<xml xmlns:v="urn:schemas-microsoft-com:vml"><v:shape><v:textbox><div>lihat sheetData<br>baris2</div></v:textbox></v:shape></xml>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"'
            . ' Target="https://contoh.example/panduan?bagian=sheetData" TargetMode="External"/></Relationships>';
        $berkas = $this->rakit(
            '<cols><col min="1" max="2" width="40" customWidth="1"/><col min="3" max="4" width="20" customWidth="1"/>'
            . '<col min="5" max="1025" width="11.52" style="0"/><col min="1026" max="16384" width="9"/></cols>',
            '',
            ['xl/drawings/vmlDrawing1.vml' => $vml, 'xl/worksheets/_rels/sheet1.xml.rels' => $rels],
            '<hyperlinks xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><hyperlink ref="A2" r:id="rIdH1"/></hyperlinks>',
        );

        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']], 'baris contoh template diimpor');
    }

    public function testBomDekompresiRelsDitolakSebelumReaderVendor(): void
    {
        // `_rels/.rels` 96 MB (terkompresi ±100 KB): `canRead` vendor mendekompresinya utuh dua kali, jadi sebelum R1-1
        // proses anak 64M fatal memori di canRead. Sekarang ditolak dari direktori ZIP sebelum vendor menyentuh berkas.
        $isi         = (string) tempnam(sys_get_temp_dir(), 'cbr');
        $this->tmp[] = $isi;
        $h           = fopen($isi, 'wb');
        $this->assertIsResource($h);
        $blok = str_repeat(' ', 1 << 20);

        for ($i = 0; $i < 96; $i++) {
            fwrite($h, $blok);
        }

        fclose($h);
        $berkas = $this->simpan(ImporQna::template());
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $zip->addFile($isi, '_rels/.rels');
        $zip->setCompressionName('_rels/.rels', ZipArchive::CM_DEFLATE, 9);
        $zip->close();
        $this->assertLessThan(2 * 1024 * 1024, (int) filesize($berkas), 'di bawah batas unggah 2 MB');

        $hasil = $this->jalankanWorker($berkas, '64M');
        $this->assertSame(422, $hasil['status'] ?? null, 'harus 422, bukan fatal: ' . json_encode($hasil));
        $this->assertSame(['berkas' => [self::RUSAK]], $hasil['errors']);
    }

    public function testBerkasSahTerlaluBanyakBarisTetapPesanBaris(): void
    {
        // Berkas sah yang barisnya terlalu banyak tetap mendapat pesan kontrak lama, bukan "rusak" (R1-4): 13.000 baris
        // (52.004 sel) dan 25.000 baris (100.004 sel); keduanya padat dengan isi ZIP di bawah MAKS_BYTE_ISI_PADAT.
        foreach ([13_001, 25_001] as $akhir) {
            $this->assertGalatBerkas('Jumlah baris data maksimal 1000.', $this->rakit('', $this->barisData(3, $akhir)));
        }
    }

    private function barisData(int $awal, int $akhir): string
    {
        $baris = '';

        for ($r = $awal; $r <= $akhir; $r++) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c><c r=\"B{$r}\" t=\"inlineStr\"><is><t>J</t></is></c>"
                . "<c r=\"C{$r}\" t=\"inlineStr\"><is><t>Cuti</t></is></c><c r=\"D{$r}\" t=\"inlineStr\"><is><t>t</t></is></c></row>";
        }

        return $baris;
    }

    public function testTemplateResmiTetapDiterima(): void
    {
        $path = $this->simpan(ImporQna::template());

        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * Template resmi dengan `<cols>` diganti `$cols`, baris tambahan sebelum `</sheetData>`, elemen sesudah
     * `</sheetData>`, entri ZIP tambahan, dan (opsional) baris contoh diganti baris tanpa kategori.
     *
     * @param array<string, string> $entri
     */
    private function rakit(string $cols, string $baris = '', array $entri = [], string $sesudahData = '', bool $tanpaKategori = false): string
    {
        $path = $this->simpan(ImporQna::template());
        $zip  = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $nama  = 'xl/worksheets/sheet1.xml';
        $sheet = (string) $zip->getFromName($nama);
        $sheet = (string) preg_replace('~<cols>.*?</cols>~s', '', $sheet);
        $sheet = str_replace('<sheetData>', $cols . '<sheetData>', $sheet);
        $sheet = str_replace('</sheetData>', $baris . '</sheetData>' . $sesudahData, $sheet);

        if ($tanpaKategori) {
            // Baris contoh template tanpa kategori → galat baris: impor dibatalkan, tidak ada yang tersimpan.
            $sheet = (string) preg_replace('~<row r="2">.*?</row>~s', '<row r="2"><c r="A2" t="inlineStr"><is><t>Contoh?</t></is></c><c r="B2" t="inlineStr"><is><t>J</t></is></c></row>', $sheet);
        }
        $zip->addFromString($nama, $sheet);

        foreach ($entri as $n => $isi) {
            $zip->addFromString($n, $isi);
        }

        $zip->close();

        return $path;
    }

    /**
     * Isi `<sheets>` workbook template diganti `$sheets`.
     */
    private function gantiWorkbook(string $berkas, string $sheets): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $wb = (string) $zip->getFromName('xl/workbook.xml');
        $this->assertSame(1, preg_match('~<sheets>.*</sheets>~s', $wb));
        $zip->addFromString('xl/workbook.xml', (string) preg_replace('~<sheets>.*</sheets>~s', "<sheets>{$sheets}</sheets>", $wb));
        $zip->close();
    }

    /**
     * Workbook template diganti `$jumlah` elemen `<sheet>` yang semuanya merujuk `rId1` (part sheet yang sama).
     */
    private function rujukSheetBerulang(string $berkas, int $jumlah): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($berkas));
        $wb = (string) $zip->getFromName('xl/workbook.xml');
        $this->assertSame(1, preg_match('~<sheets>.*</sheets>~s', $wb));
        $sheet = '';

        for ($i = 1; $i <= $jumlah; $i++) {
            $sheet .= "<sheet name=\"S{$i}\" sheetId=\"{$i}\" r:id=\"rId1\"/>";
        }

        $zip->addFromString('xl/workbook.xml', (string) preg_replace('~<sheets>.*</sheets>~s', "<sheets>{$sheet}</sheets>", $wb));
        $zip->close();
        $this->assertLessThan(200 * 1024, (int) filesize($berkas), 'unggahan kecil');
    }

    /**
     * Asersi utama deterministik (R3-3): ditolak "rusak" dengan kenaikan puncak memori kecil, artinya part sheet tidak
     * pernah ditampung reader vendor (listWorksheetInfo menampung part utuh: ±7,8-30 MB di kasus ini; terukur ±1,7 MB bila ditolak lebih dulu). Waktu hanya pagar longgar.
     */
    private function assertDitolakTanpaMembukaSheet(string $path): void
    {
        memory_reset_peak_usage();
        $awal  = memory_get_peak_usage();
        $mulai = hrtime(true);
        $this->assertGalatBerkas(self::RUSAK, $path);
        $this->assertLessThan(6 * 1024 * 1024, memory_get_peak_usage() - $awal, 'part sheet tidak dibuka');
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
    }

    /**
     * Selesai tanpa galat berkas, cepat, dan memori kecil (aliran, bukan pemuatan part utuh).
     */
    private function assertSelesaiRingan(string $path): void
    {
        memory_reset_peak_usage();
        $awal  = memory_get_peak_usage();
        $mulai = hrtime(true);
        (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertLessThan(6 * 1024 * 1024, memory_get_peak_usage() - $awal, 'part tidak ditampung utuh');
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    private function simpan(string $isi): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cbp');
        file_put_contents($path, $isi);
        $this->tmp[] = $path;

        return $path;
    }

    private function unggah(string $path): UploadedFile
    {
        return new UploadedFile($path, 'qna.xlsx', 'application/octet-stream', (int) filesize($path), UPLOAD_ERR_OK);
    }

    private function assertGalatBerkas(string $pesan, string $path): void
    {
        try {
            (new ImporQna($this->db))->impor($this->unggah($path));
            $this->fail("seharusnya 422: {$pesan}");
        } catch (ValidationException $e) {
            $this->assertSame(['berkas' => [$pesan]], $e->getErrors());
        }
    }

    /**
     * @return array{status?: int, errors?: mixed, puncak: int, rss: int|null, detik?: float, exit?: int, keluaran?: string}
     */
    private function jalankanWorker(string $berkas, string $memori, int $detik = 60): array
    {
        $env = array_merge(getenv(), [
            'CI_ENVIRONMENT'          => 'testing',
            'database.tests.database' => $this->db->getDatabase(),
        ]);
        $proses = proc_open(
            [PHP_BINARY, '-d', "memory_limit={$memori}", '-d', "max_execution_time={$detik}", SUPPORTPATH . 'Scripts' . DIRECTORY_SEPARATOR . 'impor_qna_worker.php', $berkas],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOTPATH,
            $env,
        );
        $this->assertIsResource($proses, 'Worker PHP gagal dijalankan');
        $keluar = (string) stream_get_contents($pipes[1]);
        $galat  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $kode = proc_close($proses);

        $baris = trim((string) strrchr(rtrim($keluar), "\n") ?: $keluar);
        $hasil = json_decode($baris, true);

        if (! is_array($hasil)) {
            return ['puncak' => 0, 'rss' => null, 'exit' => $kode, 'keluaran' => substr($keluar . $galat, -600)];
        }

        return $hasil;
    }
}
