<?php

declare(strict_types=1);

namespace Tests\Unit\Pdf;

use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Pdf\KopResmi;
use App\Libraries\Pdf\TcpdfRenderer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kop resmi seragam semua PDF unduhan (CR-191; keputusan Horii 24-11-2026, acuan gambar): logo kiri | baris 1 nama
 * kementerian, baris 2 unit, alamat | logo kanan, garis. Logo bawaan repo bila `web_config` kosong / tidak sah; teks
 * masukan tidak pernah dirender sebagai HTML.
 *
 * @internal
 */
final class KopResmiTest extends CIUnitTestCase
{
    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kopresmi-' . bin2hex(random_bytes(4));
        mkdir($this->akar . DIRECTORY_SEPARATOR . 'kop', 0o777, true);

        // Lebar × tinggi berbeda supaya penskalaan bisa diperiksa.
        foreach (['kop/kiri.png' => [40, 40], 'kop/kanan.png' => [100, 20], 'satker.png' => [10, 30]] as $berkas => [$l, $t]) {
            $gambar = imagecreatetruecolor($l, $t);
            imagepng($gambar, $this->akar . DIRECTORY_SEPARATOR . $berkas);
            imagedestroy($gambar);
        }

        file_put_contents($this->akar . DIRECTORY_SEPARATOR . 'palsu.png', '<?php echo 1;');
    }

    protected function tearDown(): void
    {
        foreach (['kop/kiri.png', 'kop/kanan.png', 'satker.png', 'palsu.png'] as $berkas) {
            @unlink($this->akar . DIRECTORY_SEPARATOR . $berkas);
        }

        @rmdir($this->akar . DIRECTORY_SEPARATOR . 'kop');
        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testUrutanLogoKiriBaris1Baris2AlamatLogoKananGaris(): void
    {
        $kop = KopResmi::html('Kementerian Uji', 'Sekretariat Uji', 'Jalan Uji 1<br />Telepon 000<br>Laman: contoh.test');

        $posisi = array_map(static fn (string $s): int|false => strpos($kop, $s), [
            '<img src="@' . $this->base64(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KIRI)),
            '<span style="font-size:12.5pt;"><b>KEMENTERIAN UJI</b></span>',
            '<span style="font-size:11pt;"><b>SEKRETARIAT UJI</b></span>',
            '<span style="font-size:9pt;">Jalan Uji 1<br />' . "\n" . 'Telepon 000<br />' . "\n" . 'Laman: contoh.test</span>',
            '<img src="@' . $this->base64(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KANAN)),
            'border-top:2px solid #000000',
        ]);

        $this->assertNotContains(false, $posisi, $kop);
        $urut = $posisi;
        sort($urut);
        $this->assertSame($urut, $posisi, 'logo kiri → baris 1 → baris 2 → alamat → logo kanan → garis');
        $this->assertSame(2, substr_count($kop, '<img '));
        $this->assertSame(3, substr_count($kop, '<td width='), 'tiga kolom: logo | teks | logo');
        $this->assertStringContainsString('<td width="15%" align="left">', $kop);
        $this->assertStringContainsString('<td width="15%" align="right">', $kop, 'kolom logo simetris');
    }

    /** Lanskap: kop dibatasi di tengah halaman (pengisi kiri/kanan) supaya logo tetap rapat ke teks. */
    public function testLanskapKopDibatasiDiTengah(): void
    {
        $kop = KopResmi::html('Kementerian Uji', 'Unit Uji', 'Jalan Uji', lanskap: true);

        $this->assertSame(5, substr_count($kop, '<td width='), 'pengisi | logo | teks | logo | pengisi');
        $this->assertSame(2, substr_count($kop, '<td width="15.5%"></td>'), 'pengisi simetris');
        $this->assertStringContainsString('<td width="10.5%" align="left">', $kop);
        $this->assertStringContainsString('<td width="48%" align="center"', $kop);
        $this->assertStringContainsString('<td width="10.5%" align="right">', $kop);
        $this->assertStringNotContainsString('15.5%', KopResmi::html('Kementerian Uji', 'Unit Uji', 'Jalan Uji'), 'potret: tanpa pengisi');
    }

    /** Blok teks yang lebih pendek dari logo diturunkan ke tengah; blok penuh (5 baris) nyaris tanpa pengisi. */
    public function testBlokTeksPendekDiturunkanKeTengah(): void
    {
        $this->assertStringContainsString('style="line-height:1.15;"><div style="font-size:26px;line-height:1;">&nbsp;</div><span style="font-size:12.5pt;">', KopResmi::html('K', '', ''));
        $this->assertStringContainsString('style="line-height:1.15;"><div style="font-size:4px;line-height:1;">&nbsp;</div><span', KopResmi::html('K', 'U', "a\nb\nc"));
        $this->assertStringContainsString('style="line-height:1.15;"><span', KopResmi::html('K', 'U', "a\nb\nc\nd\ne"), 'blok lebih tinggi dari logo: tanpa pengisi');
    }

    /** Rantai nama tunggal semua PDF: pdf_header_nama_kementerian → nama_kementerian → bawaan; kosong = teks polos kosong. */
    public function testRantaiNamaKementerian(): void
    {
        $this->assertSame('Kop Uji Dua', KopResmi::nama(['pdf_header_nama_kementerian' => 'Kop Uji<br />Dua', 'nama_kementerian' => 'Kementerian Uji']));

        foreach ([[], ['pdf_header_nama_kementerian' => ''], ['pdf_header_nama_kementerian' => ' <br /> '], ['pdf_header_nama_kementerian' => null]] as $wc) {
            $this->assertSame('Kementerian Uji', KopResmi::nama($wc + ['nama_kementerian' => ' Kementerian Uji ']), (string) json_encode($wc));
            $this->assertSame(KopDokumen::NAMA_INSTANSI_BAWAAN, KopResmi::nama($wc + ['nama_kementerian' => '']), (string) json_encode($wc));
            $this->assertSame(KopDokumen::NAMA_INSTANSI_BAWAAN, KopResmi::nama($wc));
        }
    }

    /** Alamat berstruktur blok (<p>, <li>, <div>, <tr>, <hr>): tiap blok jadi baris sendiri, tidak menyatu. */
    public function testAlamatBerblokDipisahPerBaris(): void
    {
        $harap = '<span style="font-size:9pt;">Jalan Uji 1<br />' . "\n" . 'Telepon 000<br />' . "\n" . 'Laman: contoh.test</span>';

        foreach ([
            '<p>Jalan Uji 1</p><p>Telepon 000</p><p>Laman: contoh.test</p>',
            '<div>Jalan Uji 1</div><div>Telepon 000</div>Laman: contoh.test',
            '<ul><li>Jalan Uji 1</li><li>Telepon 000</li><li>Laman: contoh.test</li></ul>',
            '<table><tr><td>Jalan Uji 1</td></tr><tr><td>Telepon 000</td></tr></table>Laman: contoh.test',
            'Jalan Uji 1<hr />Telepon 000<HR>Laman: contoh.test',
            "<p>Jalan   Uji 1</p>\n\n<p>Telepon 000<br /></p>\n<p>Laman: contoh.test</p>",
        ] as $alamat) {
            $this->assertStringContainsString($harap, KopResmi::html('K', '', $alamat), $alamat);
        }

        $this->assertStringContainsString('<b>KEMENTERIAN UJI</b>', KopResmi::html('<p>Kementerian</p><p>Uji</p>', '', ''), 'baris 1: blok → spasi');

        // Sel tabel bersebelahan dipisah spasi (tidak menyatu), baris tabel tetap jadi baris sendiri.
        $this->assertSame("Jalan Uji 1 Jakarta\nTelepon 000", KopResmi::teksAlamat('<table><tr><th>Jalan Uji 1</th><td>Jakarta</td></tr><tr><td>Telepon</td><td>000</td></tr></table>'));
        $this->assertSame('Kementerian Uji', KopResmi::satuBaris('<table><tr><td>Kementerian</td><td>Uji</td></tr></table>'));
    }

    public function testBaris1DanBaris2TeksPolosSatuBarisDiEscape(): void
    {
        $kop = KopResmi::html('Kementerian<br>Uji <script>alert(1)</script> &amp; "Kawan"', 'Biro<br />A &lt; B <i>miring</i>', '');

        $this->assertStringContainsString('<b>KEMENTERIAN UJI ALERT(1) &amp; &quot;KAWAN&quot;</b>', $kop, '<br> → spasi, tag dibuang, di-escape, huruf besar');
        $this->assertStringContainsString('<b>BIRO A &lt; B MIRING</b>', $kop);
        $this->assertStringNotContainsString('<script', $kop);
        $this->assertStringNotContainsString('<i>', $kop);
        $this->assertStringNotContainsString('font-size:9pt', $kop, 'alamat kosong → tanpa baris alamat');
    }

    public function testAlamatHanyaGantiBarisYangDipertahankan(): void
    {
        $kop = KopResmi::html('K', '', "Jalan <b>Tebal</b> <a href=\"https://contoh.test\">laman</a><br />\nLantai 2 &amp; 3\n<img src=x onerror=alert(1)>Pos-el: a@contoh.test<br><br>");

        $this->assertStringContainsString('<span style="font-size:9pt;">Jalan Tebal laman<br />' . "\n" . 'Lantai 2 &amp; 3<br />' . "\n" . 'Pos-el: a@contoh.test</span>', $kop);
        $this->assertStringNotContainsString('<a ', $kop);
        $this->assertStringNotContainsString('onerror', $kop);
        $this->assertSame(2, substr_count($kop, '<img '), 'hanya dua logo; <img> di alamat dibuang');
    }

    public function testBaris2KosongDihilangkanDanBaris1KosongPakaiNamaBawaan(): void
    {
        $kop = KopResmi::html(' <br> ', '  ', 'Jalan Uji');

        $this->assertStringContainsString('<b>' . mb_strtoupper(KopDokumen::NAMA_INSTANSI_BAWAAN) . '</b>', $kop, 'baris 1 tidak pernah kosong');
        $this->assertStringNotContainsString('font-size:11pt', $kop, 'baris 2 kosong dihilangkan');
        $this->assertSame(1, substr_count($kop, '<b>'));
    }

    public function testGarisBisaDimatikan(): void
    {
        $this->assertStringContainsString('border-top:2px solid #000000', KopResmi::html('K', '', ''));
        $this->assertStringNotContainsString('border-top', KopResmi::html('K', '', '', garis: false));
    }

    public function testLogoBawaanAdaDiRepoPngTanpaTransparansi(): void
    {
        foreach ([KopResmi::BERKAS_LOGO_KIRI, KopResmi::BERKAS_LOGO_KANAN] as $berkas) {
            $path = KopResmi::logoBawaan($berkas);
            $this->assertFileExists($path);

            $info = getimagesize($path);
            $this->assertIsArray($info);
            $this->assertSame(IMAGETYPE_PNG, $info[2]);

            $isi = (string) file_get_contents($path);
            // IHDR: byte 25 = tipe warna (0 abu-abu, 2 RGB, 3 palet); 4/6 = beralfa → TCPDF memecah kanal alfa (lambat, PDF besar).
            $this->assertContains(ord($isi[25]), [0, 2, 3], $berkas . ' tanpa kanal alfa');
            $this->assertStringNotContainsString('tRNS', $isi, $berkas . ' tanpa transparansi palet');
            $this->assertLessThan(64 * 1024, strlen($isi), $berkas . ' ringan');
        }
    }

    public function testOverrideLogoDariWebConfigDanSkalaKeKotakLogo(): void
    {
        [$kiri, $kanan] = KopResmi::logo(['logo_kementerian_pdf' => 'kop/kiri.png', 'logo_wonderful_pdf' => 'kop/kanan.png'], $this->akar);

        $this->assertSame(realpath($this->akar . '/kop/kiri.png'), $kiri);
        $this->assertSame(realpath($this->akar . '/kop/kanan.png'), $kanan);

        $kop = KopResmi::html('K', '', '', $kiri, $kanan);

        // 40×40 → dibatasi tinggi kotak (±24 mm); 100×20 → dibatasi lebar kotak (±26 mm), diturunkan ke tengah.
        $this->assertStringContainsString('<img src="@' . $this->base64((string) $kiri) . '" width="68" height="68" />', $kop);
        $this->assertStringContainsString('<div style="font-size:26px;line-height:1;">&nbsp;</div><img src="@' . $this->base64((string) $kanan) . '" width="74" height="15" />', $kop);
        $this->assertStringNotContainsString($this->base64(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KIRI)), $kop);
        $this->assertStringNotContainsString($this->base64(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KANAN)), $kop);
    }

    public function testWebConfigKosongAtauTidakSahPakaiLogoBawaan(): void
    {
        $bawaanKiri  = $this->base64(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KIRI));
        $bawaanKanan = $this->base64(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KANAN));

        foreach ([
            'kosong'            => [],
            'string kosong'     => ['logo_kementerian_pdf' => '', 'logo_wonderful_pdf' => ''],
            'traversal/absolut' => ['logo_kementerian_pdf' => '../kop/kiri.png', 'logo_wonderful_pdf' => $this->akar . '/kop/kanan.png'],
            'tidak ada'         => ['logo_kementerian_pdf' => 'kop/tidak-ada.png', 'logo_wonderful_pdf' => 'kop/tidak-ada.jpg'],
            'bukan gambar'      => ['logo_kementerian_pdf' => 'palsu.png', 'logo_wonderful_pdf' => 'palsu.png'],
        ] as $kasus => $wc) {
            $kop = KopResmi::html('K', '', '', ...KopResmi::logo($wc, $this->akar));

            $this->assertSame(1, substr_count($kop, $bawaanKiri), $kasus . ': logo kiri bawaan');
            $this->assertSame(1, substr_count($kop, $bawaanKanan), $kasus . ': logo kanan bawaan');
            $this->assertSame(2, substr_count($kop, '<img '), $kasus);
        }
    }

    public function testLogoKananUtamaDidahulukanLaluWebConfigLaluBawaan(): void
    {
        $wc = ['logo_wonderful_pdf' => 'kop/kanan.png'];

        $this->assertSame(realpath($this->akar . '/satker.png'), KopResmi::logo($wc, $this->akar, 'satker.png')[1]);
        $this->assertSame(realpath($this->akar . '/kop/kanan.png'), KopResmi::logo($wc, $this->akar, 'tidak-ada.png')[1], 'utama tidak ada → web_config');
        $this->assertSame(realpath($this->akar . '/kop/kanan.png'), KopResmi::logo($wc, $this->akar, null)[1]);
        $this->assertNull(KopResmi::logo([], $this->akar, '../satker.png')[1], 'semua tidak sah → null (bawaan dipakai html())');
    }

    public function testDirenderTcpdfDenganDuaLogoTertanam(): void
    {
        $pdf = (new TcpdfRenderer())->render(new DokumenPdf('Kop', '<p>ISI</p>', KopResmi::html('Kementerian Uji', 'Unit Uji', 'Jalan Uji'), marginAtasKop: 42));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, preg_match_all('/\/Subtype\s*\/Image/', $pdf), 'dua logo bawaan tertanam, tanpa topeng alfa');
        $this->assertLessThan(120 * 1024, strlen($pdf), 'PDF berlogo bawaan tetap ringan');
    }

    /**
     * Kop yang lebih tinggi dari `marginAtasKop` (alamat berbaris banyak) tidak boleh ditimpa badan dokumen.
     */
    public function testBadanTidakMenimpaKopYangLebihTinggiDariMargin(): void
    {
        $alamat = implode('<br />', array_map(static fn (int $i): string => 'ALAMATBARIS' . $i, range(1, 12)));
        $pdf    = (new TcpdfRenderer())->render(new DokumenPdf('Kop', '<p>AWALBADAN</p>', KopResmi::html('K', 'U', $alamat), marginAtasKop: 20));

        $yAlamat = $this->yTeks($pdf, 'ALAMATBARIS12');
        $yBadan  = $this->yTeks($pdf, 'AWALBADAN');

        // Sumbu y PDF naik ke atas: badan harus lebih rendah daripada baris alamat terakhir.
        $this->assertLessThan($yAlamat, $yBadan, 'badan dimulai di bawah baris terakhir kop');

        // Kop yang muat di margin: posisi badan tetap ditentukan marginAtasKop (tidak bergeser).
        $a = $this->yTeks((new TcpdfRenderer())->render(new DokumenPdf('Kop', '<p>AWALBADAN</p>', KopResmi::html('K', 'U', 'Jalan Uji'), marginAtasKop: 42)), 'AWALBADAN');
        $b = $this->yTeks((new TcpdfRenderer())->render(new DokumenPdf('Kop', '<p>AWALBADAN</p>', '<p>kop pendek</p>', marginAtasKop: 42)), 'AWALBADAN');
        $this->assertEqualsWithDelta($b, $a, 0.01);

        // Kop lengkap (logo ±24 mm, tiga baris alamat) muat di margin lanskap 40 dan potret 42: garis di atas badan.
        $alamat3 = 'Jalan Uji 1<br />Telepon 000<br />Laman: contoh.test';

        foreach ([['L', 40.0, true], ['P', 42.0, false]] as [$orientasi, $margin, $lanskap]) {
            $pdf   = (new TcpdfRenderer())->render(new DokumenPdf('Kop', '<p>AWALBADAN</p>', KopResmi::html('Kementerian Uji', 'Unit Uji', $alamat3, lanskap: $lanskap), orientasi: $orientasi, marginAtasKop: $margin));
            $tanpa = (new TcpdfRenderer())->render(new DokumenPdf('Kop', '<p>AWALBADAN</p>', '<p>kop pendek</p>', orientasi: $orientasi, marginAtasKop: $margin));
            $this->assertEqualsWithDelta($this->yTeks($tanpa, 'AWALBADAN'), $this->yTeks($pdf, 'AWALBADAN'), 0.01, $orientasi . ': badan tetap di marginAtasKop');
        }
    }

    /**
     * Halaman lanjutan tabel TANPA <thead> (mis. Struktur Organisasi) mulai di margin atas 10 mm, bukan di margin kop
     * halaman pertama (TCPDF memulihkan margin dari `pagedim` saat tabel melintasi halaman).
     */
    public function testHalamanLanjutanTabelTanpaTheadTidakMewarisiMarginKop(): void
    {
        $baris = '';

        for ($i = 1; $i <= 90; $i++) {
            $baris .= '<tr><td>JABATAN' . $i . 'X</td><td>Pejabat ' . $i . '</td></tr>';
        }

        $html = '<table border="1" cellpadding="4" width="100%">' . $baris . '</table>';

        // Potret (DRH/LKH/surat: margin kop 42): halaman lanjutan juga mulai di margin 10 mm.
        $potret      = (new TcpdfRenderer())->render(new DokumenPdf('S', $html, KopResmi::html('K', 'U', 'Jalan Uji'), marginAtasKop: 42.0));
        $potretTanpa = (new TcpdfRenderer())->render(new DokumenPdf('S', $html));
        $this->assertEqualsWithDelta($this->yTeksPertamaHalaman($potretTanpa, 2), $this->yTeksPertamaHalaman($potret, 2), 0.01, 'potret: halaman 2 mulai di margin 10 mm');
        $this->assertLessThanOrEqual($this->jumlahHalaman($potretTanpa) + 1, $this->jumlahHalaman($potret));

        $dengan = (new TcpdfRenderer())->render(new DokumenPdf('S', $html, KopResmi::html('K', 'U', 'Jalan Uji', lanskap: true), orientasi: 'L', marginAtasKop: 40.0));
        $tanpa  = (new TcpdfRenderer())->render(new DokumenPdf('S', $html, orientasi: 'L'));

        // Baris pertama halaman 2 dokumen berkop = baris ke-N; di halaman itu posisinya harus sama dengan baris pertama
        // halaman 2 dokumen tanpa kop (margin 10 mm), dan jumlah halaman hanya boleh bertambah akibat kop halaman 1.
        $this->assertEqualsWithDelta($this->yTeksPertamaHalaman($tanpa, 2), $this->yTeksPertamaHalaman($dengan, 2), 0.01, 'halaman 2 mulai di margin 10 mm');
        $this->assertEqualsWithDelta($this->yTeksPertamaHalaman($tanpa, 3), $this->yTeksPertamaHalaman($dengan, 3), 0.01, 'halaman 3 mulai di margin 10 mm');
        $this->assertLessThanOrEqual($this->jumlahHalaman($tanpa) + 1, $this->jumlahHalaman($dengan));
        $this->assertSame(4, $this->jumlahHalaman($tanpa));
    }

    private function jumlahHalaman(string $pdf): int
    {
        return (int) preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf);
    }

    /**
     * Koordinat y teks pertama pada aliran isi halaman ke-$halaman (urutan aliran yang memuat teks tabel).
     */
    private function yTeksPertamaHalaman(string $pdf, int $halaman): float
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $aliran);
        $ke = 0;

        foreach ($aliran[1] as $mentah) {
            $isi = @gzuncompress($mentah);

            if ($isi === false || ! str_contains($isi, 'JABATAN')) {
                continue;
            }

            if (++$ke === $halaman) {
                $sebelum = substr($isi, 0, (int) strpos($isi, 'JABATAN'));
                $this->assertGreaterThan(0, preg_match_all('/BT\s+[-\d.]+\s+([-\d.]+)\s+Td/', $sebelum, $m));

                return (float) end($m[1]);
            }
        }

        $this->fail('halaman ' . $halaman . ' tidak ditemukan');
    }

    private function base64(string $path): string
    {
        return base64_encode((string) file_get_contents($path));
    }

    /**
     * Koordinat y (pt, dari bawah halaman) teks $cari di aliran isi PDF (aliran FlateDecode diurai).
     */
    private function yTeks(string $pdf, string $cari): float
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $aliran);

        foreach ($aliran[1] as $mentah) {
            $isi = @gzuncompress($mentah);

            if ($isi === false || ! str_contains($isi, $cari)) {
                continue;
            }

            $sebelum = substr($isi, 0, (int) strpos($isi, $cari));

            if (preg_match_all('/BT\s+[-\d.]+\s+([-\d.]+)\s+Td/', $sebelum, $m) > 0) {
                return (float) end($m[1]);
            }
        }

        $this->fail('teks "' . $cari . '" tidak ditemukan di PDF');
    }
}
