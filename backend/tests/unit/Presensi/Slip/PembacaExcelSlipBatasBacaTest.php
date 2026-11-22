<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Presensi\XlsxMentah;

/**
 * CR-177 — ukuran sheet GPP/TK diperiksa SEBELUM berkas dimuat, supaya berkas kecil yang "lebar" (sel di kolom XFD) atau
 * berkas padat ratusan ribu baris ditolak 422 dan tidak menghabiskan memori/waktu (dulu 500). Berkas uji ditulis sebagai
 * XML mentah ({@see XlsxMentah}); kenaikan puncak memori diukur supaya test tetap bermakna saat `memory_limit = -1`.
 *
 * @internal
 */
final class PembacaExcelSlipBatasBacaTest extends CIUnitTestCase
{
    private const NIP          = '199001012020011001';
    private const PESAN_KOLOM  = 'Jumlah kolom melebihi batas 256 per sheet.';
    private const PESAN_BARIS  = 'Jumlah baris melebihi batas 10.000 per sheet.';
    private const BATAS_MEMORI = 64 * 1024 * 1024;

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

        $this->assertDitolak(self::PESAN_KOLOM, $path, 10000);
    }

    public function testKolomTepatDiBatasDiterima(): void
    {
        // Kolom ke-256 (IV) berisi header tak dikenal: masih dibaca; kolom tak dikenal memang diabaikan penguraian.
        $gpp   = '<row r="1">' . self::selHeader(self::headerGpp()) . XlsxMentah::sel('IV1', 'catatan') . '</row>' . XlsxMentah::baris(2, self::dataGpp());
        $hasil = (new PembacaExcelSlip(10000))->baca($this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]));

        $this->assertTrue($hasil->valid);
        /** @var array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $sheet */
        $sheet = $hasil->nilai;
        $this->assertSame([1, 2], array_keys($sheet['GPP']));
        $this->assertCount(Presensi::MAKS_KOLOM_IMPOR, $sheet['GPP'][1]);
        $this->assertSame('catatan', $sheet['GPP'][1][255]);
        $this->assertSame(self::NIP, $sheet['GPP'][2][0]);
    }

    public function testKolomSatuLewatBatasDitolak(): void
    {
        $gpp = '<row r="1">' . self::selHeader(self::headerGpp()) . XlsxMentah::sel('IW1', 'catatan') . '</row>' . XlsxMentah::baris(2, self::dataGpp());

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), 10000);
    }

    public function testSheetTkLebarJugaDitolak(): void
    {
        $tk = '<row r="1">' . self::selHeader(self::headerTk()) . XlsxMentah::sel('XFD1', 'x') . '</row>' . XlsxMentah::baris(2, self::dataTk());

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx(['GPP' => XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()), 'TK' => $tk]), 10000);
    }

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

    /**
     * Berkas padat 200.000 baris data GPP: ditolak dengan pesan batas baris yang sama seperti dulu, tetapi SEBELUM dimuat
     * (dulu seluruh sheet dimuat dulu, ratusan MB, baru ditolak).
     */
    public function testBerkasPadatDitolakSebelumDimuat(): void
    {
        $path = $this->xlsx(['GPP' => self::gppPadat(), 'TK' => self::sheetTk()]);
        $this->assertLessThan(5 * 1024 * 1024, (int) filesize($path), 'di bawah batas ukuran unggah');

        $this->assertDitolak(self::PESAN_BARIS, $path, 10000);
    }

    /**
     * Perilaku lama yang dijaga: sel kosong (hanya bergaya) lewat batas baris tetap dihitung, jadi berkasnya tetap ditolak.
     */
    public function testSelKosongLewatBatasBarisTetapDitolak(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()) . '<row r="7"><c r="A7" s="0"/></row>';

        $this->assertDitolak('Jumlah baris melebihi batas 5 per sheet.', $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), 5);
    }

    public function testSelKosongDiDalamBatasTetapDibacaSebagaiBarisKosong(): void
    {
        $gpp   = XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()) . '<row r="6"><c r="A6" s="0"/></row>';
        $hasil = (new PembacaExcelSlip(5))->baca($this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]));

        $this->assertTrue($hasil->valid);
        /** @var array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $sheet */
        $sheet = $hasil->nilai;
        $this->assertSame([1, 2, 3, 4, 5, 6], array_keys($sheet['GPP']));
        $this->assertSame(array_fill(0, count(self::headerGpp()), null), $sheet['GPP'][6]);
    }

    /**
     * [V2] CR-177: `<row>` tanpa sel (mis. hanya tinggi baris) lewat batas ikut dihitung pra-cek, jadi ditolak dengan pesan
     * batas baris. Dulu baris seperti ini tidak dihitung (tidak ada sel yang dibuat).
     */
    public function testBarisTanpaSelLewatBatasDitolak(): void
    {
        $gpp = XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()) . '<row r="7" ht="20"/>';

        $this->assertDitolak('Jumlah baris melebihi batas 5 per sheet.', $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), 5);
    }

    public function testXlsBarisMelebihiBatasDitolak(): void
    {
        $buku = new Spreadsheet();
        $gpp  = $buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP);
        $gpp->fromArray([self::headerGpp(), self::dataGpp(), self::dataGpp(), self::dataGpp()], null, 'A1', true);
        $tk = $buku->createSheet()->setTitle(KolomGaji::SHEET_TK);
        $tk->fromArray([self::headerTk(), self::dataTk()], null, 'A1', true);
        $path = $this->temp('.xls');
        IOFactory::createWriter($buku, 'Xls')->save($path);
        $buku->disconnectWorksheets();

        $this->assertDitolak('Jumlah baris melebihi batas 2 per sheet.', $path, 2);
        $this->assertTrue((new PembacaExcelSlip(3))->baca($path)->valid);
    }

    /**
     * Berkas yang bukan Excel tetapi dikenali reader lain (HTML) tidak lagi dibaca: reader dikunci ke Xlsx/Xls.
     */
    public function testBerkasHtmlBerkedokXlsTidakDibaca(): void
    {
        $path = $this->temp('.xls');
        file_put_contents($path, '<html><body><table><tr><td>nip</td><td>bulan</td></tr></table></body></html>');

        $this->assertDitolak(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $path, 10000);
    }

    /**
     * BLOK-1a: `<row>` dan `<c>` TANPA atribut `r`. `listWorksheetInfo` membacanya sebagai 0 baris, padahal pemuat menaruh
     * sel menurut nomor urut. 300.000 baris × 2 sel dalam berkas puluhan KB: harus ditolak sebelum dimuat.
     */
    public function testBarisTanpaAtributRDitolakSebelumDimuat(): void
    {
        $path = $this->xlsx(['GPP' => XlsxMentah::baris(1, ['nip', 'bulan', 'tahun']) . str_repeat('<row><c><v>1</v></c><c><v>1</v></c></row>', 300_000), 'TK' => self::sheetTk()]);
        $this->assertLessThan(200_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak(self::PESAN_BARIS, $path, 10000);
    }

    /**
     * BLOK-1b: berkas padat yang `<row>` terakhirnya bernomor kecil. `listWorksheetInfo` hanya melihat `r` baris terakhir.
     */
    public function testBarisTerakhirBernomorKecilDitolakSebelumDimuat(): void
    {
        $this->assertDitolak(self::PESAN_BARIS, $this->xlsx(['GPP' => self::gppPadat() . '<row r="2"/>', 'TK' => self::sheetTk()]), 10000);
    }

    /**
     * BLOK-2: relasi sheet ber-`Target` absolut. `listWorksheetInfo` membaca `kecil/gpp.xml`, pemuat membaca
     * `xl/cil/gpp.xml` (potongan path berbeda). Pemindai tidak mengikuti relasi, jadi part padat tetap terlihat.
     */
    public function testTargetRelasiAbsolutDitolakSebelumDimuat(): void
    {
        $entri = XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]);
        unset($entri['xl/worksheets/sheet1.xml']);
        $entri['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="/kecil/gpp.xml"', $entri['xl/_rels/workbook.xml.rels']);
        $this->assertStringContainsString('Target="/kecil/gpp.xml"', $entri['xl/_rels/workbook.xml.rels']);
        $entri['kecil/gpp.xml']  = XlsxMentah::lembar(XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()));
        $entri['xl/cil/gpp.xml'] = XlsxMentah::lembar(self::gppPadat());
        $path                    = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($path, $entri);

        $this->assertDitolak(self::PESAN_BARIS, $path, 10000);
    }

    /**
     * Part ber-akar bukan `worksheet` tetap dihitung: pemuat tidak memeriksa nama akar, hanya `sheetData` → `row` → `c`.
     */
    public function testPartBerakarLainTetapDihitung(): void
    {
        $entri                             = XlsxMentah::entri(['GPP' => '', 'TK' => self::sheetTk()]);
        $entri['xl/worksheets/sheet1.xml'] = XlsxMentah::KEPALA . '<lain xmlns="' . XlsxMentah::UTAMA . '"><sheetData>' . XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(8, self::dataGpp()) . '</sheetData></lain>';
        $path                              = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($path, $entri);

        $this->assertDitolak('Jumlah baris melebihi batas 5 per sheet.', $path, 5);
    }

    /**
     * Kolom lewat batas pada berkas yang masih di dalam batas baris dan batas sel: 300 baris × 1.000 kolom = 300.000 sel.
     * Tanpa cek kolom pra-muat, berkas ini dimuat dulu (ratusan MB) baru ditolak cek pasca-muat.
     */
    public function testKolomLewatBatasPadaBerkasPadatDitolakSebelumDimuat(): void
    {
        $sel = '';

        for ($k = 1; $k <= 1000; $k++) {
            $sel .= '<c r="' . XlsxMentah::kolom($k) . '%1$d"><v>1</v></c>';
        }

        $gpp = XlsxMentah::baris(1, self::headerGpp());

        for ($r = 2; $r <= 301; $r++) {
            $gpp .= '<row r="' . $r . '">' . sprintf($sel, $r) . '</row>';
        }

        $this->assertDitolak(self::PESAN_KOLOM, $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), 10000);
    }

    /**
     * Baris diperiksa dulu untuk semua sheet, baru kolom: pesan tidak bergantung urutan tab (GPP lebar, TK lewat baris).
     *
     * @param list<string> $urutan
     */
    #[DataProvider('urutanTab')]
    public function testBarisDiperiksaDuluTanpaBergantungUrutanTab(array $urutan): void
    {
        $isi = [
            'GPP' => '<row r="1">' . self::selHeader(self::headerGpp()) . XlsxMentah::sel('IW1', 'catatan') . '</row>' . XlsxMentah::baris(2, self::dataGpp()),
            'TK'  => self::sheetTk() . XlsxMentah::baris(8, self::dataTk()),
        ];

        $this->assertDitolak('Jumlah baris melebihi batas 5 per sheet.', $this->xlsx([$urutan[0] => $isi[$urutan[0]], $urutan[1] => $isi[$urutan[1]]]), 5);
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
     * Batas jumlah sel per berkas (semua sheet): di dalam batas baris dan kolom, tetapi 9.000 × 46 = 414.000 sel.
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

        $this->assertDitolak('Jumlah sel melebihi batas 400.000 per berkas.', $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]), 10000);
    }

    public function testJumlahSelDihitungDariSemuaSheet(): void
    {
        $path   = $this->xlsx(['GPP' => XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()), 'TK' => self::sheetTk(), 'Lain' => XlsxMentah::baris(1, ['a', 'b'])]);
        $jumlah = 2 * count(self::headerGpp()) + 2 * count(self::headerTk()) + 2;

        $this->assertTrue((new PembacaExcelSlip(10000, 256, $jumlah))->baca($path)->valid);
        $this->assertDitolak('Jumlah sel melebihi batas ' . ($jumlah - 1) . ' per berkas.', $path, 10000, new PembacaExcelSlip(10000, 256, $jumlah - 1));
    }

    /**
     * Batas ukuran isi sesudah dekompresi: entri pengisi 33 MB (terkompresi puluhan KB) ditolak dari ukuran yang tercatat di
     * ZIP, tanpa membaca isinya.
     */
    public function testIsiMengembangLewatBatasDitolak(): void
    {
        $entri                   = XlsxMentah::entri(['GPP' => XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()), 'TK' => self::sheetTk()]);
        $entri['xl/pengisi.xml'] = str_repeat(' ', 33 * 1024 * 1024);
        $path                    = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($path, $entri);
        unset($entri);
        $this->assertLessThan(200_000, (int) filesize($path), 'berkas kecil');

        $this->assertDitolak('Isi berkas terlalu besar sesudah dibuka (maksimal 32 MB).', $path, 10000);
    }

    /**
     * Entri yang akan diubah atau ditolak pemuat sebelum diurai (BOM UTF-16, deklarasi encoding lain, DOCTYPE) membuat
     * hitungan pemindai tidak bisa dipercaya → berkas ditolak sebagai tidak terbaca, di entri mana pun.
     */
    #[DataProvider('entriTidakDidukung')]
    public function testEntriYangDiubahPemuatDitolak(string $isi): void
    {
        $entri                      = XlsxMentah::entri(['GPP' => XlsxMentah::baris(1, self::headerGpp()) . XlsxMentah::baris(2, self::dataGpp()), 'TK' => self::sheetTk()]);
        $entri['xl/lain/data1.xml'] = $isi;
        $path                       = $this->temp('.xlsx');
        XlsxMentah::tulisEntri($path, $entri);

        $this->assertDitolak(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $path, 10000);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function entriTidakDidukung(): iterable
    {
        $lembar = '<worksheet xmlns="' . XlsxMentah::UTAMA . '"><sheetData/></worksheet>';

        yield 'BOM UTF-16LE' => ["\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . $lembar, 'UTF-16LE', 'UTF-8')];
        yield 'deklarasi UTF-7' => ['<?xml version="1.0" encoding="UTF-7"?>' . $lembar];
        yield 'deklarasi sesudah spasi panjang' => ['<?xml version="1.0" encoding' . str_repeat(' ', 70_000) . '="ISO-8859-1"?>' . $lembar];
        yield 'DOCTYPE' => ['<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE worksheet [<!ENTITY a "aaaa">]>' . $lembar];
    }

    private function assertDitolak(string $pesan, string $path, int $maksBaris, ?PembacaExcelSlip $pembaca = null): void
    {
        memory_reset_peak_usage();
        $awal = memory_get_peak_usage();

        try {
            ($pembaca ?? new PembacaExcelSlip($maksBaris))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame($pesan, $e->getMessage());
        }

        $this->assertLessThan(self::BATAS_MEMORI, memory_get_peak_usage() - $awal, 'kenaikan puncak memori');
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
     * Isi `<sheetData>` GPP padat: header + 200.000 baris data (2 sel per baris).
     */
    private static function gppPadat(): string
    {
        $gpp = XlsxMentah::baris(1, ['nip', 'bulan', 'tahun']);

        for ($r = 2; $r <= 200_001; $r++) {
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
