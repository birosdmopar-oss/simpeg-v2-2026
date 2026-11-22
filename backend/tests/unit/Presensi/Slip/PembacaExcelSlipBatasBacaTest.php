<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Presensi\XlsxMentah;

/**
 * CR-177 — berkas impor Slip Gaji yang tidak wajar ditolak 422, bukan 500 karena memori/waktu habis:
 *
 * - `.xlsx` dipindai dulu (`PemindaiXlsx`, semua entri ZIP sebagai aliran) terhadap anggaran SELURUH berkas: ukuran isi,
 *   jumlah sel, bobot elemen, dan iterasi `<col>`;
 * - batas baris dan kolom sheet GPP/TK diperiksa sesudah muat, sebelum array baris × kolom disusun.
 *
 * Berkas uji ditulis sebagai XML mentah ({@see XlsxMentah}); kenaikan puncak memori PHP diukur supaya test tetap bermakna
 * saat `memory_limit = -1`. Memori libxml (di luar hitungan PHP) dijaga lewat asersi "ditolak pemindai", bukan lewat angka.
 *
 * @internal
 */
final class PembacaExcelSlipBatasBacaTest extends CIUnitTestCase
{
    private const NIP            = '199001012020011001';
    private const PESAN_KOLOM    = 'Jumlah kolom melebihi batas 256 per sheet.';
    private const PESAN_SEL      = 'Jumlah sel melebihi batas 400.040 per berkas. Hapus sheet atau kolom yang tidak dipakai, lalu unggah ulang.';
    private const PESAN_STRUKTUR = 'Struktur berkas terlalu besar untuk diproses. Simpan ulang berkas hanya dengan sheet GPP dan TK, tanpa rumus (tempel sebagai nilai) atau teks sangat panjang.';
    private const BATAS_MEMORI   = 64 * 1024 * 1024;

    /** 120.000 sel × 2 sheet: dua part dimuat sekaligus terukur +131 MB; ditolak di part kedua jauh di bawah itu. */
    private const BATAS_SATU_PART = 105 * 1024 * 1024;

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

    // ------------------------------------------------------------------------------------------------------------------
    // Batas kolom dan baris sheet GPP/TK (sesudah muat, sebelum array disusun)
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function selDiXfd(): iterable
    {
        yield 'sel berisi' => [XlsxMentah::sel('XFD1', 'x')];
        yield 'sel kosong bergaya' => ['<c r="XFD1" s="0"/>'];
    }

