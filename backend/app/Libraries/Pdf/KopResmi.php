<?php

declare(strict_types=1);

namespace App\Libraries\Pdf;

use App\Libraries\Presensi\Slip\KopSlip;
use Config\Presensi;

/**
 * Kop resmi SATU komponen untuk semua PDF unduhan (CR-191).
 *
 * **[CATAT] Kop seragam (keputusan Horii 24-11-2026, acuan gambar)** — menggantikan keputusan F7-15 "tanpa logo"
 * (dokumen daftar, surat Cuti/TB/IB, DRH, LKH, tanda terima) dan tata letak kop Slip Gaji / Laporan Tukin sebelumnya:
 *
 * | Posisi | Isi |
 * |---|---|
 * | Kiri | logo kementerian |
 * | Tengah | baris 1 nama kementerian (tebal, 12,5pt, huruf besar); baris 2 unit (tebal, 11pt, huruf besar; kosong → baris dihilangkan); alamat (9pt, baris demi baris) |
 * | Kanan | logo Wonderful Indonesia (Laporan Tukin: logo satker pada cabang legacy) |
 * | Bawah | satu garis hitam (K3) |
 *
 * Komponen ini HANYA menata letak. Sumber teks per dokumen (nama, unit/satker, alamat) tetap ditentukan pemanggil
 * masing-masing seperti sebelum CR-191; komponen tidak membaca `web_config` selain lewat {@see self::logo()}.
 *
 * - **Teks:** baris 1/2 dijadikan satu baris teks polos (`<br>` → spasi, tag lain dibuang) lalu di-escape. Alamat boleh
 *   berasal dari HTML tersanitasi `web_config` atau teks master unit/satker: `<br>` dan baris baru menjadi ganti baris,
 *   SEMUA tag lain dibuang, isinya di-escape — tidak ada HTML masukan yang dirender apa adanya.
 * - **Logo:** path berkas yang sudah di-resolve pemanggil. `null`, berkas tidak ada, atau bukan PNG/JPEG → logo bawaan
 *   di repo (`app/Assets/Kop/`, K4), sehingga kop tetap berlogo walau `web_config` kosong. Override `web_config`
 *   (`logo_kementerian_pdf` / `logo_wonderful_pdf`) tetap lewat {@see KopSlip::resolveAset()} — aturan akar aset tidak
 *   berubah. Logo disematkan `@base64` dan diskalakan ke kotak {@see self::LEBAR_LOGO_PX} × {@see self::TINGGI_LOGO_PX}
 *   (±26 × 20 mm) dengan rasio terjaga; logo yang lebih pendek dari kotak diturunkan ke tengah.
 */
final class KopResmi
{
    /** Tinggi kotak logo, px TCPDF (1 px = 1/72 inci = 0,353 mm → ±20 mm). */
    public const TINGGI_LOGO_PX = 57;

    /** Lebar kotak logo, px TCPDF (±26 mm; muat di kolom 15% halaman A4 potret). */
    public const LEBAR_LOGO_PX = 74;

    public const BERKAS_LOGO_KIRI = 'logo-kementerian.png';

    public const BERKAS_LOGO_KANAN = 'logo-wonderful-indonesia.png';

    /** @var array<string, string> tag `<img>` per berkas (kunci path|ukuran|mtime) — satu proses merender banyak PDF */
    private static array $cacheImg = [];

    private function __construct()
    {
    }

