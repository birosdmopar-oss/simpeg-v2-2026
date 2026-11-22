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
        $gpp = XlsxMentah::baris(1, ['nip', 'bulan', 'tahun']);

        for ($r = 2; $r <= 200_001; $r++) {
            $gpp .= '<row r="' . $r . '">' . XlsxMentah::sel('A' . $r, self::NIP) . XlsxMentah::sel('B' . $r, 9) . '</row>';
        }

        $path = $this->xlsx(['GPP' => $gpp, 'TK' => self::sheetTk()]);
        unset($gpp);
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

    public function testXlsBarisMelebihiBatasDitolakSebelumDimuat(): void
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

    private function assertDitolak(string $pesan, string $path, int $maksBaris): void
    {
        memory_reset_peak_usage();
        $awal = memory_get_peak_usage();

        try {
            (new PembacaExcelSlip($maksBaris))->baca($path);
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
