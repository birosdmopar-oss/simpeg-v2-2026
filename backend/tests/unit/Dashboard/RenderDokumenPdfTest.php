<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Libraries\Dashboard\Dokumen\KolomDokumen;
use App\Libraries\Dashboard\Dokumen\RenderDokumenPdf;
use App\Libraries\Dashboard\Statistik\LingkupStatistik;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopDokumen;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;
use TCPDF;
use ZipArchive;

/**
 * CR-064 (QA R2 ISSUE-050): PDF dokumen F-09 — lebar kolom header = data (T1), NIP tidak terpotong (T9), kaki
 * "Halaman x dari y" + username pencetak (T7/T8), subjudul periode proyeksi Pensiun (T10), dan baris atas XLSX
 * Baperjakat (T3).
 *
 * @internal
 */
final class RenderDokumenPdfTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function setKolom(): iterable
    {
        yield 'DUK/Nominatif/Baperjakat (13 kolom PDF)' => [KolomDokumen::kolomPdf('duk')];

        yield 'Pensiun (12 kolom)' => [KolomDokumen::kolomPdf('pensiun')];

        yield 'kolom tak dikenal memakai bobot bawaan' => [['aneh' => 'Aneh', 'nip' => 'NIP']];

        yield 'satu kolom' => [['nama' => 'Nama']];
    }

    /**
     * @dataProvider setKolom
     *
     * @param array<string, string> $kolom
     */
    public function testLebarKolomTepat100PersenDanNipCukupLebar(array $kolom): void
    {
        $lebar = RenderDokumenPdf::lebarKolom(array_keys($kolom));

        $this->assertSame(100, $lebar['no'] + array_sum($lebar['kolom']), 'jumlah lebar TEPAT 100% (tanpa sel kosong di ujung kanan)');
        $this->assertSame(array_keys($kolom), array_keys($lebar['kolom']));
        $this->assertGreaterThan(0, $lebar['no']);

        foreach ($lebar['kolom'] as $k => $persen) {
            $this->assertGreaterThan(0, $persen, $k);
        }

        if (isset($lebar['kolom']['nip']) && count($kolom) > 1) {
            // A4 lanskap 277 mm, 7pt: NIP 18 digit ≈ 26 mm → minimal ~10% agar satu baris.
            $this->assertGreaterThanOrEqual(10, $lebar['kolom']['nip'], 'NIP 18 digit harus muat satu baris (T9)');
        }
    }

    public function testKolomTeksBebasMuatKataTerpanjangTanpaTerpotongDiTengah(): void
    {
        // A4 lanskap, margin sisi 10 mm → 277 mm; padding sel 3 px ≈ 1,06 mm per sisi + garis tepi.
        $pdf = new TCPDF('L', 'mm', 'A4');
        $pdf->SetFont('helvetica', 'B', 7);
        $kataTerpanjang = max(array_map(
            static fn (string $k): float => $pdf->GetStringWidth($k),
            ['Pengembangan', 'Kepemimpinan', 'Administrator', 'Pembangunan', 'Pemberdayaan', 'Struktural'],
        ));

        foreach (['duk' => ['nama', 'jabatan', 'unit_satker', 'pendidikan', 'diklat'], 'pensiun' => ['nama', 'jabatan', 'satker']] as $jenis => $teksBebas) {
            $lebar = RenderDokumenPdf::lebarKolom(array_keys(KolomDokumen::kolomPdf($jenis)));

            foreach ($teksBebas as $k) {
                $mm = $lebar['kolom'][$k] / 100 * 277 - 2 * 1.06 - 0.3;
                $this->assertGreaterThanOrEqual($kataTerpanjang, $mm, "{$jenis}.{$k} ({$lebar['kolom'][$k]}%) harus muat kata terpanjang ({$kataTerpanjang} mm)");
            }

            // NIP 18 digit tetap satu baris.
            $this->assertGreaterThanOrEqual($pdf->GetStringWidth('198501012010011001') + 2 * 1.06 + 0.3, $lebar['kolom']['nip'] / 100 * 277, "{$jenis}.nip");
            $this->assertSame(100, $lebar['no'] + array_sum($lebar['kolom']));
        }
    }

    public function testLabelPdfPensiunTitleCaseDanPendek(): void
    {
        $this->assertSame(
            ['Nama', 'NIP', 'Tgl Lahir', 'Jabatan', 'TMT Jabatan', 'Satker', 'Eselon', 'Tgl Pensiun', 'BUP (Th)', 'Pensiun (Bl)', 'Usia (Th)', 'Usia (Bl)'],
            array_values(KolomDokumen::kolomPdf('pensiun')),
        );
        $this->assertArrayNotHasKey('PENSIUN (TH)', array_flip(KolomDokumen::kolomPdf('pensiun')));
        // XLSX tetap label legacy huruf besar.
        $this->assertSame('PENSIUN (TH)', KolomDokumen::KOLOM_PENSIUN['pensiun_th']);
    }

    public function testKopMemakaiNamaBawaanBilaWebConfigKosongDanBaperjakatTidakKosong(): void
    {
        $kop = KopDokumen::html(null, null);

        $this->assertSame('Kementerian Pariwisata', KopDokumen::NAMA_INSTANSI_BAWAAN);
        $this->assertStringContainsString('KEMENTERIAN PARIWISATA', $kop, 'nama bawaan bila web_config kosong');
        $this->assertStringNotContainsString('font-size:9pt', $kop, 'tanpa lingkup: baris 2 (unit/satker) dihilangkan');
    }

    public function testHeaderDanDataBerlebarSamaPerKolomTanpaSelEkstra(): void
    {
        foreach (['duk', 'pensiun'] as $jenis) {
            $kolom = KolomDokumen::kolomPdf($jenis);
            $baris = [
                array_fill_keys(array_keys($kolom), 'x') + ['nip' => '198501012010011001'],
                array_fill_keys(array_keys($kolom), 'y'),
            ];
            $html = RenderDokumenPdf::tabel($kolom, $baris);

            $this->assertStringContainsString('<thead>', $html, 'header berulang tiap halaman');

            preg_match('#<thead>(.*?)</thead>#s', $html, $head);
            preg_match('#<tbody>(.*?)</tbody>#s', $html, $body);
            preg_match_all('#<th width="(\d+)%">#', $head[1], $th);
            preg_match_all('#<tr>(.*?)</tr>#s', $body[1], $trs);

            $this->assertCount(count($kolom) + 1, $th[1], "{$jenis}: jumlah <th> = No + kolom (tanpa sel ekstra)");
            $this->assertSame(100, array_sum(array_map('intval', $th[1])), "{$jenis}: jumlah lebar header = 100");
            $this->assertCount(2, $trs[1]);

            foreach ($trs[1] as $i => $tr) {
                preg_match_all('#<td width="(\d+)%">#', $tr, $td);
                $this->assertSame($th[1], $td[1], "{$jenis}: lebar <td> baris " . ($i + 1) . ' == lebar <th> per kolom (termasuk No)');
            }
        }
    }

    public function testTabelKosongTetapSatuBarisPenuh(): void
    {
        $html = RenderDokumenPdf::tabel(KolomDokumen::kolomPdf('duk'), []);

        $this->assertStringContainsString('colspan="14"', $html);
        $this->assertStringContainsString('Tidak ada data', $html);
    }

    public function testKakiMemuatUsernameDanTokenHalaman(): void
    {
        $kaki = RenderDokumenPdf::kaki('budi.admin');

        $this->assertStringContainsString('oleh budi.admin', $kaki, 'username pencetak (T8)');
        $this->assertStringContainsString('Halaman ' . DokumenPdf::TOKEN_HALAMAN . ' dari ' . DokumenPdf::TOKEN_TOTAL, $kaki, 'nomor halaman (T7)');
        $this->assertSame('{halaman}', DokumenPdf::TOKEN_HALAMAN);
        $this->assertSame('{total}', DokumenPdf::TOKEN_TOTAL);
        // Pencetak di-escape (tanpa injeksi HTML).
        $this->assertStringNotContainsString('<script>', RenderDokumenPdf::kaki('<script>x</script>'));
    }

    public function testSubjudulPensiunMenyebutPeriodeProyeksi(): void
    {
        $metode = new ReflectionMethod(RenderDokumenPdf::class, 'subjudul');
        $render = new RenderDokumenPdf();
        $unit   = new LingkupStatistik(LingkupStatistik::UNIT, 3, 'Unit A');

        $this->assertSame('Unit A · Proyeksi pensiun 2026 s.d. 2030', $metode->invoke($render, $unit, KolomDokumen::PENSIUN, ['tahun' => 2026, 'sampai' => 2030]));
        $this->assertSame('Unit A · Proyeksi pensiun 2026', $metode->invoke($render, $unit, KolomDokumen::PENSIUN, ['tahun' => 2026, 'sampai' => 2026]), 'satu tahun tanpa "s.d."');
        $this->assertSame('Unit A', $metode->invoke($render, $unit, KolomDokumen::PENSIUN, null));
        // Jenis lain tidak terpengaruh.
        $this->assertSame('Unit A', $metode->invoke($render, $unit, KolomDokumen::DUK, ['tahun' => 2026, 'sampai' => 2030]));
        $kementerian = new LingkupStatistik(LingkupStatistik::KEMENTERIAN, null, 'Kementerian Uji');
        $this->assertSame('Seluruh Kementerian Uji', $metode->invoke($render, $kementerian, KolomDokumen::DUK, null));
    }

    public function testXlsxBarisAtasSebelumHeaderDenganGabungSelDanWrap(): void
    {
        $header = ['NO', 'NAMA', 'NIP'];
        $isi    = EksporTabel::xlsx($header, [['1', 'Budi', '198501012010011001']], 'Baperjakat', [
            ['teks' => 'Bahan Sidang', 'gaya' => 'judul'],
            ['teks' => 'Periode I'],
            ['teks' => "Baris satu\nBaris dua\nBaris tiga", 'gaya' => 'wrap'],
            ['teks' => ''],
        ]);
        $xml = $this->sheet($isi);

        // Urutan baris: 1 judul, 2 subjudul, 3 teks, 4 kosong, 5 header, 6 data.
        $this->assertMatchesRegularExpression('#<row r="1"><c r="A1" t="inlineStr" s="1"><is><t xml:space="preserve">Bahan Sidang</t>#', $xml);
        $this->assertStringContainsString('<c r="A2" t="inlineStr"><is><t xml:space="preserve">Periode I</t>', $xml);
        $this->assertMatchesRegularExpression('#<row r="3" ht="45" customHeight="1"><c r="A3" t="inlineStr" s="2"><is><t xml:space="preserve">Baris satu\nBaris dua\nBaris tiga</t>#', $xml);
        $this->assertStringContainsString('<row r="4"></row>', $xml, 'baris kosong pemisah');
        $this->assertMatchesRegularExpression('#<row r="5"><c r="A5" t="inlineStr" s="1"><is><t xml:space="preserve">NO</t>.*?NIP</t>#s', $xml, 'header di baris 5, tebal');
        $this->assertStringContainsString('<c r="B6" t="inlineStr"><is><t xml:space="preserve">Budi</t>', $xml);
        // Judul, subjudul, dan teks digabung selebar header (A:C); baris kosong tidak.
        $this->assertStringContainsString('<mergeCells count="3"><mergeCell ref="A1:C1"/><mergeCell ref="A2:C2"/><mergeCell ref="A3:C3"/></mergeCells>', $xml);
    }

    public function testXlsxTanpaBarisAtasTidakBerubahDanTeksTetapAntiRumus(): void
    {
        $biasa = $this->sheet(EksporTabel::xlsx(['NAMA'], [['Budi']]));
        $this->assertStringContainsString('<row r="1"><c r="A1" t="inlineStr" s="1">', $biasa, 'header tetap baris 1');
        $this->assertStringNotContainsString('mergeCells', $biasa);

        // Teks yang berawalan rumus tetap sel teks murni (inlineStr), tidak pernah <f>.
        $xml = $this->sheet(EksporTabel::xlsx(['A'], [], 'X', [['teks' => '=HYPERLINK("http://x","klik")', 'gaya' => 'judul']]));
        $this->assertStringContainsString('t="inlineStr"', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('=HYPERLINK(&quot;http://x&quot;,&quot;klik&quot;)', $xml);
    }

    private function sheet(string $isi): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $isi);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        return $sheet;
    }
}
