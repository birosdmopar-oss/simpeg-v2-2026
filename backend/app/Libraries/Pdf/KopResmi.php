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
 * | Bawah | satu garis hitam 2px (K3) |
 *
 * Komponen ini HANYA menata letak. Sumber teks per dokumen (unit/satker, alamat) tetap ditentukan pemanggil
 * masing-masing seperti sebelum CR-191; komponen tidak membaca `web_config` selain lewat {@see self::nama()} dan
 * {@see self::logo()}.
 *
 * - **Baris 1:** rantai SATU untuk semua PDF ({@see self::nama()}): `pdf_header_nama_kementerian` → `nama_kementerian`
 *   → {@see KopDokumen::NAMA_INSTANSI_BAWAAN}; "kosong" dinilai pada teks polosnya, jadi baris 1 tidak pernah kosong.
 * - **Teks:** baris 1/2 dijadikan satu baris teks polos (`<br>` dan penutup blok → spasi, tag lain dibuang, isinya
 *   dipertahankan) lalu di-escape. Alamat boleh berasal dari HTML tersanitasi `web_config` atau teks master
 *   unit/satker: `<br>`, penutup blok (`</p>`, `</div>`, `</li>`, `</tr>`, judul, `<hr>`) dan baris baru menjadi ganti
 *   baris, SEMUA tag lain dibuang (isinya dipertahankan), hasilnya di-escape — tidak ada HTML masukan yang dirender apa
 *   adanya.
 * - **Logo:** path berkas yang sudah di-resolve pemanggil. `null`, berkas tidak ada, atau bukan PNG/JPEG → logo bawaan
 *   di repo (`app/Assets/Kop/`, K4), sehingga kop tetap berlogo walau `web_config` kosong. Override `web_config`
 *   (`logo_kementerian_pdf` / `logo_wonderful_pdf`) tetap lewat {@see KopSlip::resolveAset()} — aturan akar aset tidak
 *   berubah. Logo disematkan `@base64` dan diskalakan ke kotak {@see self::LEBAR_LOGO_PX} × {@see self::TINGGI_LOGO_PX}
 *   (±26 × 24 mm) dengan rasio terjaga; logo dan blok teks yang lebih pendek dari kotak diturunkan ke tengah.
 * - **Lanskap:** `$lanskap = true` membatasi kop ±190 mm di tengah halaman (kolom pengisi di kiri/kanan), supaya logo
 *   tetap rapat ke teks seperti di halaman potret.
 */
final class KopResmi
{
    /** Tinggi kotak logo, px TCPDF (1 px = 1/72 inci = 0,353 mm → ±24 mm). */
    public const TINGGI_LOGO_PX = 68;

    /** Lebar kotak logo, px TCPDF (±26 mm; muat di kolom 15% halaman A4 potret). */
    public const LEBAR_LOGO_PX = 74;

    public const BERKAS_LOGO_KIRI = 'logo-kementerian.png';

    public const BERKAS_LOGO_KANAN = 'logo-wonderful-indonesia.png';

    /** Lebar kolom (persen): pengisi | logo | teks. */
    private const KOLOM_POTRET = [0, 15, 70];

    private const KOLOM_LANSKAP = [15.5, 10.5, 48];

