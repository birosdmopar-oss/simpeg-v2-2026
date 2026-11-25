<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\Slip\PemuatXlsxSlip;
use App\Libraries\Presensi\Slip\PenaksirBebanRumus;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\Presensi\XlsxMentah;
use ZipArchive;

/**
 * CR-181 — anggaran beban hitung rumus impor Slip Gaji. Sel rumus GPP/TK tetap DIHITUNG ULANG (legacy, AC-D10.9); yang
 * ditambahkan hanya taksiran bebannya dari teks rumus sebelum dihitung:
 *
 * - rumus yang kerjanya tidak wajar (rentang raksasa, rentang dinamis, terlalu banyak sel rumus) ditolak 422 dengan pesan
 *   yang menuntun, cepat dan tanpa memori besar, bukan 500 karena waktu/memori habis;
 * - untuk berkas yang diterima, nilainya SAMA dengan kalkulator PhpSpreadsheet yang dijalankan langsung pada berkas itu
 *   ({@see self::nilaiKalkulator()}), termasuk sel rumus tanpa nilai tersimpan (`<v>`).
 *
 * Berkas uji `.xlsx` ditulis sebagai XML mentah ({@see XlsxMentah}): sel rumusnya tanpa `<v>`, jadi nilai yang benar hanya
 * bisa berasal dari hitung ulang.
 *
 * @internal
 */
final class PembacaExcelSlipAnggaranRumusTest extends CIUnitTestCase
{
    private const NIP          = '199001012020011001';
    private const PPH          = '=ROUND(IF(G2<=5000000,G2*5%,IF(G2<=25000000,250000+(G2-5000000)*15%,IF(G2<=50000000,3250000+(G2-25000000)*25%,9500000+(G2-50000000)*30%))),0)';
    private const BATAS_MEMORI = 64 * 1024 * 1024;
    private const BATAS_DETIK  = 5.0;

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
    // Ditolak sebelum dihitung
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Satu sel total hasil Ctrl+Shift+Down. Sebelum CR-181: kalkulator menjabarkan 1.048.575 sel (ratusan detik, lalu
     * memori habis), dan admin menerima 500.
     */
    public function testRentangRaksasaLintasSheetDitolakCepat(): void
    {
        $path = $this->xlsx(tk: [XlsxMentah::rumus('M2', '=SUM(GPP!J2:J1048576)')]);

        $this->assertDitolak($path);
    }

    /**
     * @param list<string>          $gpp  sel rumus tambahan di baris 2 GPP
     * @param list<string>          $tk   sel rumus tambahan di baris 2 TK
     * @param array<string, string> $nama nama terdefinisi => isi
     */
    #[DataProvider('rumusBerat')]
    public function testRumusBeratDitolakSebelumDihitung(array $gpp, array $tk, array $nama = []): void
    {
        $this->assertDitolak($this->xlsx($gpp, $tk, $nama));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>, 2?: array<string, string>}>
     */
    public static function rumusBerat(): iterable
    {
        $gpp = static fn (string $rumus): array => [[XlsxMentah::rumus('AB2', $rumus)], []];
        $tk  = static fn (string $rumus): array => [[], [XlsxMentah::rumus('M2', $rumus)]];

        yield 'seluruh lembar' => $gpp('=SUM(A1:XFD1048576)');
        yield 'satu kolom penuh ditulis eksplisit' => $gpp('=SUM(J2:J1048576)');
        yield 'nama sheet berkutip' => $tk("=SUM('GPP'!J2:J1048576)");
        yield 'rujukan mutlak' => $tk('=SUM(GPP!$J$2:$J$1048576)');
        yield 'rantai operator rentang' => $gpp('=SUM(A1:B2:J1048576)');
        yield 'rentang raksasa di dalam fungsi lain' => $gpp('=ROUND(IF(E2>0,COUNT(J2:J1048576),0),0)');
        // Kalkulator bisa melewati cabang IF yang tidak terpakai; taksiran sengaja menghitung semua cabang.
        yield 'rentang raksasa di cabang IF' => $gpp('=IF(1=2,SUM(J2:J1048576),0)');
        yield 'INDIRECT' => $gpp('=SUM(INDIRECT("J2:J1048576"))');
        yield 'INDIRECT kecil pun dianggap dinamis' => $gpp('=SUM(INDIRECT("E2:F2"))');
        yield 'OFFSET' => $gpp('=SUM(OFFSET(A1,0,0,1048576,1))');
        yield 'pembangkit larik SEQUENCE' => $gpp('=SUM(_xlfn.SEQUENCE(1048576,50))');
        yield 'ujung rentang hasil fungsi' => $gpp('=SUM(E2:INDEX(J:J,1048576))');
        yield 'ujung rentang hasil hitung' => $gpp('=SUM(E2:(1048576))');
        yield 'rujukan 3D' => $gpp('=SUM(GPP:TK!A1)');
        yield 'rumus tidak terurai' => $gpp('=SUM(E2:F2');
        yield 'rentang bernama raksasa' => [...$gpp('=SUM(BESAR)'), ['BESAR' => 'GPP!$J$2:$J$1048576']];
        yield 'rumus bernama raksasa' => [...$tk('=TOTAL+1'), ['TOTAL' => 'SUM(GPP!$J$2:$J$1048576)']];
        yield 'nama berantai ke rentang raksasa' => [...$tk('=LUAR'), ['LUAR' => 'DALAM+1', 'DALAM' => 'SUM(GPP!$J$2:$J$1048576)']];
        yield 'nama terdefinisi berputar' => [...$tk('=PUTAR1'), ['PUTAR1' => 'PUTAR2+1', 'PUTAR2' => 'PUTAR1+1']];

        // Nama bercabang 10 pangkat 7: penaksiran harus berhenti begitu batas terlewati, bukan menelusuri semuanya.
        $cabang = ['Nm_7' => '1'];

        for ($i = 0; $i < 7; $i++) {
            $cabang['Nm_' . $i] = implode('+', array_fill(0, 10, 'Nm_' . ($i + 1)));
        }

        yield 'nama bercabang 10 pangkat 7' => [...$tk('=Nm_0'), $cabang];

        // Rumus lebih panjang dari batas Excel (8.192 karakter): biaya urai kalkulator kuadratik.
        yield 'rumus 200 ribu karakter tanpa titik dua' => $tk('=' . implode('+', array_fill(0, 66_667, 'A1')));
        yield 'rumus satu karakter di atas batas Excel' => $gpp('=' . str_repeat('1+', 4095) . '11');
        // REPT memanggil str_repeat sebelum memeriksa panjang hasil.
        yield 'REPT berjumlah ulang raksasa' => $gpp('=REPT("x",2000000000)');
        yield 'REPT berjumlah ulang bukan literal' => $gpp('=REPT("0",18-LEN(A2))');
        yield 'COUNTIFS lima rentang 10.000 baris' => $tk('=COUNTIFS(GPP!$C$2:$C$10001,">5",GPP!$A$2:$A$10001,">5",GPP!$C$2:$C$10001,"<90",GPP!$D$2:$D$10001,">5",GPP!$E$2:$E$10001,"<90")');

        // Review R2 R3-3: hanya fungsi di daftar putih yang lolos. Fungsi lain biayanya bisa bergantung pada NILAI
        // argumen atau membangkitkan larik dari satu sel, dan itu tidak terlihat dari teks rumus (sebelumnya beban
        // puluhan, lalu fatal memori / lebih dari 60 detik).
        yield 'TEXTSPLIT (larik dari satu sel, tanpa titik dua)' => $tk('=SUM(_xlfn.TEXTSPLIT(GPP!D2,"x",",")*_xlfn.TEXTSPLIT(GPP!D2,","))');
        yield 'CRITBINOM (biaya menurut nilai argumen)' => $gpp('=CRITBINOM(3000,0.5,0.99)');
        yield 'BINOM.INV' => $gpp('=_xlfn.BINOM.INV(1000,0.5,0.99)');
        yield 'CUMIPMT' => $gpp('=CUMIPMT(0.01,10000,1000,1,10000,0)');
        yield 'CUMPRINC' => $gpp('=CUMPRINC(0.01,10000,1000,1,10000,0)');
        yield 'MODE' => $gpp('=MODE(E2:G2)');
        yield 'MODE.SNGL' => $gpp('=_xlfn.MODE.SNGL(E2:G2)');
        yield 'FREQUENCY (belum diimplementasikan kalkulator)' => $gpp('=SUM(FREQUENCY(E2:G2,E2:F2))');
        yield 'fungsi lain di luar daftar (BESSELJ)' => $gpp('=BESSELJ(1,2)');
        yield 'MMULT' => $gpp('=SUM(MMULT(E2:F2,TRANSPOSE(E2:F2)))');
        yield 'fungsi yang tidak dikenal kalkulator' => $gpp('=FUNGSIKU(E2)');
        yield 'fungsi di luar daftar di rumus tanpa rentang (jalur cepat)' => $gpp('=ROUND(E2*2,0)+CRITBINOM(10,0.5,0.9)');
        yield 'fungsi di luar daftar berspasi sebelum kurung' => $gpp('=ROUND(E2*2,0)+CRITBINOM (10,0.5,0.9)');
        yield 'fungsi di luar daftar di dalam nama terdefinisi' => [...$tk('=LUAR+1'), ['LUAR' => 'CRITBINOM(3000,0.5,0.99)']];

        // Kriteria wildcard berantai: tiap sel dicocokkan dengan pola `.*` berantai (±2,7 ms per sel, 10.000 sel ±27 detik
        // per rumus, padahal luas rentangnya sama dengan kriteria biasa).
        yield 'COUNTIF berkriteria wildcard berantai' => $tk('=COUNTIF(GPP!$D$2:$D$10001,"*a*a*a*a*a*a*a*a*a*a*b")');
        yield 'SUMIFS berkriteria wildcard berantai' => $tk('=SUMIFS(GPP!$E$2:$E$10001,GPP!$D$2:$D$10001,"*a*a*a*a*a*a*a*a*a*a*b")');
        yield 'MATCH bernilai cari wildcard berantai' => $tk('=MATCH("*a*a*a*a*a*a*a*a*a*a*b",GPP!$D$2:$D$10001,0)');
        yield 'wildcard berantai hasil REPT' => $tk('=COUNTIF(GPP!$D$2:$D$10001,REPT("*a",10)&"*b")');
        // SUBSTITUTE menyusun hasilnya sebelum memeriksa panjang: teks sumber × teks pengganti.
        yield 'SUBSTITUTE berpengganti raksasa' => $gpp('=LEN(SUBSTITUTE(D2,"a",REPT("b",3000000)))');

        // Larik hasil operator dan fungsi: tiap operand membawa ukuran lariknya (review R1 K3). Sebelumnya operator atas
        // rentang tidak ditagih, sehingga satu sel berbeban < 2% anggaran dihitung lebih dari 60 detik atau menghabiskan
        // memori.
        yield 'operator berantai atas rentang' => $tk('=SUM(GPP!$C$2:$C$10001' . str_repeat('*1', 400) . ')');
        yield 'negasi berantai atas rentang' => $tk('=SUM(' . str_repeat('-', 400) . 'GPP!$C$2:$C$10001)');
        yield 'perbandingan berantai atas rentang' => $tk('=SUM(' . str_repeat('(', 150) . 'GPP!$C$2:$C$10001' . str_repeat('>0)*1', 150) . ')');
        yield 'perkalian luar kolom kali baris' => $tk('=SUMPRODUCT(GPP!$C$2:$C$3001*TRANSPOSE(GPP!$C$2:$C$3001))');
        yield 'penjumlahan luar di dalam MAX' => $tk('=MAX(GPP!$C$2:$C$3801+TRANSPOSE(GPP!$C$2:$C$3801))');
        yield 'larik literal baris kali larik literal kolom' => $gpp('=SUM({' . implode(',', array_fill(0, 1500, '1')) . '}*{' . implode(';', array_fill(0, 1500, '1')) . '})');
        yield 'fungsi per elemen menyiarkan dua larik' => $tk('=SUM(ROUND(GPP!$C$2:$C$3001,TRANSPOSE(GPP!$C$2:$C$3001)))');
        yield 'operator & antara rentang dan REPT literal' => $tk('=SUMPRODUCT(LEN(GPP!$B$2:$B$10001&REPT("x",30000)&""))');
        yield 'MATCH bernilai cari larik' => $tk('=SUM(MATCH(GPP!$A$2:$A$1001,GPP!$A$2:$A$1001,0))');
        yield 'VLOOKUP bernilai cari larik' => $tk('=SUM(VLOOKUP(GPP!$A$2:$A$1001,GPP!$A$2:$C$1001,3,0))');
        yield 'larik hasil operator lewat nama terdefinisi' => [...$tk('=SUM(LUAS*TRANSPOSE(LUAS))'), ['LUAS' => 'GPP!$C$2:$C$3001*1']];
        yield 'MAX atas 24 kolom kali 10.000 baris' => $tk('=MAX(GPP!$C$2:$Z$10001)');

        // Review R3 B1: kalkulator melewati token cabang IF yang dipangkas lalu memproses token sesudahnya; penaksir dulu
        // berhenti di rujukan workbook eksternal / variabel `_xleta`, sehingga INDIRECT sesudahnya lolos dan dihitung.
        yield 'rujukan workbook eksternal di cabang IF lalu INDIRECT' => $tk('=IF(FALSE,[1]Sheet1!A1,0)+INDIRECT("C2")');
        yield 'variabel _xleta di cabang IF lalu INDIRECT' => $tk('=IF(FALSE,_xleta.x,0)+INDIRECT("C2")');
        // Review R3 B4: REPT dengan teks dari sel dulu dianggap 32 byte; 6.000 huruf × 90.000 = 540 MB, fatal memori 512M.
        yield 'REPT teks sel 6.000 huruf' => [[XlsxMentah::sel('AC2', str_repeat('a', 6000)), XlsxMentah::rumus('AB2', '=LEN(REPT($AC$2,90000))')], []];
        // Review R3 B2: kode format TEXT dari sel ditafsirkan dengan biaya kuadratik (32.000 `?` ±0,43 detik per panggilan);
        // format lebih panjang dari batas Excel (255) pasti ditolak.
        yield 'TEXT berformat sel 300 karakter' => [[XlsxMentah::sel('AC2', str_repeat('0', 300)), XlsxMentah::rumus('AB2', '=LEN(TEXT(E2,$AC$2))')], []];
        // Review R3 P2: XLOOKUP kelas pencarian.
        yield 'XLOOKUP match_mode 2 wildcard berantai' => $tk('=_xlfn.XLOOKUP("*a*a*a*a*a*a*a*a*a*a*b",GPP!$D$2:$D$10001,GPP!$E$2:$E$10001,0,2)');
        // D1-b (keputusan Horii 25-11): MAXIFS kelas berkriteria; TEXTJOIN dibatasi hasil 4 MB.
        yield 'MAXIFS empat pasang kriteria 10.000 baris' => $tk('=MAXIFS(GPP!$E$2:$E$10001,GPP!$C$2:$C$10001,">5",GPP!$A$2:$A$10001,">5",GPP!$C$2:$C$10001,"<90",GPP!$D$2:$D$10001,">5")');
        yield 'TEXTJOIN hasil di atas 4 MB' => [[XlsxMentah::sel('AC2', str_repeat('a', 6000)), XlsxMentah::rumus('AB2', '=LEN(_xlfn.TEXTJOIN(",",TRUE,$AC$2:$AC$1000))')], []];
        yield 'NETWORKDAYS tetap di luar daftar (D1-d)' => $gpp('=NETWORKDAYS(1,2958465)');
        yield 'XLOOKUP bernilai cari larik 1.000 × 1.000' => $tk('=SUM(_xlfn.XLOOKUP(GPP!$A$2:$A$1001,GPP!$A$2:$A$1001,GPP!$C$2:$C$1001))');
    }

