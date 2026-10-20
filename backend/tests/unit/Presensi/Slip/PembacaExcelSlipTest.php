<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\SlipGaji\BarisSheet;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\PratinjauImpor;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * PRD 5A §7.2.3 butir 1 (AC-D10.9): pembaca Excel impor Slip Gaji — nilai mentah sel (tanpa format tampilan), rumus
 * dihitung, rich text polos, sheet GPP & TK wajib, batas baris per sheet, berkas rusak → pesan generik. Berkas `.xlsx`
 * dan `.xls` sintetis dibangkitkan writer PhpSpreadsheet di test (bukan data produksi).
 *
 * @internal
 */
final class PembacaExcelSlipTest extends CIUnitTestCase
{
    private const NIP = '199001012020011001';

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
    public static function format(): iterable
    {
        yield 'xlsx' => ['Xlsx'];
        yield 'xls' => ['Xls'];
    }

    #[DataProvider('format')]
    public function testNilaiMentahRumusDanRichText(string $format): void
    {
        $path  = $this->berkas($format);
        $hasil = (new PembacaExcelSlip(10000))->baca($path);

        $this->assertTrue($hasil->valid);
        /** @var array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $sheet */
        $sheet = $hasil->nilai;

        $this->assertSame([1, 2], array_keys($sheet[KolomGaji::SHEET_GPP]), 'baris 1 header, data mulai baris 2');
        $gpp = $sheet[KolomGaji::SHEET_GPP][2];
        $this->assertSame(self::NIP, $gpp[0], 'NIP teks tetap teks');
        $this->assertEquals(4500000.5, $gpp[4], 'float mentah, bukan teks tampilan berformat');
        $this->assertSame('1.234.567', $gpp[5], 'teks angka format Indonesia apa adanya');
        $this->assertEquals(4500001.5, $gpp[6], 'sel rumus = hasil hitung');

        // Masukan valid untuk PratinjauImpor::uraiSheet (AC-D10.9: 4500000.5 → 450000050 sen, '1.234.567' → 123456700 sen).
        $urai = PratinjauImpor::uraiSheet($sheet[KolomGaji::SHEET_GPP], KolomGaji::SHEET_GPP);
        $this->assertTrue($urai->valid, json_encode($urai->detail) ?: '');
        /** @var array{baris: list<BarisSheet>} $nilai */
        $nilai = $urai->nilai;
        $baris = $nilai['baris'][0];
        $this->assertSame(self::NIP, $baris->nip);
        $this->assertSame('Budi Uji', $baris->nama, 'rich text → teks polos');
        $this->assertSame(450000050, $baris->nominal['gjpokok']);
        $this->assertSame(123456700, $baris->nominal['tjistri']);
        $this->assertSame(450000150, $baris->nominal['tjanak']);
    }

    public function testNipNumerikTetapNumerik(): void
    {
        // Sel NIP numerik dibiarkan apa adanya; PratinjauImpor menolaknya `nip_bukan_teks` (AC-D10.9).
        $path  = $this->berkas('Xlsx', nipNumerik: true);
        $sheet = (new PembacaExcelSlip(10000))->baca($path)->nilai;

        $this->assertIsArray($sheet);
        $this->assertIsNotString($sheet[KolomGaji::SHEET_GPP][2][0]);
    }

    public function testSheetWajibTidakAda(): void
    {
        $hasil = (new PembacaExcelSlip(10000))->baca($this->berkas('Xlsx', tanpaTk: true));

        $this->assertFalse($hasil->valid);
        $this->assertSame('sheet_tidak_ada', $hasil->kode);
    }

    public function testBarisMelebihiBatas(): void
    {
        $this->expectException(BerkasImporDitolak::class);
        $this->expectExceptionMessage('Jumlah baris melebihi batas 1 per sheet.');

        (new PembacaExcelSlip(1))->baca($this->berkas('Xlsx', barisTambahan: 1));
    }

    public function testBerkasRusakPesanGenerik(): void
    {
        $path = $this->temp('.xlsx');
        file_put_contents($path, "PK\x03\x04bukan-arsip-zip-yang-utuh");

        try {
            (new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas rusak harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $e->getMessage(), 'tanpa pesan exception mentah');
        }
    }

    private function berkas(string $format, bool $nipNumerik = false, bool $tanpaTk = false, int $barisTambahan = 0): string
    {
        $buku = new Spreadsheet();
        $gpp  = $buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP);

        $gpp->fromArray([['nip', 'bulan', 'tahun', 'nama', ...array_keys(KolomGaji::SUMBER_GPP)]], null, 'A1');
        $nilai = array_fill(0, count(KolomGaji::SUMBER_GPP), 0);
        $gpp->fromArray([[null, 9, 2026, null, ...$nilai]], null, 'A2', true);

        if ($nipNumerik) {
            $gpp->setCellValueExplicit('A2', 199001012020011001, DataType::TYPE_NUMERIC);
        } else {
            $gpp->setCellValueExplicit('A2', self::NIP, DataType::TYPE_STRING);
        }

        $nama = new RichText();
        $nama->createTextRun('Budi ');
        $nama->createTextRun('Uji')->getFont()?->setBold(true);
        $gpp->setCellValue('D2', $nama);
        $gpp->setCellValue('E2', 4500000.5);
        $gpp->getStyle('E2')->getNumberFormat()->setFormatCode('#,##0');
        $gpp->setCellValueExplicit('F2', '1.234.567', DataType::TYPE_STRING);
        $gpp->setCellValue('G2', '=E2+1');

        for ($i = 0; $i < $barisTambahan; $i++) {
            $gpp->fromArray([['199001012020011099', 9, 2026, 'Lain', ...$nilai]], null, 'A' . (3 + $i));
        }

        if (! $tanpaTk) {
            $tk = $buku->createSheet()->setTitle(KolomGaji::SHEET_TK);
            $tk->fromArray([['nip', 'bulan', 'tahun', ...array_keys(KolomGaji::SUMBER_TK)]], null, 'A1');
            $tk->setCellValueExplicit('A2', self::NIP, DataType::TYPE_STRING);
            $tk->fromArray([[9, 2026, ...array_fill(0, count(KolomGaji::SUMBER_TK), 0)]], null, 'B2', true);
        }

        $path = $this->temp($format === 'Xls' ? '.xls' : '.xlsx');
        IOFactory::createWriter($buku, $format)->save($path);
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
