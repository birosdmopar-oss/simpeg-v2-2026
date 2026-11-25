<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Interfaces\PdfRendererInterface;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopResmi;
use App\Libraries\Presensi\Slip\DataPdfSlip;
use App\Libraries\Presensi\Slip\PdfSlipGaji;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * PRD 5A §7.3.6 (PDF slip): PDF valid lewat TCPDF; isi view (identitas ter-escape, A. GPP → B. TK → C. Uang Makan →
 * Ringkasan, total termasuk uang makan F5-1, label uang makan bulan−1); QR wajib gambar (keluaran bukan `<img>` → gagal,
 * dilarang teks token); tanpa token → tanpa QR (U-3); kop seragam `KopResmi` (CR-191; validasi logo PNG/JPEG di `KopResmiTest`).
 *
 * @internal
 */
final class PdfSlipGajiTest extends CIUnitTestCase
{
    public function testPdfValidDenganQrGambar(): void
    {
        $isi = PdfSlipGaji::bawaan()->render($this->data());

        $this->assertStringStartsWith('%PDF-', $isi);
        $this->assertStringContainsString('%%EOF', $isi);
        // Logo kop bawaan selalu tertanam (CR-191), jadi QR dibuktikan dari JUMLAH gambar: tanpa token tepat dua logo;
        // dengan token bertambah gambar QR (PNG QR beralfa = gambar + topengnya).
        $tanpaQr = preg_match_all('/\/Subtype\s*\/Image/', PdfSlipGaji::bawaan()->render($this->data(urlQr: null)));
        $this->assertSame(2, $tanpaQr, 'tanpa token: hanya dua logo kop');
        $this->assertGreaterThan($tanpaQr, preg_match_all('/\/Subtype\s*\/Image/', $isi), 'QR tertanam sebagai gambar di samping dua logo');
    }

    public function testIsiViewUrutanBagianTotalDanEscape(): void
    {
        $penangkap = $this->penangkap();
        (new PdfSlipGaji($penangkap, static fn (string $teks, int $px): string => '<img src="@QR" height="' . $px . '" />'))
            ->render($this->data(nama: 'Ana <b>&</b> Budi'));

        $this->assertNotNull($penangkap->dokumen);
        $html = $penangkap->dokumen->html;
        $this->assertSame('Slip Gaji Oktober 2026', $penangkap->dokumen->judul);
        $this->assertStringContainsString('Ana &lt;b&gt;&amp;&lt;/b&gt; Budi', $html);
        $this->assertStringNotContainsString('<b>&</b>', $html);
        $this->assertStringContainsString('<img src="@QR" height="128" />', $html);
        $this->assertStringContainsString('Jakarta, 20 Oktober 2026', $html);
        $this->assertStringContainsString('Dibuka: 20 Oktober 2026, 10:00 WIB', $html);
        // Kop seragam (CR-191): baris 1 dan baris 2 terpisah, alamat teks polos, dua logo bawaan (web_config kosong).
        $this->assertStringContainsString('<span style="font-size:12.5pt;"><b>KEMENTERIAN UJI</b></span><br /><span style="font-size:11pt;"><b>UNIT SDM</b></span>', $html);
        $this->assertStringContainsString('<span style="font-size:9pt;">Jl. Contoh laman</span>', $html);
        $this->assertStringNotContainsString('<a ', $html, 'tautan kop dibuang');
        $this->assertStringContainsString(base64_encode((string) file_get_contents(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KIRI))), $html, 'logo kiri bawaan');
        $this->assertStringContainsString(base64_encode((string) file_get_contents(KopResmi::logoBawaan(KopResmi::BERKAS_LOGO_KANAN))), $html, 'logo kanan bawaan');
        $this->assertLessThan(strpos($html, 'SLIP GAJI'), strpos($html, 'border-top:2px solid #000000'), 'garis kop di atas judul');

        $posisi = array_map(static fn (string $s): int|false => strpos($html, $s), [
            'A. GAJI POKOK &amp; PENDAPATAN (GPP)', 'B. TUNJANGAN KINERJA (TK)', 'C. UANG MAKAN', 'RINGKASAN PENGHASILAN',
        ]);
        $this->assertNotContains(false, $posisi);
        $urut = $posisi;
        sort($urut);
        $this->assertSame($urut, $posisi);

