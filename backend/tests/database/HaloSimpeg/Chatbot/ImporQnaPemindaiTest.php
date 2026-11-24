<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use CodeIgniter\HTTP\Files\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
 * Impor QnA: pemindaian struktur `.xlsx` sebelum `load()` (CR-182). Berkas dirakit dari template resmi dengan XML sheet
 * yang diubah; tanpa fixture biner.
 *
 * Sebelum CR-182 (9554054a): `<col min="5" max="1">` membuat `Reader\Xlsx\ColumnAndRowAttributes::readColumnAttributes`
 * (PhpSpreadsheet 5.10) berputar tanpa akhir sampai fatal memori (500 di luar envelope 422), dan `<col>` selebar lembar
 * dengan baris ber-`r` acak lolos pra-cek `listWorksheetInfo` (totalRows = `r` baris TERAKHIR) lalu dimuat lambat.
 * Putaran R1: bom dekompresi `_rels/.rels` (canRead), `<col>` × baris kosong (read filter), LO banyak sheet, pesan baris.
 *
 * @internal
 */
final class ImporQnaPemindaiTest extends DatabaseTestCase
{
    use ChatbotFixtureTrait;

    private const RUSAK = 'Berkas Excel rusak atau tidak dapat dibaca.';

    /** Batas waktu test berbasis waktu: target ±2 s di mesin pengembang, ruang aman untuk runner CI yang lebih lambat. */
    private const DETIK_CI = 6.0;

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

    public function testColMaxKurangDariMinDitolak422TanpaFatal(): void
    {
        $berkas = $this->rakit('<cols><col min="5" max="1" width="10" customWidth="1"/></cols>');
        $this->assertLessThan(10_000, (int) filesize($berkas), 'berkas kecil');

        // Di proses anak berbatas memori: sebelum CR-182 proses ini fatal (bukan 422), jadi PHPUnit sendiri tidak ikut mati.
        $hasil = $this->jalankanWorker($berkas, '128M');
        $this->assertSame(422, $hasil['status'] ?? null, 'harus 422, bukan fatal: ' . json_encode($hasil));
        $this->assertSame(['berkas' => [self::RUSAK]], $hasil['errors']);
        $this->assertLessThan(64 * 1024 * 1024, $hasil['puncak'], 'puncak memori PHP proses anak');

        if ($hasil['rss'] !== null) {
            $this->assertLessThan(160 * 1024 * 1024, $hasil['rss'], 'puncak RSS proses anak');
        }
    }

    public function testColSelebarLembarDenganBarisAcakCepatDanBarisLebihTetapTidakIkut(): void
    {
        // Baris 3..1.502 diacak lalu baris 3 terakhir: listWorksheetInfo melihat totalRows 3, padahal ada 1.500 baris.
        // Sebelum CR-182 dimuat lambat (readCell per kolom × baris karena read filter). Sekarang muat tanpa filter
        // (R1-3) sehingga cepat. Baris di atas 1.001 tetap tidak ikut diimpor, sama seperti sebelumnya (catatan review b).
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

        $mulai = hrtime(true);
        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
        $this->assertSame([1000, []], [$hasil['ditambah'], $hasil['galat']], 'baris 2..1.001 saja');
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
    public function testRujukanSheetBerulangTidakPadatDitolakCepat(int $jumlah): void
    {
        $berkas = $this->rakit('', str_repeat(' ', 28 * 1024 * 1024), [], '', true);
        $this->rujukSheetBerulang($berkas, $jumlah);

        $this->assertDitolakTanpaMembukaSheet($berkas);
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
    public function testPadatBarisDalamBatasDitolakTanpaMemuat(): void
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

        memory_reset_peak_usage();
        $awal = memory_get_peak_usage();
        $this->assertGalatBerkas(self::RUSAK, $berkas);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $awal, 'tidak dimuat');
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
    public function testEntriBukanUtf8DitolakTradeOff(): void
    {
        $svg = '<?xml version="1.0" encoding="ISO-8859-1"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';

        $this->assertGalatBerkas(self::RUSAK, $this->rakit('', '', ['xl/media/image1.svg' => $svg]));
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
    public function testNamaSheetGandaDitolak(): void
    {
        $berkas = $this->rakit('');
        $this->gantiWorkbook($berkas, '<sheet name="Template QnA" sheetId="1" r:id="rId1"/><sheet name="template qna" sheetId="2" r:id="rId1"/>');

        $this->assertGalatBerkas(self::RUSAK, $berkas);
    }

    /**
     * R4 B2: bentuk yang mengelabui hitungan `<sheet>` berbasis regex di R3. Semua → 422 tanpa membuka part 28 MB:
     * (a) `_rels/.rels` dengan 100 relasi officeDocument ke workbook yang sama (vendor mengurai workbook dan sheet untuk
     * SETIAP relasi); (b) prefiks namespace non-ASCII; (c) workbook UTF-16 ber-BOM; (d) prefiks > 64 byte.
     */
    #[DataProvider('pengelabuHitunganSheet')]
    public function testPengelabuHitunganSheetDitolakCepat(string $varian): void
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

        $this->assertDitolakTanpaMembukaSheet($berkas);
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
    public function testRumusSharedPanjangDiLuarAdDitolakCepat(): void
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

        $mulai = hrtime(true);
        $this->assertGalatBerkas(self::RUSAK, $berkas);
        $this->assertLessThan(self::DETIK_CI, (hrtime(true) - $mulai) / 1e9);
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