    /**
     * Header sah, satu sel di XFD1, dan sel terakhir di baris 10.001 (= 10.000 baris data, masih di dalam batas baris).
     * Dulu: 10.001 × 16.384 iterasi sel (±163 juta) → memori/waktu habis.
     */
    #[DataProvider('selDiXfd')]
    public function testSelDiKolomJauhDitolakTanpaMenghabiskanMemori(string $selXfd): void
    {
        $gpp  = '<row r="1">' . self::selHeader(self::headerGpp()) . $selXfd . '</row>' . XlsxMentah::baris(2, self::dataGpp()) . XlsxMentah::baris(10001, self::dataGpp());
        $path = $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]);
        $this->assertLessThan(20_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak(self::PESAN_KOLOM, $path);
    }

    public function testKolomTepatDiBatasDiterima(): void
    {
        // Kolom ke-256 (IV) berisi header tak dikenal: masih dibaca; kolom tak dikenal memang diabaikan penguraian.
        $gpp   = '<row r="1">' . self::selHeader(self::headerGpp()) . XlsxMentah::sel('IV1', 'catatan') . '</row>' . XlsxMentah::baris(2, self::dataGpp());
        $sheet = $this->assertDiterima($this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]));

        $this->assertSame([1, 2], array_keys($sheet['GPP']));
        $this->assertCount(Presensi::MAKS_KOLOM_IMPOR, $sheet['GPP'][1]);
        $this->assertSame('catatan', $sheet['GPP'][1][255]);
        $this->assertSame(self::NIP, $sheet['GPP'][2][0]);
    }

    public function testKolomSatuLewatBatasDitolak(): void
    {
        $gpp = '<row r="1">' . self::selHeader(self::headerGpp()) . XlsxMentah::sel('IW1', 'catatan') . '</row>' . XlsxMentah::baris(2, self::dataGpp());

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]));
    }

    public function testSheetTkLebarJugaDitolak(): void
    {
        $tk = '<row r="1">' . self::selHeader(self::headerTk()) . XlsxMentah::sel('XFD1', 'x') . '</row>' . XlsxMentah::baris(2, self::dataTk());

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx(['GPP' => self::sheetGpp(), 'TK' => $tk]));
    }

    /**
     * Sel tanpa atribut `r` ditaruh pemuat menurut nomor urutnya di baris: 300 sel berurutan = kolom 300.
     */
    public function testBarisBerisiBanyakSelTanpaAtributRDitolak(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp()) . '<row r="2">' . str_repeat('<c><v>1</v></c>', 300) . '</row>';

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]));
    }

    /**
     * Perilaku lama yang dijaga: sel kosong (hanya bergaya) lewat batas baris tetap dihitung, jadi berkasnya tetap ditolak.
     */
    public function testSelKosongLewatBatasBarisTetapDitolak(): void
    {
        $gpp = self::sheetGpp() . '<row r="7"><c r="A7" s="0"/></row>';

        $this->assertDitolak('Jumlah baris melebihi batas 5 per sheet.', $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), new PembacaExcelSlip(5));
    }

    public function testSelKosongDiDalamBatasTetapDibacaSebagaiBarisKosong(): void
    {
        $gpp   = self::sheetGpp() . '<row r="6"><c r="A6" s="0"/></row>';
        $sheet = $this->assertDiterima($this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), new PembacaExcelSlip(5));

        $this->assertSame([1, 2, 3, 4, 5, 6], array_keys($sheet['GPP']));
        $this->assertSame(array_fill(0, count(self::headerGpp()), null), $sheet['GPP'][6]);
    }

    /**
     * Perilaku lama yang dijaga: `<row>` tanpa sel (mis. hanya tinggi baris) tidak dihitung sebagai baris data.
     */
    public function testBarisBerformatTanpaSelLewatBatasTetapDiterima(): void
    {
        $gpp   = self::sheetGpp() . '<row r="7" ht="20" customHeight="1"/><row r="20000" ht="20" customHeight="1"/>';
        $sheet = $this->assertDiterima($this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), new PembacaExcelSlip(5));

        $this->assertSame([1, 2], array_keys($sheet['GPP']));
    }

    /**
     * Perilaku lama yang dijaga: batas baris dan kolom hanya untuk sheet GPP dan TK. Sheet lain (referensi 12.000 baris,
     * matriks 300 kolom) tidak dimuat dan tidak membuat berkas ditolak.
     */
    public function testSheetTambahanBesarTetapDiterima(): void
    {
        $rekap = '<row r="1">' . XlsxMentah::sel('A1', 'kode') . XlsxMentah::sel('KN1', 'lebar') . '</row>';

        for ($r = 2; $r <= 12_001; $r++) {
            $rekap .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, $r) . '</row>';
        }

        $sheet = $this->assertDiterima($this->xlsx(['Rekap' => $rekap, 'GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]));

        $this->assertSame([1, 2], array_keys($sheet['GPP']));
    }

    /**
     * GPP diperiksa dulu, lalu TK: pesan tidak bergantung urutan tab (GPP lebar, TK lewat baris → pesan kolom).
     *
     * @param list<string> $urutan
     */
    #[DataProvider('urutanTab')]
    public function testGppDiperiksaDuluTanpaBergantungUrutanTab(array $urutan): void
    {
        $isi = [
            'GPP' => '<row r="1">' . self::selHeader(self::headerGpp()) . XlsxMentah::sel('IW1', 'catatan') . '</row>' . XlsxMentah::baris(2, self::dataGpp()),
            'TK'  => self::sheetTk() . XlsxMentah::baris(8, self::dataTk()),
        ];

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx([$urutan[0] => $isi[$urutan[0]], $urutan[1] => $isi[$urutan[1]]]), new PembacaExcelSlip(5));
    }

    public function testTkLewatBarisDitolakSesudahGppLolos(): void
    {
        $this->assertDitolak('Jumlah baris melebihi batas 5 per sheet.', $this->xlsx(['TK' => self::sheetTk() . XlsxMentah::baris(8, self::dataTk()), 'GPP' => self::sheetGpp()]), new PembacaExcelSlip(5));
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function urutanTab(): iterable
    {
        yield 'GPP di kiri' => [['GPP', 'TK']];
        yield 'TK di kiri' => [['TK', 'GPP']];
    }

    /**
     * GPP dan TK menunjuk part yang SAMA (dihitung pemindai sekali, dimuat pemuat dua kali): ditolak saat part itu
     * diminta untuk sheet kedua, sebelum salinan keduanya dibangun.
     */
    public function testGppDanTkMenunjukPartYangSamaDitolak(): void
    {
        $sel = '';

        for ($k = 1; $k <= 30; $k++) {
            $sel .= '<c r="' . XlsxMentah::kolom($k) . '%1$d"><v>%1$d</v></c>';
        }

        $isi = '';

        for ($r = 1; $r <= 4000; $r++) {
            $isi .= '<row r="' . $r . '">' . sprintf($sel, $r) . '</row>';
        }

        $entri = XlsxMentah::entri(['GPP' => $isi, 'TK' => '']);
        unset($entri['xl/worksheets/sheet2.xml'], $isi);
        $entri['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet2.xml"', 'Target="worksheets/sheet1.xml"', $entri['xl/_rels/workbook.xml.rels']);
        $path                                = $this->xlsxEntri($entri);
        unset($entri);

        memory_reset_peak_usage();
        $awal = memory_get_peak_usage();

        try {
            (new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $e->getMessage());
        }

        $this->assertLessThan(self::BATAS_SATU_PART, memory_get_peak_usage() - $awal, 'satu part di memori, bukan dua');
    }

    /**
     * Varian: nama entri berbeda huruf besar-kecil (pemuat mencari entri tanpa membedakannya) dan dua entri berisi sama.
     *
     * @param array<string, string> $ubah
     */
    #[DataProvider('partSamaLewatNamaLain')]
    public function testGppDanTkBerisiPartYangSamaLewatNamaLainDitolak(array $ubah): void
    {
        $entri = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetGpp()]);

        foreach ($ubah as $cari => $ganti) {
            $entri['xl/_rels/workbook.xml.rels'] = str_replace($cari, $ganti, $entri['xl/_rels/workbook.xml.rels']);
        }

        $this->assertDitolak(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $this->xlsxEntri($entri));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function partSamaLewatNamaLain(): iterable
    {
        yield 'dua entri berisi sama' => [[]];
        yield 'Target beda huruf besar-kecil' => [['Target="worksheets/sheet2.xml"' => 'Target="worksheets/SHEET1.xml"']];
        yield 'Target absolut' => [['Target="worksheets/sheet2.xml"' => 'Target="/xl/worksheets/sheet1.xml"']];
    }

    /**
     * GPP dan TK dimuat dalam SATU muatan: rumus lintas sheet (TK → GPP, GPP → TK, nama terdefinisi) dihitung seperti
     * biasa. Bila dimuat terpisah, sheet yang dirujuk tidak ada dan hasilnya "#REF!".
     *
     * @param list<string> $urutan
     */
    #[DataProvider('formatDanUrutan')]
    public function testRumusLintasSheetGppDanTkTetapDihitung(string $format, array $urutan): void
    {
        $buku = new Spreadsheet();
        $buku->removeSheetByIndex(0);
        $isi = [
            KolomGaji::SHEET_GPP => [self::headerGpp(), self::dataGpp()],
            KolomGaji::SHEET_TK  => [self::headerTk(), self::dataTk()],
        ];

        foreach ($urutan as $nama) {
            $buku->createSheet()->setTitle($nama)->fromArray($isi[$nama], null, 'A1', true);
        }

        $gpp = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $tk  = $buku->getSheetByName(KolomGaji::SHEET_TK);
        $this->assertNotNull($gpp);
        $this->assertNotNull($tk);

        $gpp->setCellValueExplicit('A2', self::NIP, DataType::TYPE_STRING);
        $tk->setCellValueExplicit('A2', self::NIP, DataType::TYPE_STRING);
        $gpp->setCellValue('E2', 1000);
        $tk->setCellValue('E2', 5);
        $tk->setCellValue('D2', '=GPP!E2*2');       // TK → GPP
        $gpp->setCellValue('F2', '=TK!E2+1');       // GPP → TK
        $tk->setCellValue('G2', '=SUM(GPP!E2:E2,TK!E2)');

        if ($format === 'Xlsx') {
            $buku->addNamedRange(new NamedRange('POKOK', $gpp, '$E$2'));
            $tk->setCellValue('F2', '=POKOK+3');    // nama terdefinisi lintas sheet
        }

        $path = $this->temp($format === 'Xls' ? '.xls' : '.xlsx');
        IOFactory::createWriter($buku, $format)->save($path);
        $buku->disconnectWorksheets();

        $sheet = $this->assertDiterima($path);

        $this->assertEquals(2000, $sheet['TK'][2][3], 'TK!D2 = GPP!E2*2');
        $this->assertEquals(6, $sheet['GPP'][2][5], 'GPP!F2 = TK!E2+1');
        $this->assertEquals(1005, $sheet['TK'][2][6], 'TK!G2 = SUM lintas sheet');

        if ($format === 'Xlsx') {
            $this->assertEquals(1003, $sheet['TK'][2][5], 'TK!F2 = POKOK+3');
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function formatDanUrutan(): iterable
    {
        yield 'xlsx, GPP di kiri' => ['Xlsx', ['GPP', 'TK']];
        yield 'xlsx, TK di kiri' => ['Xlsx', ['TK', 'GPP']];
        yield 'xls' => ['Xls', ['GPP', 'TK']];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Pemuat tanpa read filter
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Pagar "tanpa read filter": definisi kolom lebar (`<cols min="1" max="16383">`, bukan sel) + 10.000 baris data tetap
     * diterima dalam waktu wajar. Bila pemuat diberi read filter, reader Xlsx memanggilnya untuk setiap kolom `<cols>` ×
     * setiap baris (±164 juta panggilan, puluhan detik) dan test ini merah.
     */
    public function testDefinisiKolomLebarDiterimaDalamWaktuWajar(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp());

        for ($r = 2; $r <= 10_001; $r++) {
            $gpp .= XlsxMentah::baris($r, [self::NIP, 9, 2026]);
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => $gpp, 'TK' => self::sheetTk()], ['GPP' => '<cols><col min="1" max="16383" width="10"/></cols>']);
        unset($gpp);

        memory_reset_peak_usage();
        $awal  = memory_get_peak_usage();
        $mulai = hrtime(true);
        $hasil = (new PembacaExcelSlip(10000))->baca($path);
        $detik = (hrtime(true) - $mulai) / 1e9;

        $this->assertTrue($hasil->valid);
        /** @var array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $sheet */
        $sheet = $hasil->nilai;
        $this->assertCount(10_001, $sheet['GPP']);
        $this->assertCount(count(self::headerGpp()), $sheet['GPP'][10_001], 'lebar baris = kolom berisi sel, bukan lebar <cols>');
        $this->assertLessThan(10.0, $detik, 'tanpa read filter: tidak ada panggilan per kolom <cols> × baris');
        $this->assertLessThan(self::BATAS_MEMORI, memory_get_peak_usage() - $awal, 'kenaikan puncak memori');
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Anggaran seluruh berkas: ditolak pemindai, sebelum dimuat
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Berkas padat 250.000 baris data GPP (500.000 sel) ditolak sebelum dimuat (dulu seluruh sheet dimuat dulu, ratusan
     * MB, baru ditolak karena baris).
     */
    public function testBerkasPadatDitolakSebelumDimuat(): void
    {
        $path = $this->xlsx(['GPP' => self::gppPadat(), 'TK' => self::sheetTk()]);
        $this->assertLessThan(5 * 1024 * 1024, (int) filesize($path), 'di bawah batas ukuran unggah');

        $this->assertDitolak(self::PESAN_SEL, $path);
    }

    /**
     * `<row>` dan `<c>` TANPA atribut `r` (`listWorksheetInfo` membacanya sebagai 0 baris): 300.000 baris × 2 sel dalam
     * berkas puluhan KB.
     */
    public function testBarisTanpaAtributRDitolakSebelumDimuat(): void
    {
        $path = $this->xlsx(['GPP' => XlsxMentah::baris(1, ['nip', 'bulan', 'tahun']) . str_repeat('<row><c><v>1</v></c><c><v>1</v></c></row>', 300_000), 'TK' => self::sheetTk()]);
        $this->assertLessThan(200_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak(self::PESAN_SEL, $path);
    }

    /**
     * Berkas padat yang `<row>` terakhirnya bernomor kecil (`listWorksheetInfo` hanya melihat `r` baris terakhir).
     */
    public function testBarisTerakhirBernomorKecilDitolakSebelumDimuat(): void
    {
        $this->assertDitolak(self::PESAN_SEL, $this->xlsx(['GPP' => self::gppPadat() . '<row r="2"/>', 'TK' => self::sheetTk()]));
    }

    /**
     * Relasi sheet ber-`Target` absolut `/kecil/gpp.xml`: `listWorksheetInfo` membaca `kecil/gpp.xml`, sedangkan pemuat
     * membuang `strlen('xl') + 2` karakter pertama lalu menambahkan `xl/` → `xl/il/gpp.xml`. Pemindai tidak mengikuti
     * relasi, jadi part padat tetap terhitung.
     */
    public function testTargetRelasiAbsolutDitolakSebelumDimuat(): void
    {
        $entri = XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]);
        unset($entri['xl/worksheets/sheet1.xml']);
        $entri['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="/kecil/gpp.xml"', $entri['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('Target="/kecil/gpp.xml"', $entri['xl/_rels/workbook.xml.rels']);
        $entri['kecil/gpp.xml'] = XlsxMentah::lembar(self::sheetGpp());
        $entri['xl/il/gpp.xml'] = XlsxMentah::lembar(self::gppPadat());

        $this->assertDitolak(self::PESAN_SEL, $this->xlsxEntri($entri));
    }

    /**
     * BLOK-3: entri ZIP bernama akhiran `/` tetap dimuat pemuat bila `Target` relasinya juga berakhiran `/`.
     */
    public function testEntriBerakhiranGarisMiringTetapDihitung(): void
    {
        $entri = XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]);
        unset($entri['xl/worksheets/sheet1.xml']);
        $entri['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="worksheets/sheet1.xml/"', $entri['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('Target="worksheets/sheet1.xml/"', $entri['xl/_rels/workbook.xml.rels']);
        $entri['xl/worksheets/sheet1.xml/'] = XlsxMentah::lembar(self::gppPadat());
        $path                               = $this->xlsxEntri($entri);
        $this->assertLessThan(5 * 1024 * 1024, (int) filesize($path));

        $this->assertDitolak(self::PESAN_SEL, $path);
    }

    /**
     * Part ber-akar bukan `worksheet` tetap dihitung: pemuat tidak memeriksa nama akar, hanya `sheetData` → `row` → `c`.
     */
    public function testPartBerakarLainTetapDihitung(): void
    {
        $entri                             = XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]);
        $entri['xl/worksheets/sheet1.xml'] = XlsxMentah::KEPALA . '<lain xmlns="' . XlsxMentah::UTAMA . '"><sheetData>' . self::sheetGpp() . '</sheetData></lain>';
        $jumlah                            = 2 * count(self::headerGpp()) + 2 * count(self::headerTk());
        $path                              = $this->xlsxEntri($entri);

        $this->assertDiterima($path, new PembacaExcelSlip(10000, 256, $jumlah));
        $this->assertDitolak(self::pesanSel($jumlah - 1), $path, new PembacaExcelSlip(10000, 256, $jumlah - 1));
    }

    /**
     * Batas jumlah sel per berkas: di dalam batas baris dan kolom, tetapi 9.000 × 46 = 414.000 sel.
     */
    public function testJumlahSelLewatBatasDitolakSebelumDimuat(): void
    {
        $sel = '';

        for ($k = 1; $k <= 46; $k++) {
            $sel .= '<c r="' . XlsxMentah::kolom($k) . '%1$d"><v>1</v></c>';
        }

        $gpp = XlsxMentah::baris(1, self::headerGpp());

        for ($r = 2; $r <= 9001; $r++) {
            $gpp .= '<row r="' . $r . '">' . sprintf($sel, $r) . '</row>';
        }

        $this->assertDitolak(self::PESAN_SEL, $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]));
    }

    public function testJumlahSelDihitungDariSemuaSheet(): void
    {
        $path   = $this->xlsx(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk(), 'Lain' => XlsxMentah::baris(1, ['a', 'b'])]);
        $jumlah = 2 * count(self::headerGpp()) + 2 * count(self::headerTk()) + 2;

        $this->assertDiterima($path, new PembacaExcelSlip(10000, 256, $jumlah));
        $this->assertDitolak(self::pesanSel($jumlah - 1), $path, new PembacaExcelSlip(10000, 256, $jumlah - 1));
    }

    /**
     * Batas sel bawaan naik mengikuti batas baris ((batas baris + 1) × 40) dan tidak turun di bawah hitungan untuk 10.000
     * baris. Berkas 414.000 sel tanpa sheet GPP/TK: ditolak pemindai pada batas baris 10.000 atau lebih kecil, lolos
     * pemindai pada 20.000 (lalu berhenti di "sheet tidak ada", tanpa dimuat).
     */
    public function testBatasSelBawaanMengikutiBatasBaris(): void
    {
        $sel = '';

        for ($k = 1; $k <= 46; $k++) {
            $sel .= '<c r="' . XlsxMentah::kolom($k) . '%1$d"><v>1</v></c>';
        }

        $isi = '';

        for ($r = 1; $r <= 9000; $r++) {
            $isi .= '<row r="' . $r . '">' . sprintf($sel, $r) . '</row>';
        }

        $path = $this->xlsx(['Lain' => $isi]);
        unset($isi);

        $this->assertDitolak(self::PESAN_SEL, $path, new PembacaExcelSlip(10000));
        $this->assertDitolak(self::PESAN_SEL, $path, new PembacaExcelSlip(5));

        $hasil = (new PembacaExcelSlip(20000))->baca($path);
        $this->assertFalse($hasil->valid, 'lolos pemindai, berhenti di sheet wajib');
    }

    /**
     * Batas ukuran isi sesudah dekompresi: entri pengisi 33 MB (terkompresi puluhan KB) ditolak dari ukuran yang tercatat di
     * ZIP, tanpa membaca isinya.
     */
    public function testIsiMengembangLewatBatasDitolak(): void
    {
        $entri                   = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]);
        $entri['xl/pengisi.xml'] = str_repeat(' ', 33 * 1024 * 1024);
        $path                    = $this->xlsxEntri($entri);
        unset($entri);
        $this->assertLessThan(200_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak('Isi berkas terlalu besar sesudah dibuka (maksimal 32 MB).', $path);
    }

    /**
     * BLOK-4: jutaan elemen kecil di luar `<c>` lolos batas sel dan batas isi, tetapi memakan memori libxml saat pemuat
     * membangun pohon XML (di luar `memory_limit`). Bobot elemen + atribut seluruh berkas dibatasi.
     *
     * @param array<string, string> $entri
     */
    #[DataProvider('pengisiElemen')]
    public function testElemenPengisiLewatAnggaranDitolakSebelumDimuat(array $entri): void
    {
        $path = $this->xlsxEntri([...XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]), ...$entri]);
        $this->assertLessThan(200_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak(self::PESAN_STRUKTUR, $path);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function pengisiElemen(): iterable
    {
        $buka = XlsxMentah::KEPALA . '<worksheet xmlns="' . XlsxMentah::UTAMA . '">';
        $data = '<sheetData>' . self::sheetGpp() . '</sheetData></worksheet>';

        yield '3,5 juta elemen kosong di sheetPr' => [['xl/worksheets/sheet1.xml' => $buka . '<sheetPr>' . str_repeat('<x/>', 3_500_000) . '</sheetPr>' . $data]];
        yield '3,5 juta si kosong di sharedStrings' => [['xl/sharedStrings.xml' => XlsxMentah::KEPALA . '<sst xmlns="' . XlsxMentah::UTAMA . '">' . str_repeat('<si/>', 3_500_000) . '</sst>']];
        yield '1,2 juta elemen ber-atribut' => [['xl/worksheets/sheet1.xml' => $buka . '<sheetPr>' . str_repeat('<x a="1"/>', 1_200_000) . '</sheetPr>' . $data]];
    }

    /**
     * BLOK-5: pemuat mengulang `min..max` untuk setiap `<col>`; 1.000 `<col min="1" max="16383">` dalam berkas 7 KB =
     * 16 juta iterasi. `max` lebih kecil dari `min` membuat perulangan pemuat tidak pernah berhenti.
     */
    #[DataProvider('definisiKolomMahal')]
    public function testDefinisiKolomMahalDitolakSebelumDimuat(string $cols): void
    {
        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()], ['GPP' => '<cols>' . $cols . '</cols>']);
        $this->assertLessThan(20_000, (int) filesize($path), 'berkas kecil');

        $mulai = hrtime(true);
        $this->assertDitolak(self::PESAN_STRUKTUR, $path);
        $this->assertLessThan(5.0, (hrtime(true) - $mulai) / 1e9);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function definisiKolomMahal(): iterable
    {
        yield '1.000 col selebar 16.383' => [str_repeat('<col min="1" max="16383" width="9" customWidth="1"/>', 1000)];
        yield 'max lebih kecil dari min' => ['<col min="5" max="2" width="9"/>'];
        yield 'max di luar lembar' => ['<col min="1" max="900000000" width="9"/>'];
    }

    public function testDefinisiKolomSeluruhLembarDiterima(): void
    {
        // `max="16384"`: pemuat berhenti sesudah satu iterasi. Bentuk ini ditulis Excel untuk "seluruh kolom".
        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()], ['GPP' => '<cols>' . str_repeat('<col min="1" max="16384" width="9"/>', 500) . '</cols>']);

        $this->assertDiterima($path);
    }

    /**
     * P2: nama sheet kembar (Excel tidak pernah mengizinkannya): pemuat memuat setiap sheet bernama sama, pemindai
     * menghitung part-nya sekali.
     *
     * @param list<string> $nama
     */
    #[DataProvider('namaSheetKembar')]
    public function testNamaSheetKembarDitolak(array $nama): void
    {
        $entri = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]);
        $dok   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheet = '';

        foreach ($nama as $i => $n) {
            $sheet .= '<sheet name="' . $n . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($n === 'TK' ? 2 : 1) . '"/>';
        }

        $entri['xl/workbook.xml'] = XlsxMentah::KEPALA . '<workbook xmlns="' . XlsxMentah::UTAMA . '" xmlns:r="' . $dok . '"><sheets>' . $sheet . '</sheets></workbook>';

        $this->assertDitolak(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $this->xlsxEntri($entri));
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function namaSheetKembar(): iterable
    {
        yield 'GPP tiga kali' => [['GPP', 'TK', 'GPP', 'GPP']];
        yield 'beda huruf besar-kecil' => [['GPP', 'TK', 'gpp']];
        yield 'sheet lain kembar' => [['GPP', 'TK', 'Rekap', 'Rekap']];
    }

    /**
     * P4a: pemuat mengurai ulang relasi sheet untuk SETIAP `<tablePart>` (juga saat data-only): 1.000 tablePart × relasi
     * panjang = menit.
     */
    public function testTablePartBerlebihDitolakSebelumDimuat(): void
    {
        $dok  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $path = $this->xlsxEntri([
            ...XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]),
            'xl/worksheets/sheet1.xml' => XlsxMentah::KEPALA . '<worksheet xmlns="' . XlsxMentah::UTAMA . '"><sheetData>' . self::sheetGpp() . '</sheetData><tableParts count="51" xmlns:r="' . $dok . '">'
                . str_repeat('<tablePart r:id="rIdT"/>', 51) . '</tableParts></worksheet>',
        ]);

        $this->assertDitolak(self::PESAN_STRUKTUR, $path);
    }

    /**
     * P4b: pemuat menyalin teks shared string untuk setiap sel yang merujuknya. Satu `<si>` 10.000 huruf dirujuk 5.000 sel
     * = 50 MB salinan dari berkas belasan KB.
     */
    public function testSharedStringPanjangDirujukBanyakSelDitolakSebelumDimuat(): void
    {
        $path = $this->xlsxSharedString(str_repeat('<row><c t="s"><v>1</v></c></row>', 5000), ['pendek', str_repeat('a', 10_000)]);
        $this->assertLessThan(50_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak(self::PESAN_STRUKTUR, $path);
    }

    /**
     * Shared string panjang yang dirujuk SATU sel, di samping ribuan sel berteks pendek, bukan masalah: yang dihitung
     * jumlah salinan per sel, bukan teks terpanjang × jumlah sel.
     */
    public function testSharedStringPanjangDirujukSekaliTetapDiterima(): void
    {
        $path = $this->xlsxSharedString('<row><c t="s"><v>1</v></c></row>' . str_repeat('<row><c t="s"><v>0</v></c></row>', 20_000), ['pendek', str_repeat('a', 30_000)]);

        $this->assertDiterima($path);
    }

    /**
     * P4c: pemuat menyalin teks rumus induk `<f t="shared">` untuk setiap sel turunannya.
     */
    public function testRumusSharedPanjangDitolakSebelumDimuat(): void
    {
        $lain = '<row r="1"><c r="A1"><f t="shared" ref="A1:A5001" si="0">LEN("' . str_repeat('a', 10_000) . '")</f><v>0</v></c></row>';

        for ($r = 2; $r <= 5001; $r++) {
            $lain .= '<row r="' . $r . '"><c r="A' . $r . '"><f t="shared" si="0"/><v>0</v></c></row>';
        }

        $path = $this->xlsx(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk(), 'Lain' => $lain]);
        $this->assertLessThan(60_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak(self::PESAN_STRUKTUR, $path);
    }

    public function testRumusSharedPendekTetapDiterima(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp()) . '<row r="2">' . self::selHeaderData() . '</row>';
        $tk  = XlsxMentah::baris(1, [...self::headerTk(), 'jumlah']) . '<row r="2"><c r="A2" t="inlineStr"><is><t>' . self::NIP . '</t></is></c><c r="B2"><v>9</v></c><c r="C2"><v>2026</v></c>'
            . '<c r="J2"><f t="shared" ref="J2:J5" si="0">B2+C2</f><v>2035</v></c></row>';

        for ($r = 3; $r <= 5; $r++) {
            $tk .= '<row r="' . $r . '"><c r="J' . $r . '"><f t="shared" si="0"/><v>0</v></c></row>';
        }

        $sheet = $this->assertDiterima($this->xlsx(['GPP' => $gpp, 'TK' => $tk]));
        $this->assertEquals(2035, $sheet['TK'][2][9]);
    }

    /**
     * P1: entri XML yang gagal diurai parser pemindai tidak membuat berkas ditolak; isinya dihitung kasar sebagai batas
     * atas. Sheet yang sengaja dirusak namespace-nya (SimpleXML milik pemuat tetap membacanya) dengan sel berlebih tetap
     * ditolak sebelum dimuat.
     */
    public function testSheetRusakNamespaceDenganSelBerlebihTetapDitolak(): void
    {
        $entri                             = XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]);
        $entri['xl/worksheets/sheet1.xml'] = XlsxMentah::KEPALA . '<worksheet xmlns="' . XlsxMentah::UTAMA . '"><foo:bar/><sheetData>' . self::gppPadat() . '</sheetData></worksheet>';

        $this->assertDitolak(self::PESAN_STRUKTUR, $this->xlsxEntri($entri));
    }

    /**
     * Hitung kasar ikut menghitung sel berprefiks namespace (`<x:c>`): sheet rusak yang memakai prefiks untuk namespace
     * utama tetap ditolak karena jumlah sel, bukan lolos sebagai "tanpa sel".
     */
    public function testHitungKasarMenghitungSelBerprefiks(): void
    {
        $entri                      = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]);
        $entri['xl/lain/data1.xml'] = '<x:worksheet xmlns:x="' . XlsxMentah::UTAMA . '"><foo:bar/><x:sheetData><x:row>' . str_repeat('<x:c/>', 100) . '</x:row></x:sheetData></x:worksheet>';
        $jumlah                     = 2 * count(self::headerGpp()) + 2 * count(self::headerTk());
        $path                       = $this->xlsxEntri($entri);

        $this->assertDitolak(self::pesanSel($jumlah + 50), $path, new PembacaExcelSlip(10000, 256, $jumlah + 50));
        $this->assertDiterima($path, new PembacaExcelSlip(10000, 256, $jumlah + 200));
    }

    /**
     * Indeks shared string yang ditulis panjang (nol di depan) tetap dikenali: pemuat memakai `(int)` seluruh teksnya.
     */
    public function testIndeksSharedStringBernolDiDepanTetapDihitung(): void
    {
        $path = $this->xlsxSharedString(str_repeat('<row><c t="s"><v>' . str_repeat('0', 40) . '1</v></c></row>', 5000), ['pendek', str_repeat('a', 10_000)]);

        $this->assertDitolak(self::PESAN_STRUKTUR, $path);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Entri yang diperlakukan khusus oleh pemuat (encoding, DOCTYPE, galat urai)
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Berkas sah dengan entri tambahan yang tidak mengganggu: tetap diterima.
     *
     * @param array<string, string> $entri
     */
    #[DataProvider('entriTambahanSah')]
    public function testEntriTambahanSahTetapDiterima(array $entri): void
    {
        $this->assertDiterima($this->xlsxEntri([...XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]), ...$entri]));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function entriTambahanSah(): iterable
    {
        $lembar = '<worksheet xmlns="' . XlsxMentah::UTAMA . '"><sheetData/></worksheet>';

        yield 'SVG Illustrator ber-DOCTYPE dan iso-8859-1' => [['xl/media/image1.svg' => '<?xml version="1.0" encoding="iso-8859-1"?><!-- Generator: Adobe Illustrator 19.0.0 -->'
            . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>']];
        yield 'customXml UTF-16 ber-BOM' => [['customXml/item1.xml' => "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="utf-16"?><b:Sources xmlns:b="http://schemas.openxmlformats.org/officeDocument/2006/bibliography"/>', 'UTF-16LE', 'UTF-8')]];
        // BOM menang atas deklarasi, di libxml maupun di pemuat (konversi menurut BOM, lalu deklarasi dibuang).
        yield 'BOM UTF-16 dengan deklarasi encoding lain' => [['xl/lain/data1.xml' => "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="ISO-8859-1"?>' . $lembar, 'UTF-16LE', 'UTF-8')]];
        yield 'deklarasi alias UTF8' => [['xl/lain/data1.xml' => '<?xml version="1.0" encoding="UTF8"?>' . $lembar]];
        yield 'teks sel memuat encoding="latin1"' => [['xl/lain/data1.xml' => XlsxMentah::lembar('<row r="1">' . XlsxMentah::sel('A1', 'catatan: encoding="latin1" pada berkas lama') . '</row>')]];
        // Tulisan Excel 365 untuk kontrol form/komentar berteks dua baris: <br> tanpa penutup. Pemuat mengabaikan VML rusak.
        yield 'VML dengan <br> tanpa penutup' => [['xl/drawings/vmlDrawing1.vml' => '<xml xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">'
            . '<v:shape id="_x0000_s1025"><v:textbox><div style="text-align:left">baris1<br>baris2</div></v:textbox><x:ClientData ObjectType="Note"><x:Row>0</x:Row><x:Column>0</x:Column></x:ClientData></v:shape></xml>']];
        yield 'VML rusak dengan kata shared di teks kontrol' => [['xl/drawings/vmlDrawing2.vml' => '<xml xmlns:v="urn:schemas-microsoft-com:vml"><v:shape><v:textbox><div>folder shared<br>baris2</div></v:textbox></v:shape></xml>']];
        yield 'SVG dengan prefiks namespace tak dideklarasikan' => [['xl/media/image2.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><sodipodi:namedview/><g><path d="M0 0"/></g></svg>']];
        yield 'gambar biner' => [['xl/media/image1.png' => "\x89PNG\r\n\x1a\n" . str_repeat("\x00\xFF<c>", 500)]];
        yield 'entri kosong' => [['xl/lain/kosong.xml' => '']];
    }

    /**
     * Entri yang hitungannya tidak bisa dipercaya → berkas ditolak sebagai tidak terbaca, di entri mana pun: deklarasi
     * encoding selain UTF-8 (pemuat mengonversi isinya sebelum diurai, jadi byte yang dipindai bukan byte yang diurai).
     */
    #[DataProvider('entriTidakTerpercaya')]
    public function testEntriTidakTerpercayaDitolak(string $isi): void
    {
        $entri                      = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]);
        $entri['xl/lain/data1.xml'] = $isi;

        $this->assertDitolak(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $this->xlsxEntri($entri));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function entriTidakTerpercaya(): iterable
    {
        $lembar = '<worksheet xmlns="' . XlsxMentah::UTAMA . '"><sheetData/></worksheet>';

        yield 'deklarasi UTF-7' => ['<?xml version="1.0" encoding="UTF-7"?>' . $lembar];
        yield 'deklarasi sesudah spasi panjang' => ['<?xml version="1.0" encoding' . str_repeat(' ', 70_000) . '="ISO-8859-1"?>' . $lembar];
    }

    /**
     * Entri UTF-16 ber-BOM tanpa deklarasi encoding diurai apa adanya, jadi selnya ikut dihitung.
     */
    public function testEntriUtf16TanpaDeklarasiIkutDihitung(): void
    {
        $entri                      = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk()]);
        $entri['xl/lain/data1.xml'] = "\xFF\xFE" . mb_convert_encoding('<worksheet xmlns="' . XlsxMentah::UTAMA . '"><sheetData>' . XlsxMentah::baris(1, ['a', 'b', 'c']) . '</sheetData></worksheet>', 'UTF-16LE', 'UTF-8');
        $jumlah                     = 2 * count(self::headerGpp()) + 2 * count(self::headerTk()) + 3;
        $path                       = $this->xlsxEntri($entri);

        $this->assertDiterima($path, new PembacaExcelSlip(10000, 256, $jumlah));
        $this->assertDitolak(self::pesanSel($jumlah - 1), $path, new PembacaExcelSlip(10000, 256, $jumlah - 1));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // .xls dan reader terkunci
    // ------------------------------------------------------------------------------------------------------------------

    public function testXlsBarisMelebihiBatasDitolak(): void
    {
        $path = $this->xls();

        $this->assertDitolak('Jumlah baris melebihi batas 2 per sheet.', $path, new PembacaExcelSlip(2));
        $this->assertDiterima($path, new PembacaExcelSlip(3));
    }

    /**
     * `.xls`: batas sel memakai baris × kolom sheet GPP dan TK (4 × 26 + 2 × 9).
     */
    public function testXlsJumlahSelLewatBatasDitolak(): void
    {
        $path   = $this->xls();
        $jumlah = 4 * count(self::headerGpp()) + 2 * count(self::headerTk());

        $this->assertDiterima($path, new PembacaExcelSlip(10000, 256, $jumlah));
        $this->assertDitolak(self::pesanSel($jumlah - 1), $path, new PembacaExcelSlip(10000, 256, $jumlah - 1));
    }

    /**
     * [V2] Berkas yang bukan Excel tetapi dikenali reader lain (HTML) tidak lagi dibaca: reader dikunci ke Xlsx/Xls.
     */
    public function testBerkasHtmlBerkedokXlsTidakDibaca(): void
    {
        $path = $this->temp('.xls');
        file_put_contents($path, '<html><body><table><tr><td>nip</td><td>bulan</td></tr></table></body></html>');

        $this->assertDitolak(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $path);
    }

    // ------------------------------------------------------------------------------------------------------------------

    private function assertDitolak(string $pesan, string $path, ?PembacaExcelSlip $pembaca = null): void
    {
        memory_reset_peak_usage();
        $awal = memory_get_peak_usage();

        try {
            ($pembaca ?? new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame($pesan, $e->getMessage());
        }

        $this->assertLessThan(self::BATAS_MEMORI, memory_get_peak_usage() - $awal, 'kenaikan puncak memori');
    }

    /**
     * @return array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>}
     */
    private function assertDiterima(string $path, ?PembacaExcelSlip $pembaca = null): array
    {
        $hasil = ($pembaca ?? new PembacaExcelSlip(10000))->baca($path);
        $this->assertTrue($hasil->valid);
        /** @var array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $sheet */
        $sheet = $hasil->nilai;

        return $sheet;
    }

    private static function pesanSel(int $batas): string
    {
        return 'Jumlah sel melebihi batas ' . number_format($batas, 0, ',', '.') . ' per berkas. Hapus sheet atau kolom yang tidak dipakai, lalu unggah ulang.';
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

    /**
     * Isi `<sheetData>` GPP sah: header + satu baris data.
     */
    private static function sheetGpp(): string
    {
        return XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp());
    }

    /**
     * Isi `<sheetData>` GPP padat: header + 250.000 baris data (2 sel per baris) = 500.003 sel.
     */
    private static function gppPadat(): string
    {
        $gpp = XlsxMentah::baris(1, ['nip', 'bulan', 'tahun']);

        for ($r = 2; $r <= 250_001; $r++) {
            $gpp .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::sel('B' . $r, 9) . '</row>';
        }

        return $gpp;
    }

    private static function sheetTk(): string
    {
        return XlsxMentah::baris(1, self::headerTk()) . XlsxMentah::baris(2, self::dataTk());
    }

    /**
     * Sel header baris 1 tanpa pembungkus `<row>` (supaya sel lain bisa ditambahkan ke baris yang sama).
     *
     * @param list<string> $header
     */
    private static function selHeader(array $header): string
    {
        return substr(XlsxMentah::baris(1, $header), strlen('<row r="1">'), -strlen('</row>'));
    }

    /**
     * @param array<string, string> $sheet
     */
    private function xlsx(array $sheet): string
    {
        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, $sheet);

        return $path;
    }

    /**
     * @param array<string, string> $entri
     */
    private function xlsxEntri(array $entri): string
    {
        $path = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($path, $entri);

        return $path;
    }

    /**
     * Workbook sah + sheet `Lain` berisi `$isiLain`, dengan `sharedStrings.xml` berisi `$teks` (indeks 0, 1, …).
     *
     * @param list<string> $teks
     */
    private function xlsxSharedString(string $isiLain, array $teks): string
    {
        $entri                               = XlsxMentah::entri(['GPP' => self::sheetGpp(), 'TK' => self::sheetTk(), 'Lain' => $isiLain]);
        $entri['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', $entri['xl/_rels/workbook.xml.rels']);
        $entri['xl/sharedStrings.xml']       = XlsxMentah::KEPALA . '<sst xmlns="' . XlsxMentah::UTAMA . '">' . implode('', array_map(static fn (string $t): string => '<si><t>' . $t . '</t></si>', $teks)) . '</sst>';

        return $this->xlsxEntri($entri);
    }

    /**
     * Sel data GPP baris 2 tanpa pembungkus `<row>`.
     */
    private static function selHeaderData(): string
    {
        return substr(XlsxMentah::baris(2, self::dataGpp()), strlen('<row r="2">'), -strlen('</row>'));
    }

    /**
     * `.xls` sah: GPP header + 3 baris data, TK header + 1 baris data.
     */
    private function xls(): string
    {
        $buku = new Spreadsheet();
        $gpp  = $buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP);
        $gpp->fromArray([self::headerGpp(), self::dataGpp(), self::dataGpp(), self::dataGpp()], null, 'A1', true);
        $tk = $buku->createSheet()->setTitle(KolomGaji::SHEET_TK);
        $tk->fromArray([self::headerTk(), self::dataTk()], null, 'A1', true);
        $path = $this->temp('.xls');
        IOFactory::createWriter($buku, 'Xls')->save($path);
        $buku->disconnectWorksheets();

        return $path;
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
