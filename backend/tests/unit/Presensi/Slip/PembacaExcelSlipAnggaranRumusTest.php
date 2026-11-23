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
        yield 'COUNTIFS tiga rentang 10.000 baris' => $tk('=COUNTIFS(GPP!$C$2:$C$10001,">5",GPP!$A$2:$A$10001,">5",GPP!$C$2:$C$10001,"<90")');

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

        for ($r = 2; $r <= 13; $r++) {
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
        $buku = self::bukuSah();
        $gpp  = $buku->getSheetByName(KolomGaji::SHEET_GPP);
        $this->assertNotNull($gpp);
        $penaksir = new PenaksirBebanRumus($buku);
        $beban    = 0;
        $terbesar = 0;

        foreach ([...$rumus, ...$total] as $i => $r) {
            $sel = $gpp->getCell(XlsxMentah::kolom(40 + $i) . '2');

            for ($b = 2, $akhir = $i < count($rumus) ? $baris + 1 : 2; $b <= $akhir; $b++) {
                $sel->setValue((string) preg_replace('/(?<=[A-Z])2\b/', (string) $b, $r));
                $satu = $penaksir->beban($sel);
                $beban += $satu;
                $terbesar = max($terbesar, $satu);
            }
        }

        $buku->disconnectWorksheets();

        $this->assertLessThanOrEqual(Presensi::MAKS_BEBAN_PER_RUMUS_IMPOR, $terbesar);
        $this->assertLessThanOrEqual(Presensi::MAKS_BEBAN_RUMUS_IMPOR, $beban, 'beban berkas ' . $beban);
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
     * Baris total di bawah 10.000 baris data (`=SUM(E2:E10001)`) dan satu `VLOOKUP` ke seluruh GPP: rumus wajar yang
     * luas rujukannya besar tetap diterima.
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
                . XlsxMentah::rumus('M2', '=SUM(GPP!E2:E10001)') . XlsxMentah::rumus('N2', '=VLOOKUP(A2,GPP!$A$1:$Z$10001,5,0)') . '</row>',
        ]);

        $sheet = $this->assertDiterima($path);

        $this->assertEquals((2 + 10001) * 10000 / 2, $sheet['TK'][2][12]);
        $this->assertEquals(2, $sheet['TK'][2][13]);
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