        $this->assertStringContainsString('Uang Makan (Presensi September 2026)', $html);
        // bersih_gpp 4.000.000 + bersih_tk 3.000.000 + uang makan 500.000 (F5-1).
        $this->assertMatchesRegularExpression('/Total Penghasilan Bulan Oktober 2026.*?7\.500\.000/s', $html);
    }

    public function testBagianTkTanpaPajakDanTunjanganPajak(): void
    {
        // CR-067 [K print/cetak.php @0cf7e67 :274-277, :360-361]: baris "Pajak"/"Tunjangan Pajak" tidak tampil; jumlah per
        // kolom TK hanya dari baris yang tampil (kotor / potongan); total slip tetap dari bersih_tk.
        $penangkap = $this->penangkap();
        (new PdfSlipGaji($penangkap, static fn (): string => '<img src="@QR" />'))
            ->render($this->data(sen: ['pajak' => 7_000_000, 'tunj_pajak' => 9_000_000]));

        $this->assertNotNull($penangkap->dokumen);
        $html = $penangkap->dokumen->html;
        $tk   = substr($html, (int) strpos($html, 'B. TUNJANGAN KINERJA (TK)'), (int) strpos($html, 'C. UANG MAKAN') - (int) strpos($html, 'B. TUNJANGAN KINERJA (TK)'));
        $this->assertStringNotContainsString('Pajak', $tk);
        $this->assertStringNotContainsString('70.000', $tk);
        $this->assertStringNotContainsString('90.000', $tk);
        $this->assertMatchesRegularExpression('/Jumlah Penghasilan.*?3\.200\.000/s', $tk);
        $this->assertMatchesRegularExpression('/Jumlah Potongan.*?-&nbsp;200\.000/s', $tk);
        $this->assertMatchesRegularExpression('/Total Penghasilan Bulan Oktober 2026.*?7\.500\.000/s', $html);
    }

    public function testTanpaTokenTanpaQrDanTanpaWaktuDibuka(): void
    {
        $penangkap = $this->penangkap();
        $dipanggil = false;
        (new PdfSlipGaji($penangkap, static function () use (&$dipanggil): string {
            $dipanggil = true;

            return '<img />';
        }))->render($this->data(urlQr: null, dibuka: false));

        $this->assertNotNull($penangkap->dokumen);
        $html = $penangkap->dokumen->html;
        $this->assertFalse($dipanggil);
        $this->assertSame(2, substr_count($html, '<img'), 'hanya dua logo kop (CR-191), tanpa QR');
        $this->assertStringNotContainsString('Kode QR', $html);
        $this->assertStringNotContainsString('Dibuka:', $html);
        $this->assertStringContainsString('Generated by SIMPEG', $html);
    }

    public function testQrBukanGambarGagalTanpaMencetakToken(): void
    {
        $penangkap = $this->penangkap();
        $slip      = new PdfSlipGaji($penangkap, static fn (string $teks): string => '<span>' . $teks . '</span>');

        try {
            $slip->render($this->data());
            $this->fail('harus gagal');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('token-rahasia', $e->getMessage());
        }

        $this->assertNull($penangkap->dokumen, 'tidak ada dokumen yang dirender');
    }

    public function testLabelUangMakan(): void
    {
        $this->assertSame('Uang Makan (Presensi Desember 2026)', PdfSlipGaji::labelUangMakan('12-2026'));
        $this->assertSame('Uang Makan (Presensi Januari 2027)', PdfSlipGaji::labelUangMakan('1-2027'));
        $this->assertSame('Uang Makan', PdfSlipGaji::labelUangMakan(null));
        $this->assertSame('Uang Makan', PdfSlipGaji::labelUangMakan('13-2026'));
        $this->assertSame('Uang Makan', PdfSlipGaji::labelUangMakan('2026-10'));
    }

    /**
     * @param array<string, int> $sen
     */
    private function data(string $nama = 'Ana Budi', ?string $urlQr = 'https://contoh.test/verify/slip/token-rahasia', bool $dibuka = true, array $sen = []): DataPdfSlip
    {
        $tambahan = $sen;
        $sen      = array_fill_keys(KolomGaji::semuaField(), 0);
        $sen      = [
            ...$sen,
            'gjpokok'    => 300_000_000, 'tjistri' => 50_000_000, 'tjberas' => 100_000_000,
            'potpfk10'   => 50_000_000, 'bersih_gpp' => 400_000_000,
            'kotor'      => 320_000_000, 'potongan' => 20_000_000, 'bersih_tk' => 300_000_000,
            'uang_makan' => 50_000_000,
            ...$tambahan,
        ];
        $zona = new DateTimeZone('UTC');

        return new DataPdfSlip(
            PeriodeSlip::buat(2026, 10),
            ['nama' => $nama, 'nip' => '199001012020121001', 'pangkat' => 'Penata / III/c', 'jabatan' => 'Analis', 'unit' => 'Biro SDM', 'satker' => 'Sekretariat'],
            $sen,
            '9-2026',
            ['nama_kementerian' => 'Kementerian Uji<br>', 'nama_unit' => 'Unit<br />SDM', 'alamat' => 'Jl. Contoh <a href="https://contoh.test">laman</a>'],
            null,
            null,
            $urlQr,
            new DateTimeImmutable('2026-10-20 02:00:00', $zona),
            $dibuka ? new DateTimeImmutable('2026-10-20 03:00:00', $zona) : null,
        );
    }

    private function penangkap(): PenangkapPdfSlip
    {
        return new PenangkapPdfSlip();
    }
}

/**
 * Renderer uji: hanya menyimpan dokumen terakhir (render TCPDF sungguhan diuji lewat `PdfSlipGaji::bawaan()`).
 *
 * @internal
 */
final class PenangkapPdfSlip implements PdfRendererInterface
{
    public ?DokumenPdf $dokumen = null;

    public function render(DokumenPdf $dokumen): string
    {
        $this->dokumen = $dokumen;

        return '%PDF-uji';
    }
}
