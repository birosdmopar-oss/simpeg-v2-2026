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

        // 40×40 → dibatasi tinggi kotak; 100×20 → dibatasi lebar kotak, diturunkan ke tengah.
        $this->assertStringContainsString('<img src="@' . $this->base64((string) $kiri) . '" width="57" height="57" />', $kop);
        $this->assertStringContainsString('<div style="font-size:21px;line-height:1;">&nbsp;</div><img src="@' . $this->base64((string) $kanan) . '" width="74" height="15" />', $kop);
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
