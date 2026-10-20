<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Interfaces\PdfRendererInterface;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\SlipGaji\FormatSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\RingkasanSlip;
use Closure;
use RuntimeException;

/**
 * Generator PDF slip gaji (PRD 5A §7.3.6, ADR-032): TCPDF lewat `Pdf::renderer()`, view `pdf/slip_gaji` — port tata
 * letak legacy `771f8b4` `views/hr/employee/sl_gaji/print/cetak.php` (A4 potret, margin 12 mm, helvetica 8,5; kop
 * di dalam kotak dokumen; A. GPP → B. TK → C. Uang Makan → Ringkasan) [K `Lsl_gaji.php:468-588` `cetak_pdf`].
 *
 * - Total = `RingkasanSlip` (`bersih_gpp + bersih_tk + uang_makan`, F5-1); baris tukin kelas jabatan tidak ada.
 * - QR berisi URL verifikasi lewat `Pdf::qrImg`; **wajib gambar**: bila keluaran bukan `<img>` (GD/Imagick tidak ada),
 *   cetak gagal ({@see RuntimeException}, dijawab 500 generik) — dilarang mencetak teks token (§7.3.6). Tanpa token aktif
 *   → tanpa QR (U-3).
 * - Tanggal cetak tanpa jam (P-5, `FormatSlip::tanggalCetak`); waktu dibuka "4 Oktober 2026, 10:00 WIB" (P-6) bila ada.
 */
final class PdfSlipGaji
{
    public const VIEW = 'pdf/slip_gaji';

    /** @var Closure(string, int): string */
    private readonly Closure $pembuatQr;

    /**
     * @param (Closure(string, int): string)|null $pembuatQr bawaan `Pdf::qrImg`; disuntik di test untuk jalur gagal
     */
    public function __construct(private readonly PdfRendererInterface $renderer, ?Closure $pembuatQr = null)
    {
        $this->pembuatQr = $pembuatQr ?? Pdf::qrImg(...);
    }

    public static function bawaan(): self
    {
        return new self(Pdf::renderer());
    }

    public function render(DataPdfSlip $data): string
    {
        $qr = null;

        if ($data->urlQr !== null) {
            $qr = ($this->pembuatQr)($data->urlQr, 128);

            if (! str_starts_with($qr, '<img')) {
                throw new RuntimeException('QR verifikasi slip gagal dibuat (ekstensi gd/imagick tidak tersedia).');
            }
        }

        $html = view(self::VIEW, [
            'd'         => $data,
            'qr'        => $qr,
            'ringkasan' => RingkasanSlip::hitung($data->sen),
            'labelGpp'  => KolomGaji::GPP,
            'labelTk'   => KolomGaji::TK,
            'labelUm'   => self::labelUangMakan($data->uangMakanPeriode),
            'tglCetak'  => FormatSlip::tanggalCetak($data->dicetakPada),
            'dibuka'    => $data->dibukaPada === null ? null : FormatSlip::waktuWib($data->dibukaPada),
            'logoKiri'  => self::logo($data->logoKiri, 97),
            'logoKanan' => self::logo($data->logoKanan, 87),
        ], ['saveData' => false]);

        return $this->renderer->render(new DokumenPdf(
            'Slip Gaji ' . $data->periode->label(),
            $html,
            marginSisi: 12.0,
        ));
    }

    /**
     * Tag `<img>` logo kop tersemat (`@base64`, pola `Pdf::qrImg`); berkas tidak ada/bukan PNG/JPEG → '' (tanpa logo,
     * tidak gagal; PRD 5A §7.3.6).
     */
    public static function logo(?string $path, int $lebarPx): string
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return '';
        }

        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return '';
        }

        $isi = @file_get_contents($path);

        return $isi === false ? '' : '<img src="@' . base64_encode($isi) . '" width="' . $lebarPx . '" />';
    }

    /**
     * "Uang Makan (Presensi {Bulan} {Tahun})" dari `uang_makan_periode` `m-YYYY` [K `print/cetak.php:362-365` @771f8b4];
     * format lain/kosong → "Uang Makan".
     */
    public static function labelUangMakan(?string $periode): string
    {
        if ($periode !== null && preg_match('/^(\d{1,2})-(\d{4})\z/', $periode, $m) === 1 && isset(TanggalBisnis::NAMA_BULAN[(int) $m[1]])) {
            return 'Uang Makan (Presensi ' . TanggalBisnis::NAMA_BULAN[(int) $m[1]] . ' ' . $m[2] . ')';
        }

        return 'Uang Makan';
    }
}