    /**
     * Review R4 (korpus): rujukan dan RENTANG ke workbook eksternal ber-nilai tersimpan (`<v>`) tetap diterima seperti di
     * main. Kalkulator tidak menjabarkan rentang eksternal (berhenti dengan "Unable to access External Workbook", lalu sel
     * memakai nilai tersimpannya); operand buram eksternal di operator `:` bukan rentang dinamis.
     */
    public function testRentangWorkbookEksternalBernilaiTersimpanDiterima(): void
    {
        $sel = [
            '<c r="AB2"><f>SUM([1]Sheet1!$A$1:$A$10)+E2</f><v>1005</v></c>',
            '<c r="AC2"><f>[1]Sheet1!B2*2</f><v>42</v></c>',
        ];
        $sheet = $this->assertDiterima($this->xlsx($sel));

        $this->assertEquals(1005, $sheet['GPP'][2][27], 'GPP!AB2 = nilai tersimpan (rentang eksternal)');
        $this->assertEquals(42, $sheet['GPP'][2][28], 'GPP!AC2 = nilai tersimpan (sel eksternal)');
    }

    /**
     * Review R3 B3: `SEARCH`/`FIND` pada kasus terburuk ±byte teks cari × byte teks sumber per panggilan (15.000 × 30.000
     * byte ±0,1 detik). 700 sel dulu ditaksir linear (±740 per sel) lalu dihitung 51,8 detik; kini tiap sel ±30 ribu dan
     * jumlahnya melewati anggaran. Teks pendek tetap murah (dijaga `testFungsiLazimTambahanDiterimaDenganNilaiSama`).
     */
    public function testSearchTeksPanjangDitolakLewatJumlahAnggaran(): void
    {
        $tk = XlsxMentah::baris(1, self::headerTk())
            . '<row r="2">' . XlsxMentah::sel('A2', self::NIP) . XlsxMentah::sel('N2', str_repeat('a', 30000)) . XlsxMentah::sel('O2', str_repeat('a', 14999) . 'b') . '</row>';

        for ($r = 3; $r <= 702; $r++) {
            $tk .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::rumus('M' . $r, '=IFERROR(SEARCH($O$2,$N$2),0)') . '</row>';
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => self::sheetGpp(), 'TK' => $tk]);

