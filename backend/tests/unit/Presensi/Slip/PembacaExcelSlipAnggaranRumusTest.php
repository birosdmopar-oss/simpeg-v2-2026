<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\Slip\PemuatXlsxSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Presensi\XlsxMentah;

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

        for ($r = 2; $r <= 9; $r++) {
            $tk->setCellValue('M' . $r, '=VLOOKUP(A' . $r . ',GPP!$A$1:$Z$10001,5,0)');
        }

        $path = $this->temp('.xls');
        IOFactory::createWriter($buku, 'Xls')->setPreCalculateFormulas(false)->save($path);
        $buku->disconnectWorksheets();

        $this->assertDitolak($path);
    }

    /**
     * Batas jumlah beban juga menangkap "terlalu banyak sel rumus ringan" (mis. 22 kolom rumus × 10.000 baris): tiap sel
     * rumus berbeban paling sedikit beban dasar + panjang teksnya.
     */
    public function testBanyakSelRumusRinganDitolakLewatJumlahAnggaran(): void
    {
        $sel = [];

        // 40 sel "=E2+1": beban 40 × (beban dasar + 5 karakter).
        for ($c = 28; $c < 68; $c++) {
            $sel[] = XlsxMentah::rumus(XlsxMentah::kolom($c) . '2', '=E2+1');
        }

        $path  = $this->xlsx($sel);
        $beban = 40 * (Presensi::BEBAN_DASAR_RUMUS_IMPOR + strlen('=E2+1'));

        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanRumus: $beban - 1));
        $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanRumus: $beban));
    }

    /**
     * Satuan beban satu rentang: baris × kolom + baris² / pembagi, ditambah beban dasar dan panjang teks rumus.
     */
    public function testBebanRentangDihitungDariLuasDanJumlahBaris(): void
    {
        $rumus = '=SUM(A1:J300)';
        $path  = $this->xlsx([XlsxMentah::rumus('AB2', $rumus)]);
        $beban = Presensi::BEBAN_DASAR_RUMUS_IMPOR + strlen($rumus) + 300 * 10 + intdiv(300 * 300, Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR);

        $this->assertSame(3029, $beban);
        $this->assertDitolak($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $beban - 1));
        $this->assertDiterima($path, new PembacaExcelSlip(10000, maksBebanPerRumus: $beban));
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