    /** `<br>`, `<hr>`, dan penutup elemen blok: pemisah baris teks (alamat ber-`<p>`/daftar/tabel). */
    private const POLA_PEMISAH = '/<br\s*\/?>\R?|<hr\s*\/?>\R?|<\/(?:p|div|li|tr|h[1-6]|blockquote|table)\s*>\R?/i';

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
     * @param bool        $lanskap   halaman lanskap: kop dibatasi ±190 mm di tengah
     */
    public static function html(string $baris1, string $baris2, string $alamat, ?string $logoKiri = null, ?string $logoKanan = null, bool $garis = true, bool $lanskap = false): string
    {
        $baris1 = self::satuBaris($baris1);
        $baris1 = $baris1 !== '' ? $baris1 : KopDokumen::NAMA_INSTANSI_BAWAAN;
        $baris2 = self::satuBaris($baris2);
        $alamat = self::teksAlamat($alamat);
        $teks   = ['<span style="font-size:12.5pt;"><b>' . esc(mb_strtoupper($baris1)) . '</b></span>'];
        $tinggi = 12.5;

        if ($baris2 !== '') {
            $teks[] = '<span style="font-size:11pt;"><b>' . esc(mb_strtoupper($baris2)) . '</b></span>';
            $tinggi += 11;
        }

        if ($alamat !== '') {
            $teks[] = '<span style="font-size:9pt;">' . nl2br(esc($alamat)) . '</span>';
            $tinggi += 9 * (substr_count($alamat, "\n") + 1);
        }

        // Blok teks lebih pendek dari logo (mis. tanpa baris 2 / alamat) → diturunkan ke tengah.
        $turun                          = intdiv(self::TINGGI_LOGO_PX - (int) ceil($tinggi * 1.15), 2);
        [$sisi, $lebarLogo, $lebarTeks] = $lanskap ? self::KOLOM_LANSKAP : self::KOLOM_POTRET;
        $pengisi                        = $sisi > 0 ? '<td width="' . $sisi . '%"></td>' : '';

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $pengisi
            . '<td width="' . $lebarLogo . '%" align="left">' . self::img($logoKiri, self::BERKAS_LOGO_KIRI) . '</td>'
            . '<td width="' . $lebarTeks . '%" align="center" style="line-height:1.15;">' . self::spasi($turun) . implode('<br />', $teks) . '</td>'
            . '<td width="' . $lebarLogo . '%" align="right">' . self::img($logoKanan, self::BERKAS_LOGO_KANAN) . '</td>'
            . $pengisi . '</tr></table>'
            . ($garis ? '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<tr><td style="font-size:1px;line-height:4px;">&nbsp;</td></tr>'
                . '<tr><td style="border-top:2px solid #000000;font-size:1px;line-height:1px;">&nbsp;</td></tr></table>' : '');
    }

    /**
     * Nama kementerian baris 1 — rantai tunggal semua PDF: `pdf_header_nama_kementerian` → `nama_kementerian` →
     * {@see KopDokumen::NAMA_INSTANSI_BAWAAN}. Nilai dianggap kosong bila teks polosnya kosong (mis. hanya `<br>`).
     *
     * @param array<string, mixed> $wc nilai `web_config` (`values()` atau `mentah()`)
     */
    public static function nama(array $wc): string
    {
        foreach (['pdf_header_nama_kementerian', 'nama_kementerian'] as $key) {
            $nilai = $wc[$key] ?? null;

            if (is_string($nilai) && self::satuBaris($nilai) !== '') {
                return self::satuBaris($nilai);
            }
        }

        return KopDokumen::NAMA_INSTANSI_BAWAAN;
    }

    /**
     * Path logo kiri/kanan dari `web_config` (override) — `null` berarti bawaan dipakai {@see self::html()}.
     *
     * @param array<string, mixed> $wc             nilai `web_config` (`values()` atau `mentah()`)
     * @param mixed                $logoKananUtama path relatif yang didahulukan untuk logo kanan (mis. `satker.logo_uns`);
     *                                             tidak sah/tidak ada → `logo_wonderful_pdf`
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
     * Teks polos satu baris: `<br>` dan penutup blok → spasi, tag dibuang, entitas diurai (di-escape lagi saat dirender).
     */
    public static function satuBaris(string $nilai): string
    {
        $polos = strip_tags((string) preg_replace(self::POLA_PEMISAH, ' ', $nilai));
        $polos = html_entity_decode($polos, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $polos));
    }

    /**
     * Teks polos banyak baris: `<br>` dan penutup blok → baris baru, tag lain dibuang (isinya dipertahankan), entitas
     * diurai, baris kosong dibuang.
     */
    public static function teksAlamat(string $nilai): string
    {
        $polos = strip_tags((string) preg_replace(self::POLA_PEMISAH, "\n", $nilai));
        $polos = html_entity_decode($polos, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $baris = array_map(static fn (string $b): string => trim((string) preg_replace('/\s+/u', ' ', $b)), preg_split('/\R/u', $polos) ?: []);

        return implode("\n", array_filter($baris, static fn (string $b): bool => $b !== ''));
    }

    /**
     * Pengisi vertikal setinggi ±$px (trik `div` TCPDF) — '' bila tidak perlu.
     */
    private static function spasi(int $px): string
    {
        return $px >= 2 ? '<div style="font-size:' . $px . 'px;line-height:1;">&nbsp;</div>' : '';
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

            return self::$cacheImg[$kunci] = self::spasi(intdiv(self::TINGGI_LOGO_PX - $tinggi, 2))
                . '<img src="@' . base64_encode($isi) . '" width="' . $lebar . '" height="' . $tinggi . '" />';
        }

        return '';
    }
}