        $this->assertDitolak($path);
    }

    /**
     * 2.000 baris TK ber-`VLOOKUP` ke seluruh GPP: tiap sel masih di bawah batas per rumus, jumlahnya tidak (±1,3 detik per
     * sel bila dihitung; sebelum CR-181 berakhir "Maximum execution time").
     */
    public function testBanyakRujukanSedangDitolakLewatJumlahAnggaran(): void
    {
        $rumus = '';

        for ($r = 2; $r <= 2001; $r++) {
            $rumus .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::rumus('M' . $r, '=VLOOKUP(A' . $r . ',GPP!$A$1:$Z$10001,5,0)') . '</row>';
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => self::sheetGpp(), 'TK' => XlsxMentah::baris(1, self::headerTk()) . $rumus]);

        $this->assertDitolak($path);
    }

    /**
     * Sama dengan di atas untuk `.xls` (taksiran berjalan pada workbook yang sudah dimuat, jadi berlaku untuk kedua format).
     */
    public function testXlsRumusBeratDitolak(): void
    {
        $buku = self::bukuSah();
        $tk   = $buku->getSheetByName(KolomGaji::SHEET_TK);
        $this->assertNotNull($tk);

        for ($r = 2; $r <= 21; $r++) {
            $tk->setCellValue('M' . $r, '=VLOOKUP(A' . $r . ',GPP!$A$1:$Z$10001,5,0)');
        }

        $path = $this->temp('.xls');
        IOFactory::createWriter($buku, 'Xls')->setPreCalculateFormulas(false)->save($path);
        $buku->disconnectWorksheets();

        $this->assertDitolak($path);
    }

    /**
     * Batas jumlah beban juga menangkap "terlalu banyak sel rumus ringan" (mis. 26 kolom rumus × 10.000 baris): tiap sel
     * rumus berbeban paling sedikit beban dasar ditambah beban rujukan dan operatornya.
     */
    public function testBanyakSelRumusRinganDitolakLewatJumlahAnggaran(): void
    {
        $sel = [];

        // 40 sel "=E2+1": beban dasar + satu rujukan + dua operator (`=` dan `+`); suku kuadratik panjangnya nol.
        for ($c = 28; $c < 68; $c++) {
            $sel[] = XlsxMentah::rumus(XlsxMentah::kolom($c) . '2', '=E2+1');
        }

        $path  = $this->xlsx($sel);
        $beban = 40 * (Presensi::BEBAN_DASAR_RUMUS_IMPOR + Presensi::BEBAN_RUJUKAN_RUMUS_IMPOR + 2 * Presensi::BEBAN_OPERATOR_RUMUS_IMPOR);

        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanRumus: $beban - 1));
        $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanRumus: $beban));
    }

    /**
     * Satuan beban, dihitung tangan untuk rentang 600 baris × 10 kolom (dan 600 × 1): beban dasar sel (8 + rujukan × 6 +
     * operator × 2 + kurung × 7 + pemisah × 4) + tiap rentang (20 + baris × kolom + baris² × (kolom + 1) / 12.000).
     * Sebagai argumen fungsi, suku kuadratiknya memakai pembagi kelas fungsi (`SUBTOTAL` 2 × 2.000, fungsi lain 2 × 900)
     * dan luasnya dikalikan untuk fungsi berkriteria; operator atas larik menagih satu satuan per elemen hasil; nilai
     * cari berupa larik mengalikan biaya larik yang ditelusuri.
     */
    #[DataProvider('bebanRentang')]
    public function testBebanRentangDihitungDariLuasBarisDanFungsi(string $rumus, int $beban): void
    {
        $path = $this->xlsx([XlsxMentah::rumus('AB2', $rumus)]);

        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $beban - 1));
        $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $beban));
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function bebanRentang(): iterable
    {
        // Rentang A1:J600 = 20 + 6.000 + 330; A1:A600 = 20 + 600 + 60.
        yield 'SUM (murah)' => ['=SUM(A1:J600)', 29 + 6350];
        yield 'rentang tanpa fungsi' => ['=A1:J600', 22 + 6350];
        yield 'VLOOKUP (murah)' => ['=VLOOKUP(1,A1:J600,2,0)', 41 + 6350];
        yield 'SUBTOTAL (sedang, luas × 2)' => ['=SUBTOTAL(9,A1:J600)', 33 + 6350 + (990 - 330) + 6000];
        yield 'MAX (umum)' => ['=MAX(A1:J600)', 29 + 6350 + (2200 - 330)];
        yield 'COUNTIF (berkriteria, luas × 6)' => ['=COUNTIF(A1:J600,1)', 33 + 6350 + (2200 - 330) + 5 * 6000];
        yield 'rentang di dalam fungsi di dalam fungsi' => ['=ROUND(MAX(A1:J600),0)', 40 + 6350 + (2200 - 330)];
        yield 'operator atas rentang' => ['=SUM(A1:J600*2)', 31 + 6350 + 6000 + 330];
        yield 'operator atas rentang lalu fungsi umum' => ['=MAX(A1:J600*2)', 31 + 6350 + 6000 + 2200];
        yield 'fungsi per elemen atas rentang' => ['=SUM(ROUND(A1:J600,0))', 40 + 6350 + 6000 + 330];
        yield 'perkalian luar 600 × 600' => ['=SUM(A1:A600*TRANSPOSE(A1:A600))', 50 + 2 * 680 + 360_000 + 18_030];
        yield 'MATCH bernilai cari larik' => ['=SUM(MATCH(A1:A600,A1:A600,0))', 56 + 2 * 680 + 2 * (400 - 60) + 600 + 599 * (600 + 400) + 60];

        // Review R3 P2 — kelas R3 (A1:A600 = 680; B1:C600 = 20 + 1.200 + 90 = 1.310; suku kuadratik satu kolom 600 baris:
        // murah 60, sedang 180, umum 400; A1:J600: murah 330, umum 2.200).
        yield 'LOOKUP (sedang)' => ['=LOOKUP(1,A1:A600,B1:B600)', 49 + 2 * 680 + 2 * (180 - 60)];
        yield 'XLOOKUP satu kolom (umum)' => ['=XLOOKUP(1,A1:A600,B1:B600)', 49 + 2 * 680 + 2 * (400 - 60)];
        yield 'XLOOKUP returnArray dua kolom (hasil 1 × 2)' => ['=SUM(XLOOKUP(1,A1:A600,B1:C600))', 56 + 680 + 1310 + (400 - 60) + (600 - 90)];
        // match_mode 2: wildcard 1 bintang = 4 per sel larik (2 × 600); match_mode 0 tanpa tagihan wildcard.
        yield 'XLOOKUP match_mode 0' => ['=XLOOKUP("a*b",D1:D600,E1:E600,0,0)', 59 + 2 * 680 + 2 * (400 - 60)];
        yield 'XLOOKUP match_mode 2 (wildcard)' => ['=XLOOKUP("a*b",D1:D600,E1:E600,0,2)', 59 + 2 * 680 + 2 * (400 - 60) + 1200 * 4];
        // Pengurut: kelas umum dan luas × 3.
        yield 'LARGE (pengurut, luas × 3)' => ['=LARGE(A1:J600,1)', 33 + 6350 + (2200 - 330) + 2 * 6000];
        yield 'MEDIAN (pengurut)' => ['=MEDIAN(A1:J600)', 29 + 6350 + (2200 - 330) + 2 * 6000];
        yield 'RANK (pengurut)' => ['=RANK(A1,A1:J600)', 39 + 6350 + (2200 - 330) + 2 * 6000];
        // Per elemen: satu satuan per elemen larik hasil, lalu suku kuadratik SUM atas larik itu (60).
        yield 'SEARCH per elemen' => ['=SUM(SEARCH("a",D1:D600))', 40 + 680 + 600 + 60];
        yield 'DATEDIF per elemen' => ['=SUM(DATEDIF(A1:A600,B1:B600,"Y"))', 56 + 2 * 680 + 600 + 60];
        // Larik literal {1,2;3,4}: dua baris + satu larik × 20, operator atas 4 elemen.
        yield 'larik literal' => ['=SUM({1,2;3,4}*2)', 31 + 3 * 20 + 4];
    }

    /**
     * `REPT` berargumen literal ditaksir dari byte hasilnya (tiap hasil tersimpan beberapa salinan sampai impor selesai):
     * 2.000 sel × 32.767 emoji dulu lolos taksiran lalu memori habis.
     */
    public function testReptLiteralDitaksirDariUkuranHasil(): void
    {
        $rumus = '=REPT("ab",500)';
        $path  = $this->xlsx([XlsxMentah::rumus('AB2', $rumus)]);
        $dasar = Presensi::BEBAN_DASAR_RUMUS_IMPOR + Presensi::BEBAN_OPERATOR_RUMUS_IMPOR + Presensi::BEBAN_FUNGSI_RUMUS_IMPOR + Presensi::BEBAN_ARGUMEN_RUMUS_IMPOR;
        $beban = $dasar + intdiv(2 * 500, Presensi::BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR);

        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $beban - 1));
        $sheet = $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $beban));
        $this->assertSame(str_repeat('ab', 500), $sheet['GPP'][2][27]);

        $emoji = '';

        for ($r = 2; $r <= 2001; $r++) {
            $emoji .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::rumus('M' . $r, '=REPT("😀",32767)') . '</row>';
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => self::sheetGpp(), 'TK' => XlsxMentah::baris(1, self::headerTk()) . $emoji]);

        $this->assertDitolak($path);
    }

    /**
     * 59 sel `COUNTIFS` dua rentang 5.000 baris: tiap sel masih di bawah batas per rumus (±116 ribu), jumlahnya tidak.
     * Sebelum pengali fungsi berkriteria, berkas seperti ini lolos taksiran lalu dihitung lebih dari satu menit.
     */
    public function testFungsiBerkriteriaAtasRentangBesarDitolakLewatJumlahAnggaran(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp());

        for ($r = 2; $r <= 10001; $r++) {
            $gpp .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::sel('E' . $r, $r % 97) . '</row>';
        }

        $tk = XlsxMentah::baris(1, self::headerTk());

        for ($r = 2; $r <= 60; $r++) {
            $tk .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::rumus('M' . $r, '=COUNTIFS(GPP!$E$2:$E$5001,">5",GPP!$E$5002:$E$10001,"<90")') . '</row>';
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => $gpp, 'TK' => $tk]);

        $this->assertDitolak($path);
    }

    /**
     * Pesan baris/kolom tetap menang atas pesan rumus, seperti sebelum CR-181: admin yang mengunggah terlalu banyak
     * baris tidak disuruh menempel nilai dulu.
     */
    public function testPesanUkuranMenangAtasPesanRumus(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp());

        for ($r = 2; $r <= 10003; $r++) {
            $gpp .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . '</row>';
        }

        $berat = '<row r="2">' . XlsxMentah::sel('A2', self::NIP) . XlsxMentah::rumus('M2', '=SUM(GPP!J2:J1048576)') . '</row>';
        $path  = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => $gpp, 'TK' => XlsxMentah::baris(1, self::headerTk()) . $berat]);

        try {
            (new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame('Jumlah baris melebihi batas 10.000 per sheet.', $e->getMessage());
        }

        // Kolom TK lewat batas + rumus berat di GPP: pesan kolom.
        $path = $this->xlsx([XlsxMentah::rumus('AB2', '=SUM(J2:J1048576)')], [XlsxMentah::sel('IW2', 1)]);

        try {
            (new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame('Jumlah kolom melebihi batas 256 per sheet.', $e->getMessage());
        }
    }

    /**
     * Rumus tepat di batas panjang Excel (8.192 karakter) masih diterima dan dihitung.
     */
    public function testRumusTepatDiBatasPanjangExcelDiterima(): void
    {
        $rumus = '=' . str_repeat('1+', 4095) . '1';
        $this->assertSame(Presensi::MAKS_PANJANG_RUMUS_IMPOR, strlen($rumus));

        $sheet = $this->assertDiterima($this->xlsx([XlsxMentah::rumus('AB2', $rumus)]));

        $this->assertEquals(4096, $sheet['GPP'][2][27]);
    }

    /**
     * Kalibrasi: templat dengan beberapa kolom rumus wajar harus masuk anggaran (diterima sebelum CR-181, selesai di
     * bawah ±40 detik di mesin ukur; README BB-12). Beban dijumlahkan per baris NYATA: teks rumus tiap baris memuat
     * nomor barisnya sendiri (`C10001` lebih panjang dari `C2`), persis seperti di berkas, tanpa menghitung satu sel pun.
     *
     * @param list<string> $rumus rumus kolom-kolom baris 2 (nomor baris 2 diganti nomor baris sebenarnya)
     * @param list<string> $total rumus baris total (satu sel masing-masing)
     */
    #[DataProvider('templatWajar')]
    public function testTemplatBerumusWajarMasukAnggaran(array $rumus, int $baris, array $total = []): void
    {
        [$beban, $terbesar] = $this->bebanTemplat($rumus, $baris, $total);

        $this->assertLessThanOrEqual(Presensi::MAKS_BEBAN_PER_RUMUS_IMPOR, $terbesar);
        $this->assertLessThanOrEqual(Presensi::MAKS_BEBAN_RUMUS_IMPOR, $beban, 'beban berkas ' . $beban);
    }

    /**
     * Kalibrasi ujung atas (review R2 R3-1): templat yang di mesin ukur selesai lebih dari ±40 detik tetap di atas
     * anggaran (8 kolom + PPh + 2 kolom TK pada 10.000 baris = `L1_10000`, main 52 detik CPU di jendela tenang R3 dan
     * ±36 detik pada run tenang Fable; 22 kolom `ROUND(IF)`).
     *
     * @param list<string> $rumus
     */
    #[DataProvider('templatBerat')]
    public function testTemplatTerlaluBeratDiAtasAnggaran(array $rumus, int $baris): void
    {
        [$beban] = $this->bebanTemplat($rumus, $baris, []);

        $this->assertGreaterThan(Presensi::MAKS_BEBAN_RUMUS_IMPOR, $beban, 'beban berkas ' . $beban);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int}>
     */
    public static function templatBerat(): iterable
    {
        yield '10.000 baris, 8 kolom ROUND/IF/SUM + PPh + 2 kolom TK' => [[
            '=ROUND(2%*(E2+F2+G2),0)', '=ROUND(10%*(E2+F2+G2),0)', '=ROUNDUP(SUM(E2:N2),-2)-SUM(E2:N2)', '=IF(E2>0,ROUND(E2*5%,0),0)',
            '=ROUNDDOWN(SUM(E2:H2)*1%,0)', '=IF(AND(E2>0,F2>0),ROUND((E2+F2)*1%,0),0)', '=SUM(E2:Q2)-SUM(R2:Y2)', '=E2-F2', self::PPH, '=H2', '=G2+I2-H2',
        ], 10000];
        yield '10.000 baris, 22 kolom ROUND(IF)' => [array_fill(0, 22, '=ROUND(IF(C2>0,C2*0.05+D2*0.1-E2,0),0)'), 10000];
    }

    /**
     * Jumlah beban dan beban sel terbesar satu templat, per baris nyata, tanpa menghitung satu sel pun. Beban hanya
     * bergantung pada TEKS rumus, dan teks tiap baris hanya berbeda pada nomor barisnya: cukup ditaksir sekali per
     * kelompok panjang digit nomor baris (2–9, 10–99, …) lalu dikalikan jumlah baris kelompok itu (CI lebih cepat).
     *
     * @param list<string> $rumus
     * @param list<string> $total
     *
     * @return array{0: int, 1: int}
     */
    private function bebanTemplat(array $rumus, int $baris, array $total): array
    {
        $buku = self::bukuSah();
        $gpp  = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $this->assertNotNull($gpp);
        $penaksir = new PenaksirBebanRumus($buku);
        $beban    = 0;
        $terbesar = 0;

        foreach ([...$rumus, ...$total] as $i => $r) {
            $sel = $gpp->getCell(XlsxMentah::kolom(40 + $i) . '2');

            $akhir = $i < count($rumus) ? $baris + 1 : 2;

            for ($awal = 2; $awal <= $akhir; $awal = $ujung + 1) {
                $ujung = min($akhir, 10 ** strlen((string) $awal) - 1);
                $sel->setValue((string) preg_replace('/(?<=[A-Z])2\b/', (string) $awal, $r));
                $satu = $penaksir->beban($sel);
                $beban += $satu * ($ujung - $awal + 1);
                $terbesar = max($terbesar, $satu);
            }
        }

        $buku->disconnectWorksheets();

        return [$beban, $terbesar];
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int, 2?: list<string>}>
     */
    public static function templatWajar(): iterable
    {
        $pph    = '=ROUND(IF(G2<=5000000,G2*5%,IF(G2<=20000000,250000+(G2-5000000)*15%,IF(G2<=40000000,2500000+(G2-20000000)*25%,7500000+(G2-40000000)*30%))),0)';
        $ringan = ['=SUM(E2:Q2)-SUM(R2:Y2)', '=E2-F2', '=G2+I2-H2'];
        $enam   = [
            '=ROUND(2%*(E2+F2+G2),0)', '=ROUND(10%*(E2+F2+G2),0)', '=ROUNDUP(SUM(E2:N2),-2)-SUM(E2:N2)', '=IF(E2>0,ROUND(E2*5%,0),0)',
            '=SUM(E2:Q2)-SUM(R2:Y2)', '=E2-F2',
        ];
        // Baris total di bawah 9.999 baris data: 26 kolom GPP + 10 kolom TK.
        $jumlah = array_fill(0, 36, '=SUM(E$2:E$10000)');

        yield '10.000 baris, 3 kolom ROUND(IF)' => [array_fill(0, 3, '=ROUND(IF(C2>0,C2*0.05+D2*0.1-E2,0),0)'), 10000];
        yield '10.000 baris, satu kolom PPh progresif' => [[$pph], 10000];
        yield '10.000 baris, 6 kolom selisih sederhana' => [array_fill(0, 6, '=E2-F2'), 10000];
        yield '10.000 baris, 2 kolom SUM baris' => [['=SUM(E2:Z2)', '=SUM(E2:Q2)-SUM(R2:Y2)'], 10000];
        yield '3.000 pegawai, 10 kolom rumus campuran' => [[
            '=ROUND(2%*(E2+F2+G2),0)', '=ROUND(10%*(E2+F2+G2),0)', '=ROUNDUP(SUM(E2:N2),-2)-SUM(E2:N2)', '=SUM(E2:Q2)-SUM(R2:Y2)',
            '=VLOOKUP(K2,$N$2:$O$18,2,0)', '=ROUND(E2*L2,0)', '=E2-F2', $pph, '=H2', '=G2+I2-H2',
        ], 3000];
        // Review R1 K1: dua templat lazim yang dulu ditolak batas 3 juta padahal main menyelesaikannya ±30 detik.
        yield '10.000 baris, 6 kolom ROUND/IF/SUM' => [$enam, 10000];
        yield '9.999 baris, 3 kolom + PPh + baris total' => [[...$ringan, $pph], 9999, $jumlah];
        yield '5.000 baris, 6 kolom + PPh + VLOOKUP 20 baris + baris total' => [[...$enam, $pph, '=IFERROR(VLOOKUP(K2,$N$2:$O$21,2,FALSE),0)'], 5000, array_fill(0, 36, '=SUM(E$2:E$5001)')];
        yield '10.000 baris, MATCH ke referensi 200 baris' => [['=MATCH(K2,$N$2:$N$201,0)'], 10000];
        yield '9.999 baris, baris total SUBTOTAL' => [[], 9999, array_fill(0, 28, '=SUBTOTAL(9,E$2:E$10000)')];
        // Review R2 R3-1: templat lazim yang ditolak batas 4,5 juta padahal main menyelesaikannya 25–31 detik saat tenang.
        // Tepi kalibrasi DISENGAJA dikunci di sini: `10.000 baris, 6 kolom + PPh` = 5.969.896, ±30 ribu di bawah 6 juta;
        // menambah beban dasar sekecil apa pun membuat test ini merah dan menuntut kalibrasi ulang README BB-12.
        yield '10.000 baris, 6 kolom ROUND/IF/SUM + PPh' => [[...$enam, self::PPH], 10000];
        yield '5.000 baris, 6 kolom + PPh + SUMIF/COUNTIF 17 baris + LEFT/MID/TEXT + baris total' => [[
            ...$enam, self::PPH, '=SUMIF($N$2:$N$18,K2,$O$2:$O$18)', '=COUNTIF($N$2:$N$18,K2)', '=VALUE(MID(A2,5,2))',
            '=VALUE(LEFT(A2,4))+0*LEN(TEXT(E2,"#,##0"))',
        ], 5000, array_fill(0, 36, '=SUM(E$2:E$5001)')];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Penaksir tidak menyentuh workbook
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Penaksir tidak boleh membuat sel, menghitung sel, atau mengisi cache kalkulator milik workbook. Tabel dengan sel
     * judul yang tidak ada dan sel judul berumus: `StructuredReference::parse` milik vendor membuat sel judul itu dan
     * menghitungnya lewat kalkulator workbook.
     */
    public function testPenaksirTidakMembuatAtauMenghitungSel(): void
    {
        $buku = self::bukuSah();
        $gpp  = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $tk   = $buku->getSheetByName(KolomGaji::SHEET_TK);
        $this->assertNotNull($gpp);
        $this->assertNotNull($tk);

        // Tabel GPP selebar satu kolom lebih dari data; tabel TK dengan sel judul berumus.
        $akhir = XlsxMentah::kolom(count(self::headerGpp()) + 1);
        $gpp->addTable(new Table('A1:' . $akhir . '2', 'TabelGpp'));
        $tk->setCellValue('M1', 'jumlah');
        $tk->addTable(new Table('A1:M2', 'TabelTk'));
        $buku->addNamedRange(new NamedRange('POKOK', $gpp, '$E$2'));

        $tk->setCellValue('N2', '=SUM(TabelGpp[' . self::headerGpp()[4] . '])+COUNTA(TabelGpp)+POKOK+SUM(GPP!E:E)');
        $gpp->setCellValue('AD2', '=SUM(TabelTk[jumlah])+SUM(TabelTk[[#All],[nip]:[jumlah]])');
        $gpp->setCellValue('AE2', '=TabelGpp[[#This Row],[' . self::headerGpp()[4] . ']]*2');

        // Sel judul yang "tidak ditulis" dan sel judul berumus, sesudah tabel terdaftar.
        $gpp->getCellCollection()->delete($akhir . '1');
        $tk->getCell('M1')->setValueExplicit('=GPP!E2&"x"', DataType::TYPE_FORMULA);
        Calculation::getInstance($buku)->clearCalculationCache();

        $selGpp   = $gpp->getCoordinates();
        $selTk    = $tk->getCoordinates();
        $penaksir = new PenaksirBebanRumus($buku);

        foreach ([[$tk, 'N2'], [$gpp, 'AD2'], [$gpp, 'AE2'], [$tk, 'M1']] as [$lembar, $koordinat]) {
            $beban = $penaksir->beban($lembar->getCell($koordinat));

            $this->assertGreaterThan(Presensi::BEBAN_DASAR_RUMUS_IMPOR, $beban, $koordinat);
            $this->assertLessThan(10_000, $beban, $koordinat);
        }

        $this->assertSame($selGpp, $gpp->getCoordinates(), 'tidak ada sel GPP yang dibuat');
        $this->assertSame($selTk, $tk->getCoordinates(), 'tidak ada sel TK yang dibuat');

        $cache = new ReflectionProperty(Calculation::class, 'calculationCache');
        $this->assertSame([], $cache->getValue(Calculation::getInstance($buku)), 'cache kalkulator workbook tetap kosong');

        $buku->disconnectWorksheets();
    }

    /**
     * Lewat pembaca: tabel yang sel judulnya tidak ada di berkas tidak menambah kolom pada hasil baca (dulu penaksir
     * membuat sel judul itu sebelum dimensi sheet dihitung), dan rujukan terstruktur tetap dihitung.
     */
    public function testTabelBerjudulHilangTidakMengubahHasilBaca(): void
    {
        $buku = self::bukuSah();
        $gpp  = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $tk   = $buku->getSheetByName(KolomGaji::SHEET_TK);
        $this->assertNotNull($gpp);
        $this->assertNotNull($tk);

        $lebar = count(self::headerGpp());
        $akhir = XlsxMentah::kolom($lebar + 1);
        $gpp->setCellValue($akhir . '1', 'kosong');
        $gpp->addTable(new Table('A1:' . $akhir . '2', 'TabelGpp'));
        $tk->setCellValue('M2', '=SUM(TabelGpp[' . self::headerGpp()[4] . '])');

        $path = $this->temp('.xlsx');
        IOFactory::createWriter($buku, 'Xlsx')->save($path);
        $buku->disconnectWorksheets();

        // Buang sel judul kolom terakhir tabel dari XML sheet GPP.
        $this->ubahEntri($path, 'xl/worksheets/sheet1.xml', static function (string $xml) use ($akhir): string {
            $baru = preg_replace('~<c r="' . $akhir . '1"[^>]*>.*?</c>~s', '', $xml, 1, $jumlah);
            self::assertSame(1, $jumlah);

            return (string) $baru;
        });

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertCount($lebar, $sheet['GPP'][1], 'tidak ada kolom tambahan di GPP');
        $this->assertCount($lebar, $sheet['GPP'][2]);
        // Nilai rujukan terstruktur lintas sheet mengikuti kalkulator apa adanya (acuan), tidak ditetapkan di sini.
        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertArrayHasKey('TK!M2', $acuan);
    }

    /**
     * Sel judul tabel berumus berat: rumus lain yang merujuk tabel itu tidak boleh memicu hitungnya sebelum anggaran
     * diperiksa (dulu `=SUM(GPP!J2:J1048576)` di sel judul dihitung saat menaksir rumus GPP, lalu waktu habis).
     */
    public function testJudulTabelBerumusBeratDitolakTanpaDihitung(): void
    {
        $buku = self::bukuSah();
        $gpp  = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $tk   = $buku->getSheetByName(KolomGaji::SHEET_TK);
        $this->assertNotNull($gpp);
        $this->assertNotNull($tk);

        $tk->setCellValue('M1', 'jumlah');
        $tk->addTable(new Table('A1:M2', 'TabelTk'));
        $gpp->setCellValue('AB2', '=SUM(TabelTk[jumlah])+E2');

        $path = $this->temp('.xlsx');
        IOFactory::createWriter($buku, 'Xlsx')->save($path);
        $buku->disconnectWorksheets();

        $this->ubahEntri($path, 'xl/worksheets/sheet2.xml', static function (string $xml): string {
            $baru = preg_replace('~<c r="M1"[^>]*>.*?</c>~s', '<c r="M1"><f>SUM(GPP!J2:J1048576)</f></c>', $xml, 1, $jumlah);
            self::assertSame(1, $jumlah);

            return (string) $baru;
        });

        $this->assertDitolak($path);
    }

    /**
     * Rentang kolom/baris penuh dipangkas ke data sheet rujukan, sama seperti kalkulator: `GPP!E:E` pada GPP dua baris
     * hanya dua sel, jadi tetap diterima walau batas per rumus kecil; ditulis eksplisit `E1:E1048576` tidak dipangkas.
     */
    public function testRentangKolomPenuhDipangkasSepertiKalkulator(): void
    {
        $kecil = new PembacaExcelSlip(10000, maksBebanPerRumus: 100);

        $sheet = $this->assertDiterima($this->xlsx(tk: [XlsxMentah::rumus('M2', '=SUM(GPP!E:E)'), XlsxMentah::rumus('N2', '=SUM(GPP!2:2)')]), $kecil);
        $this->assertEquals(1000, $sheet['TK'][2][12]);

        $this->assertDitolak($this->xlsx(tk: [XlsxMentah::rumus('M2', '=SUM(GPP!E1:E1048576)')]), $kecil);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Diterima: nilai tetap hasil hitung ulang, sama dengan kalkulator
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Sel rumus tanpa `<v>`: nilai hanya bisa benar bila dihitung ulang. Dibandingkan sel demi sel dengan kalkulator
     * PhpSpreadsheet yang dijalankan langsung pada berkas yang sama (= perilaku sebelum CR-181).
     */
    public function testRumusWajarTetapDihitungUlangDenganNilaiSama(): void
    {
        $gpp = [
            'AB2' => '=SUM(E2:Z2)',
            'AC2' => '=ROUND(E2*0.05,0)',
            'AD2' => '=IF(E2>0,SUM(E2:F2),0)',
            'AE2' => '=TK!E2+1',
            'AF2' => '="jumlah: "&E2',
            'AG2' => '=SUM(E2:F2 F2:G2)',
            'AH2' => '=SUM((E2:F2,G2))',
            'AI2' => '=SUM(E:E)',
            'AJ2' => '=SUM(2:2)',
            'AK2' => '=TIDAKADA+1',
            'AL2' => '=SUM(Hilang!A1:B2)',
            'AM2' => '=AB2*2',
            'AN2' => '=E2/0',
            'AO2' => '=COUNTA(A1:Z2)',
            // Kalkulator menjadikan ujung rentang sesudah nama terdefinisi "#REF!": tidak ada yang dijabarkan.
            'AP2' => '=SUM(DAFTAR:J1048576)',
        ];
        $tk = [
            'M2' => '=GPP!E2*2',
            'N2' => "=SUM('GPP'!E2:F2)",
            'O2' => '=VLOOKUP(A2,GPP!$A$1:$Z$2,5,0)',
            'P2' => '=POKOK+3',
            'Q2' => '=SUM(DAFTAR)',
            'R2' => '=PAJAK',
            'S2' => '=SUM(GPP!E:F)',
            'T2' => '=SUM(GPP!E2:F2,TK!E2)',
            'U2' => '=SUM(POKOK:G2)',
            'V2' => '=GPP!AB2+M2',
        ];
        $path = $this->xlsx(
            array_map(XlsxMentah::rumus(...), array_keys($gpp), $gpp),
            array_map(XlsxMentah::rumus(...), array_keys($tk), $tk),
            ['POKOK' => 'GPP!$E$2', 'DAFTAR' => 'GPP!$E$2:$G$2', 'PAJAK' => 'ROUND(GPP!$E$2*0.05,0)'],
        );

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertCount(count($gpp) + count($tk), $acuan);
        $this->assertSamaDenganAcuan($acuan, $sheet);

        // Beberapa nilai disebut eksplisit supaya test tidak hanya membandingkan dua jalur yang sama-sama salah.
        $this->assertEquals(1000 + 20 + 3, $sheet['GPP'][2][27], 'GPP!AB2 = SUM(E2:Z2)');
        $this->assertEquals(50, $sheet['GPP'][2][28], 'GPP!AC2 = ROUND(E2*0.05,0)');
        $this->assertEquals(6, $sheet['GPP'][2][30], 'GPP!AE2 = TK!E2+1');
        $this->assertSame('jumlah: 1000', $sheet['GPP'][2][31]);
        $this->assertSame('#NAME?', $sheet['GPP'][2][36]);
        $this->assertEquals(2046, $sheet['GPP'][2][38], 'GPP!AM2 = AB2*2 (rumus merujuk rumus)');
        $this->assertSame('#DIV/0!', $sheet['GPP'][2][39]);
        $this->assertEquals(2000, $sheet['TK'][2][12], 'TK!M2 = GPP!E2*2');
        $this->assertEquals(1000, $sheet['TK'][2][14], 'TK!O2 = VLOOKUP');
        $this->assertEquals(1003, $sheet['TK'][2][15], 'TK!P2 = POKOK+3');
        $this->assertEquals(1023, $sheet['TK'][2][16], 'TK!Q2 = SUM(DAFTAR)');
        $this->assertEquals(50, $sheet['TK'][2][17], 'TK!R2 = PAJAK');
        $this->assertEquals(3023, $sheet['TK'][2][21], 'TK!V2 = GPP!AB2+M2');
    }

    /**
     * Larik kecil hasil operator dan fungsi (idiom `SUMPRODUCT((rentang>0)*1)` per baris, larik literal) tetap diterima
     * walau batas per rumus kecil, dan nilainya sama dengan kalkulator.
     */
    public function testLarikKecilHasilOperatorDiterimaDenganNilaiSama(): void
    {
        $gpp = [
            'AB2' => '=SUMPRODUCT((E2:G2>0)*1)',
            'AC2' => '=SUM(E2:G2*2)',
            'AD2' => '=SUM(IF(E2:G2>5,1,0))',
            'AE2' => '=INDEX(E2:G2,MATCH(20,E2:G2,0))*2',
            'AF2' => '=SUM({1,2;3,4}*2)',
            'AG2' => '=SUM(TRANSPOSE(E2:G2)*E2:G2)',
            'AH2' => '=E2&"-"&F2',
            'AI2' => '=SUM(-E2:G2)',
            'AJ2' => '=SUM(MATCH(E2:G2,E2:G2,0))',
            'AK2' => '=LEN(REPT("ab",20)&D2)',
        ];
        $path = $this->xlsx(array_map(XlsxMentah::rumus(...), array_keys($gpp), $gpp));

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanPerRumus: 200));

        $this->assertCount(count($gpp), $acuan);
        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertEquals(3, $sheet['GPP'][2][27], 'GPP!AB2 = SUMPRODUCT((E2:G2>0)*1)');
        $this->assertEquals(2046, $sheet['GPP'][2][28], 'GPP!AC2 = SUM(E2:G2*2)');
        $this->assertEquals(40, $sheet['GPP'][2][30], 'GPP!AE2 = INDEX(…,MATCH(…))*2');
        $this->assertEquals(20, $sheet['GPP'][2][31], 'GPP!AF2 = SUM({1,2;3,4}*2)');
        $this->assertSame('1000-20', $sheet['GPP'][2][33]);
    }

    /**
     * Fungsi di daftar putih (review R2 R3-3) tetap dihitung ulang dengan nilai yang sama dengan kalkulator, termasuk
     * teks literal berisi kurung (bukan pemanggilan fungsi), kriteria ber-`*` yang wajar, dan `SUBSTITUTE` biasa.
     */
    public function testFungsiDaftarPutihDiterimaDenganNilaiSama(): void
    {
        $gpp = [
            'AB2' => '="Gaji (bruto) "&E2',
            'AC2' => '=COUNTIF(E2:G2,">5")',
            'AD2' => '=COUNTIF(D2:D2,"*udi*")',
            'AE2' => '=SUBSTITUTE(D2," ","_")',
            'AF2' => '=IFERROR(MATCH("B*",D2:D2,0),0)',
            'AG2' => '=IFS(E2>5000,1,E2>500,2,TRUE,3)',
            'AH2' => '=CONCATENATE(LEFT(A2,4),"-",MID(A2,5,2),"-",RIGHT(A2,3))',
            'AI2' => '=TEXT(DATE(C2,B2,1),"yyyy-mm")',
            'AJ2' => '=UPPER(TRIM(D2))&LOWER(D2)&PROPER(D2)',
            'AK2' => '=CEILING(E2,300)+FLOOR(E2,300)+MOD(E2,7)+TRUNC(E2/7,1)+ABS(F2-E2)',
            'AL2' => '=IF(AND(ISNUMBER(E2),NOT(ISBLANK(D2)),OR(ISTEXT(E2),ISERROR(E2/0))),ROW(E2)+COLUMN(E2),NA())',
            'AM2' => '=SUMIFS(E2:G2,E2:G2,">5")+AVERAGEIF(E2:G2,">5")+SUMIF(E2:G2,">5")+COUNTBLANK(E2:Z2)+COUNTIFS(E2:G2,">5")+AVERAGEIFS(E2:G2,E2:G2,">5")',
            'AN2' => '=REPLACE(A2,1,4,"20")',
            'AO2' => '=HLOOKUP(20,E2:G2,1,FALSE)+SUBTOTAL(9,E2:G2)+MAX(E2:G2)-MIN(E2:G2)+AVERAGE(E2:G2)+COUNT(E2:G2)+COUNTA(E2:G2)+ROWS(E2:G2)+COLUMNS(E2:G2)',
            'AP2' => '=YEAR(DATE(2026,9,15))+MONTH(DATE(2026,9,15))+DAY(DATE(2026,9,15))+VALUE("12")+LEN(D2)+INT(E2/7)+ROUNDUP(E2/7,1)+ROUNDDOWN(E2/7,1)',
            'AQ2' => '=IFNA(VLOOKUP("x",D2:E2,2,FALSE),IF(TRUE(),1,FALSE()))',
            'AR2' => '=_xlfn.CONCAT(D2,"-",E2)',
            'AS2' => '=SUMPRODUCT(E2:G2,E2:G2)+INDEX(E2:G2,1,2)+SUM(TRANSPOSE(E2:G2))',
            'AT2' => '=REPT("0",3)&ISNA(E2)',
        ];
        $path = $this->xlsx(array_map(XlsxMentah::rumus(...), array_keys($gpp), $gpp));

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertCount(count($gpp), $acuan);
        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertSame('Gaji (bruto) 1000', $sheet['GPP'][2][27]);
        $this->assertEquals(2, $sheet['GPP'][2][28], 'GPP!AC2 = COUNTIF(E2:G2,">5")');
        $this->assertEquals(1, $sheet['GPP'][2][29], 'GPP!AD2 = COUNTIF(D2:D2,"*udi*")');
        $this->assertSame('Budi_Uji', $sheet['GPP'][2][30]);
        $this->assertEquals(1, $sheet['GPP'][2][31], 'GPP!AF2 = MATCH("B*",…)');
        $this->assertEquals(2, $sheet['GPP'][2][32], 'GPP!AG2 = IFS');
        $this->assertSame('1990-01-001', $sheet['GPP'][2][33]);
        $this->assertSame('2026-09', $sheet['GPP'][2][34]);
    }

    /**
     * CR-181 R3 butir d (keputusan reviewer): fungsi yang lazim di berkas gaji/kepegawaian masuk daftar putih sesudah
     * diukur — `SEARCH`/`FIND`, `DATEDIF`/`EDATE`/`EOMONTH` (masa kerja, tanggal), `LOOKUP`/`XLOOKUP` (kelas pencarian),
     * `LARGE`/`SMALL`/`MEDIAN`/`RANK`/`RANK.EQ` (pengurut). Diterima dengan nilai sama dengan kalkulator.
     */
    public function testFungsiLazimTambahanDiterimaDenganNilaiSama(): void
    {
        $gpp = [
            'AB2' => '=SEARCH("udi",D2)+FIND("U",D2)+IFERROR(SEARCH("x",D2),0)',
            'AC2' => '=DATEDIF(DATE(2010,3,1),DATE(2026,11,1),"Y")&" thn "&DATEDIF(DATE(2010,3,1),DATE(2026,11,1),"YM")&" bln"',
            'AD2' => '=EDATE(DATE(C2,B2,15),12)+EOMONTH(DATE(C2,B2,15),0)-EOMONTH(DATE(C2,B2,15),-1)',
            // Review R3 P1: vektor cari urut (larik literal) dan rujukan satu sel, sehingga hasilnya pasti, bukan #N/A.
            'AE2' => '=LOOKUP(25,{10,20,30},{100,200,300})+LOOKUP(1000,E2:E2,F2:F2)',
            // match_mode 2 (argumen ke-5) = wildcard; nilai cari "B*" cocok dengan "Budi Uji" → E2 = 1000.
            'AF2' => '=_xlfn.XLOOKUP(20,E2:G2,E2:G2,0)+_xlfn.XLOOKUP("B*",D2:D2,E2:E2,0,2)',
            'AG2' => '=LARGE(E2:G2,1)+SMALL(E2:G2,1)+MEDIAN(E2:G2)+RANK(E2,E2:G2)+_xlfn.RANK.EQ(F2,E2:G2,1)',
        ];
        $path = $this->xlsx(array_map(XlsxMentah::rumus(...), array_keys($gpp), $gpp));

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertCount(count($gpp), $acuan);
        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertSame('16 thn 8 bln', $sheet['GPP'][2][28]);
        $this->assertEquals(200 + 20, $sheet['GPP'][2][30], 'GPP!AE2 = LOOKUP urut (200) + LOOKUP tepat (F2 = 20)');
        $this->assertEquals(20 + 1000, $sheet['GPP'][2][31], 'GPP!AF2 = XLOOKUP tepat (20) + XLOOKUP wildcard match_mode 2 (E2 = 1000)');

        foreach ($acuan as $alamat => $nilai) {
            $this->assertFalse(is_string($nilai) && str_starts_with($nilai, '#'), "acuan {$alamat} bukan nilai galat");
        }
    }

    /**
     * D1 (keputusan Horii 25-11): fungsi skalar murah yang lazim di templat gaji masuk daftar putih (pola berkas reviewer
     * n01–n10): `MROUND`, `PRODUCT`, `N`, `POWER`, `SQRT`, `SIGN`, `ISERR`, `ISLOGICAL`, `EXACT`, `DAYS`, `WEEKDAY`,
     * `MAXIFS`/`MINIFS`, `TEXTJOIN`, `TODAY`/`NOW` (nilai = tanggal impor, sama dengan legacy). Diterima dengan nilai sama
     * dengan kalkulator.
     */
    public function testFungsiD1DiterimaDenganNilaiSama(): void
    {
        $gpp = [
            'AB2' => '=MROUND(E2,300)+PRODUCT(E2,F2,0.5)+N(E2)+N(D2)',
            'AC2' => '=POWER(G2,2)+SQRT(E2)+SIGN(F2-E2)',
            'AD2' => '=IF(ISERR(E2/0),1,0)+IF(ISLOGICAL(TRUE),1,0)+IF(EXACT(D2,"Budi Uji"),1,0)',
            'AE2' => '=DAYS(DATE(2026,11,30),DATE(2026,11,1))+WEEKDAY(DATE(2026,11,25),2)',
            'AF2' => '=_xlfn.MAXIFS(E2:G2,E2:G2,">5")+_xlfn.MINIFS(E2:G2,E2:G2,">5",E2:G2,"<5000")',
            'AG2' => '=_xlfn.TEXTJOIN("-",TRUE,D2,E2,"",G2)',
            'AH2' => '=IF(TODAY()>DATE(2000,1,1),1,0)+IF(YEAR(NOW())>=2026,1,0)',
        ];
        $path = $this->xlsx(array_map(XlsxMentah::rumus(...), array_keys($gpp), $gpp));

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertCount(count($gpp), $acuan);
        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertEquals(900 + 10000 + 1000 + 0, $sheet['GPP'][2][27], 'GPP!AB2 = MROUND + PRODUCT + N(angka) + N(teks)');
        $this->assertEquals(3, $sheet['GPP'][2][29], 'GPP!AD2 = ISERR + ISLOGICAL + EXACT');
        $this->assertEquals(29 + 3, $sheet['GPP'][2][30], 'GPP!AE2 = DAYS + WEEKDAY (Rabu, mode 2)');
        $this->assertEquals(1000 + 20, $sheet['GPP'][2][31], 'GPP!AF2 = MAXIFS + MINIFS');
        $this->assertSame('Budi Uji-1000-3', $sheet['GPP'][2][32]);
        $this->assertEquals(2, $sheet['GPP'][2][33], 'GPP!AH2 = TODAY/NOW (tanggal impor)');
    }

    /**
     * Kriteria wildcard yang isinya tidak tertulis di rumus: dibaca dari sel bernilai yang dirujuk (tanpa menghitung),
     * dan untuk sel rumus dipakai jumlah `*` terbanyak di workbook. Kriteria biasa dari sel tetap diterima.
     */
    public function testKriteriaWildcardDariSelDitaksirDariIsiSel(): void
    {
        $pola     = XlsxMentah::sel('P2', '*a*a*a*a*a*a*a*a*a*a*b');
        $kunci    = XlsxMentah::sel('Q2', 'Budi Uji');
        $langsung = $this->xlsx(tk: [$pola, XlsxMentah::rumus('M2', '=COUNTIF(GPP!$D$2:$D$10001,P2)')]);
        $lewat    = $this->xlsx(tk: [$pola, XlsxMentah::rumus('R2', '=P2'), XlsxMentah::rumus('M2', '=COUNTIF(GPP!$D$2:$D$10001,R2)')]);
        $biasa    = $this->xlsx(tk: [$pola, $kunci, XlsxMentah::rumus('M2', '=COUNTIF(GPP!$D$2:$D$10001,Q2)')]);

        $this->assertDitolak($langsung);
        $this->assertDitolak($lewat);
        $sheet = $this->assertDiterima($biasa);
        $this->assertEquals(1, $sheet['TK'][2][12]);
    }

    /**
     * Teks panjang di workbook menaikkan beban tiap operasi atas teks (fungsi teks, pembandingan, pencarian menyalin
     * seluruh teks operandnya), dan `SUBSTITUTE` ditaksir dari teks sumber × teks pengganti: 700 sel
     * `LEN(SUBSTITUTE(sel 32.767 huruf,"a",sel 2.000 huruf))` dulu berbeban 32 ribu lalu dihitung lebih dari 60 detik.
     */
    public function testTeksPanjangDitaksirDariIsiWorkbook(): void
    {
        $panjang = XlsxMentah::sel('AB2', str_repeat('a', 32767));
        $pendek  = XlsxMentah::sel('AC2', str_repeat('b', 2000));
        $teks    = intdiv(32767, Presensi::BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR);
        $dasar   = Presensi::BEBAN_DASAR_RUMUS_IMPOR + Presensi::BEBAN_RUJUKAN_RUMUS_IMPOR + Presensi::BEBAN_OPERATOR_RUMUS_IMPOR + Presensi::BEBAN_FUNGSI_RUMUS_IMPOR;

        $this->assertDitolak($this->xlsx([$panjang, $pendek, XlsxMentah::rumus('AD2', '=LEN(SUBSTITUTE(AB2,"a",AC2))')]));

        // `=UPPER(AB2)`: satu operator (`=`) dan satu kurung, masing-masing ditambah 32.767 / 128.
        $path = $this->xlsx([$panjang, XlsxMentah::rumus('AD2', '=UPPER(AB2)')]);

        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $dasar + 2 * $teks - 1));
        $sheet = $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $dasar + 2 * $teks));
        $this->assertSame(str_repeat('A', 32767), $sheet['GPP'][2][29]);

        // Tanpa teks panjang, rumus yang sama hanya berbeban dasar.
        $path = $this->xlsx([XlsxMentah::rumus('AD2', '=UPPER(D2)')]);
        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $dasar - 1));
        $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $dasar));
    }

    /**
     * Berkas tulisan writer PhpSpreadsheet (ada `<v>`, ada tabel): rujukan terstruktur dan nama tabel tetap dihitung.
     */
    #[DataProvider('format')]
    public function testBerkasTulisanWriterTetapSama(string $format): void
    {
        $buku = self::bukuSah();
        $gpp  = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $tk   = $buku->getSheetByName(KolomGaji::SHEET_TK);
        $this->assertNotNull($gpp);
        $this->assertNotNull($tk);

        $gpp->setCellValue('AB2', '=SUM(E2:Z2)');
        $gpp->setCellValue('AC2', '=AB2-G2');
        $tk->setCellValue('M2', '=GPP!E2*2');
        $tk->setCellValue('N2', '=SUM(GPP!E2:G2)');

        if ($format === 'Xlsx') {
            $gpp->addTable(new Table('A1:' . XlsxMentah::kolom(count(self::headerGpp())) . '2', 'TabelGpp'));
            $buku->addNamedRange(new NamedRange('POKOK', $gpp, '$E$2'));
            $tk->setCellValue('O2', '=SUM(TabelGpp[' . self::headerGpp()[4] . '])');
            $tk->setCellValue('P2', '=COUNTA(TabelGpp)');
            $tk->setCellValue('Q2', '=POKOK+3');
        }

        $path = $this->temp($format === 'Xls' ? '.xls' : '.xlsx');
        IOFactory::createWriter($buku, $format)->save($path);
        $buku->disconnectWorksheets();

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertEquals(1023, $sheet['GPP'][2][27]);
        $this->assertEquals(1020, $sheet['GPP'][2][28]);
        $this->assertEquals(2000, $sheet['TK'][2][12]);
        $this->assertEquals(1023, $sheet['TK'][2][13]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function format(): iterable
    {
        yield 'xlsx' => ['Xlsx'];
        yield 'xls' => ['Xls'];
    }

    /**
     * Baris total di bawah 10.000 baris data (`=SUM(E2:E10001)`) dan satu pencarian ke seluruh GPP: rumus wajar yang
     * luas rujukannya besar tetap diterima. Review R2 R3-2: `INDEX(…,MATCH(…),…)` atas 26 kolom dan `VLOOKUP` ke rentang
     * yang lebih lebar dari data (A:AN) dulu ditolak batas per sel 500.000 padahal hanya ±2–3,5 detik; nilainya sama
     * dengan kalkulator.
     */
    public function testBarisTotalDanSatuVlookupSeluruhGppDiterima(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp());

        for ($r = 2; $r <= 10001; $r++) {
            $gpp .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::sel('E' . $r, $r) . '</row>';
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, [
            'GPP' => $gpp,
            'TK'  => XlsxMentah::baris(1, self::headerTk()) . '<row r="2">' . substr(XlsxMentah::baris(2, self::dataTk()), strlen('<row r="2">'), -strlen('</row>'))
                . XlsxMentah::rumus('M2', '=SUM(GPP!E2:E10001)') . XlsxMentah::rumus('N2', '=VLOOKUP(A2,GPP!$A$1:$Z$10001,5,0)')
                . XlsxMentah::rumus('O2', '=INDEX(GPP!$A$2:$Z$10001,MATCH(A2,GPP!$A$2:$A$10001,0),5)')
                . XlsxMentah::rumus('P2', '=VLOOKUP(A2,GPP!$A$2:$AN$10001,5,FALSE)') . '</row>',
        ]);

        $acuan = $this->nilaiKalkulator($path);
        $sheet = $this->assertDiterima($path);

        $this->assertCount(4, $acuan);
        $this->assertSamaDenganAcuan($acuan, $sheet);
        $this->assertEquals((2 + 10001) * 10000 / 2, $sheet['TK'][2][12]);
        $this->assertEquals(2, $sheet['TK'][2][13]);
        $this->assertEquals(2, $sheet['TK'][2][14], 'TK!O2 = INDEX(…,MATCH(…),5)');
        $this->assertEquals(2, $sheet['TK'][2][15], 'TK!P2 = VLOOKUP A:AN');
    }

    /**
     * Ukur (opt-in `RUN_VOLUME_TEST=1`): templat penuh 10.000 baris dengan dua kolom rumus `SUM` per baris (20.000 sel
     * rumus, di bawah anggaran) selesai jauh di bawah batas waktu request 60 detik. Angka dicatat di README BB-12.
     */
    public function testTemplatPenuhDenganKolomRumusDiBawahAnggaran(): void
    {
        if (getenv('RUN_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Uji ukur CR-181 hanya dijalankan dengan env RUN_VOLUME_TEST=1.');
        }

        $nominal = count(KolomGaji::SUMBER_GPP);
        $akhir   = XlsxMentah::kolom(4 + $nominal);
        $gpp     = XlsxMentah::baris(1, self::headerGpp());
        $tk      = XlsxMentah::baris(1, self::headerTk());

        for ($r = 2; $r <= 10001; $r++) {
            $gpp .= '<row r="' . $r . '">' . substr(XlsxMentah::baris($r, [self::NIP, 9, 2026, 'Budi Uji', ...array_fill(0, $nominal, 1000 + $r)]), strlen('<row r="' . $r . '">'), -strlen('</row>'))
                . XlsxMentah::rumus('AB' . $r, '=SUM(E' . $r . ':' . $akhir . $r . ')') . XlsxMentah::rumus('AC' . $r, '=SUM(E' . $r . ':F' . $r . ')') . '</row>';
            $tk .= XlsxMentah::baris($r, self::dataTk());
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulis($path, ['GPP' => $gpp, 'TK' => $tk]);

        memory_reset_peak_usage();
        $awalMemori = memory_get_peak_usage();
        $awal       = microtime(true);
        $sheet      = $this->assertDiterima($path);
        $detik      = microtime(true) - $awal;

        fwrite(STDERR, sprintf("\n[CR-181] templat penuh + 20.000 sel rumus: %.1f detik, puncak memori PHP +%.0f MB\n", $detik, (memory_get_peak_usage() - $awalMemori) / 1048576));

        $this->assertEquals($nominal * (1000 + 10001), $sheet['GPP'][10001][27]);
        $this->assertEquals(2 * (1000 + 10001), $sheet['GPP'][10001][28]);
        $this->assertLessThan(60.0, $detik);
    }

    // ------------------------------------------------------------------------------------------------------------------

    private function assertDitolak(string $path, ?PembacaExcelSlip $pembaca = null): void
    {
        memory_reset_peak_usage();
        $awalMemori = memory_get_peak_usage();
        $awal       = microtime(true);

        try {
            ($pembaca ?? new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame(PembacaExcelSlip::PESAN_RUMUS, $e->getMessage());
        }

        $this->assertLessThan(self::BATAS_DETIK, microtime(true) - $awal, 'ditolak sebelum dihitung');
        $this->assertLessThan(self::BATAS_MEMORI, memory_get_peak_usage() - $awalMemori, 'kenaikan puncak memori');
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

    /**
     * @param array<string, mixed>                                                 $acuan "SHEET!A1" => nilai kalkulator
     * @param array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $sheet
     */
    private function assertSamaDenganAcuan(array $acuan, array $sheet): void
    {
        $this->assertNotSame([], $acuan);

        foreach ($acuan as $alamat => $nilai) {
            [$nama, $koordinat] = explode('!', $alamat);
            [$kolom, $baris]    = Coordinate::coordinateFromString($koordinat);

            $this->assertSame($nilai, $sheet[$nama][(int) $baris][Coordinate::columnIndexFromString($kolom) - 1], $alamat);
        }
    }

    /**
     * Nilai semua sel rumus GPP/TK menurut kalkulator PhpSpreadsheet, dimuat dengan cara yang sama dengan pembaca tetapi
     * TANPA anggaran: acuan "perilaku sebelum CR-181".
     *
     * @return array<string, mixed> "SHEET!A1" => nilai
     */
    private function nilaiKalkulator(string $path): array
    {
        $reader = str_ends_with($path, '.xls') ? IOFactory::createReader('Xls') : new PemuatXlsxSlip();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([KolomGaji::SHEET_GPP, KolomGaji::SHEET_TK]);
        $buku  = $reader->load($path);
        $nilai = [];

        foreach ([KolomGaji::SHEET_GPP, KolomGaji::SHEET_TK] as $nama) {
            $lembar = $buku->getSheetByName($nama);
            $this->assertNotNull($lembar);

            foreach ($lembar->getCoordinates() as $koordinat) {
                $sel = $lembar->getCell($koordinat);

                if ($sel->getDataType() === DataType::TYPE_FORMULA) {
                    $nilai[$nama . '!' . $koordinat] = $sel->getCalculatedValue();
                }
            }
        }

        $buku->disconnectWorksheets();

        return $nilai;
    }

    /**
     * Workbook sah: header + satu baris data per sheet; GPP!E2 = 1000, F2 = 20, G2 = 3, TK!E2 = 5.
     *
     * @param list<string>          $gpp  sel tambahan di baris 2 GPP
     * @param list<string>          $tk   sel tambahan di baris 2 TK
     * @param array<string, string> $nama nama terdefinisi => isi
     */
    private function xlsx(array $gpp = [], array $tk = [], array $nama = []): string
    {
        $baris = static fn (array $data, array $sel): string => substr(XlsxMentah::baris(2, $data), 0, -strlen('</row>')) . implode('', $sel) . '</row>';
        $entri = XlsxMentah::entri([
            'GPP' => XlsxMentah::baris(1, self::headerGpp()) . $baris(self::dataGpp(), $gpp),
            'TK'  => XlsxMentah::baris(1, self::headerTk()) . $baris(self::dataTk(), $tk),
        ]);

        if ($nama !== []) {
            $daftar = '';

            foreach ($nama as $n => $isi) {
                $daftar .= '<definedName name="' . $n . '">' . htmlspecialchars($isi, ENT_XML1) . '</definedName>';
            }

            $entri['xl/workbook.xml'] = str_replace('</sheets>', '</sheets><definedNames>' . $daftar . '</definedNames>', $entri['xl/workbook.xml']);
        }

        $path = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($path, $entri);

        return $path;
    }

    /**
     * @param callable(string): string $ubah
     */
    private function ubahEntri(string $path, string $entri, callable $ubah): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $isi = $zip->getFromName($entri);
        $this->assertIsString($isi);
        $zip->addFromString($entri, $ubah($isi));
        $zip->close();
    }

    private static function sheetGpp(): string
    {
        return XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp());
    }

    private static function bukuSah(): Spreadsheet
    {
        $buku = new Spreadsheet();
        $gpp  = $buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP);
        $gpp->fromArray([self::headerGpp(), self::dataGpp()], null, 'A1', true);
        $gpp->setCellValueExplicit('A2', self::NIP, DataType::TYPE_STRING);
        $tk = $buku->createSheet()->setTitle(KolomGaji::SHEET_TK);
        $tk->fromArray([self::headerTk(), self::dataTk()], null, 'A1', true);
        $tk->setCellValueExplicit('A2', self::NIP, DataType::TYPE_STRING);

        return $buku;
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
        return [self::NIP, 9, 2026, 'Budi Uji', 1000, 20, 3, ...array_fill(0, count(KolomGaji::SUMBER_GPP) - 3, 0)];
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
        return [self::NIP, 9, 2026, 0, 5, ...array_fill(0, count(KolomGaji::SUMBER_TK) - 2, 0)];
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