    /**
     * HTML kop untuk `writeHTML` TCPDF: `[logo kiri | blok teks | logo kanan]` + garis.
     *
     * @param string      $baris1    nama kementerian (boleh ber-`<br>`/tag; dijadikan teks polos huruf besar); kosong →
     *                               {@see KopDokumen::NAMA_INSTANSI_BAWAAN} (baris 1 tidak pernah kosong, semua PDF)
     * @param string      $baris2    nama unit/satker; kosong → baris dihilangkan
     * @param string      $alamat    alamat (HTML tersanitasi `web_config` atau teks master); hanya ganti baris dipertahankan
     * @param string|null $logoKiri  path berkas logo kiri; null/tidak sah → bawaan
     * @param string|null $logoKanan path berkas logo kanan; null/tidak sah → bawaan
     * @param bool        $garis     garis hitam di bawah kop (K3)
     */
    public static function html(string $baris1, string $baris2, string $alamat, ?string $logoKiri = null, ?string $logoKanan = null, bool $garis = true): string
    {
        $baris1 = self::satuBaris($baris1);
        $baris1 = $baris1 !== '' ? $baris1 : KopDokumen::NAMA_INSTANSI_BAWAAN;
        $baris2 = self::satuBaris($baris2);
        $alamat = self::teksAlamat($alamat);
        $teks   = [];

        $teks[] = '<span style="font-size:12.5pt;"><b>' . esc(mb_strtoupper($baris1)) . '</b></span>';

        if ($baris2 !== '') {
            $teks[] = '<span style="font-size:11pt;"><b>' . esc(mb_strtoupper($baris2)) . '</b></span>';
        }

        if ($alamat !== '') {
            $teks[] = '<span style="font-size:9pt;">' . nl2br(esc($alamat)) . '</span>';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="15%" align="left">' . self::img($logoKiri, self::BERKAS_LOGO_KIRI) . '</td>'
            . '<td width="70%" align="center" style="line-height:1.15;">' . implode('<br />', $teks) . '</td>'
            . '<td width="15%" align="right">' . self::img($logoKanan, self::BERKAS_LOGO_KANAN) . '</td>'
            . '</tr></table>'
            . ($garis ? '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<tr><td style="font-size:1px;line-height:4px;">&nbsp;</td></tr>'
                . '<tr><td style="border-top:2px solid #000000;font-size:1px;line-height:1px;">&nbsp;</td></tr></table>' : '');
    }

    /**
     * Path logo kiri/kanan dari `web_config` (override) — `null` berarti bawaan dipakai {@see self::html()}.
     *
     * @param array<string, mixed> $wc              nilai `web_config` (`values()` atau `mentah()`)
     * @param mixed                $logoKananUtama  path relatif yang didahulukan untuk logo kanan (mis. `satker.logo_uns`);
     *                                              tidak sah/tidak ada → `logo_wonderful_pdf`
     *
     * @return array{0: string|null, 1: string|null} [kiri, kanan]
     */
    public static function logo(array $wc, ?string $akarAset = null, mixed $logoKananUtama = null): array
    {
        $akarAset ??= config(Presensi::class)->akarAset;

        return [
            KopSlip::resolveAset($akarAset, $wc['logo_kementerian_pdf'] ?? null),
            KopSlip::resolveAset($akarAset, $logoKananUtama) ?? KopSlip::resolveAset($akarAset, $wc['logo_wonderful_pdf'] ?? null),
        ];
    }

    /**
     * Path logo bawaan di repo (K4).
     */
    public static function logoBawaan(string $berkas): string
    {
        return APPPATH . 'Assets/Kop/' . $berkas;
    }

    /**
     * Teks polos satu baris: `<br>` → spasi, tag dibuang, entitas diurai (di-escape lagi saat dirender).
     */
    public static function satuBaris(string $nilai): string
    {
        $polos = strip_tags((string) preg_replace('/<br\s*\/?>/i', ' ', $nilai));
        $polos = html_entity_decode($polos, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $polos));
    }

    /**
     * Teks polos banyak baris: `<br>` → baris baru, tag lain dibuang, entitas diurai, baris kosong di tepi dibuang.
     */
    public static function teksAlamat(string $nilai): string
    {
        $polos = strip_tags((string) preg_replace('/<br\s*\/?>\R?/i', "\n", $nilai));
        $polos = html_entity_decode($polos, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $baris = array_map(trim(...), preg_split('/\R/u', $polos) ?: []);

        return trim(implode("\n", $baris));
    }

    /**
     * Tag `<img>` logo tersemat yang muat di kotak logo; `$path` tidak sah → `$berkasBawaan`; bawaan pun tidak ada → ''.
     */
    private static function img(?string $path, string $berkasBawaan): string
    {
        foreach ([$path, self::logoBawaan($berkasBawaan)] as $calon) {
            if ($calon === null || $calon === '' || ! is_file($calon)) {
                continue;
            }

            $kunci = $calon . '|' . filesize($calon) . '|' . filemtime($calon);

            if (isset(self::$cacheImg[$kunci])) {
                return self::$cacheImg[$kunci];
            }

            $info = @getimagesize($calon);

            if ($info === false || $info[0] < 1 || $info[1] < 1 || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                continue;
            }

            $isi = @file_get_contents($calon);

            if ($isi === false) {
                continue;
            }

            $skala  = min(self::LEBAR_LOGO_PX / $info[0], self::TINGGI_LOGO_PX / $info[1]);
            $lebar  = max(1, (int) round($info[0] * $skala));
            $tinggi = max(1, (int) round($info[1] * $skala));
            $turun  = intdiv(self::TINGGI_LOGO_PX - $tinggi, 2);

            return self::$cacheImg[$kunci] = ($turun > 0 ? '<div style="font-size:' . $turun . 'px;line-height:1;">&nbsp;</div>' : '')
                . '<img src="@' . base64_encode($isi) . '" width="' . $lebar . '" height="' . $tinggi . '" />';
        }

        return '';
    }
}
