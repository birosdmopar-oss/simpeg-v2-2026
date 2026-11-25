<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Pdf\KopResmi;
use App\Libraries\Presensi\Slip\KopSlip;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;

/**
 * Kop PDF laporan Tukin per pegawai — **berlogo, sumber data ikut legacy** (keputusan Horii 28-10-2026): pengisian
 * konstanta `MY_PDF_HEADER_UNIT` legacy `0cf7e67` (`L_presensi.php:7105-7118`) [K].
 *
 * [CATAT] Kop seragam (keputusan Horii 24-11-2026, acuan gambar; CR-191): TATA LETAK kini lewat {@see KopResmi}
 * (logo kiri | nama 12,5pt, unit 11pt, alamat 9pt | logo kanan, satu garis hitam) — menggantikan tata letak tiga baris
 * `MY_PDF_HEADER_UNIT` (nama 12pt, unit 9pt, dua garis putih 7px + hitam 2px). Logo yang tidak ada/tidak sah kini jatuh
 * ke logo bawaan repo, bukan sel kosong. Sumber nama/unit/alamat/logo kanan di bawah TIDAK berubah.
 *
 * Sumber v2 (tanpa path di-hard-code, pola CR-066 / slip gaji):
 * - nama: `web_config` `pdf_header_nama_kementerian` → `nama_kementerian` → `KopDokumen::NAMA_INSTANSI_BAWAAN`
 *   ({@see KopResmi::nama()}, rantai tunggal semua PDF);
 * - baris unit: nama **satker** bila unit ∈ {`$unitTtdSatkerTerpilih`, `$unitTtdSatkerKepegawaian`} (legacy id 6/7, F3),
 *   selain itu nama unit;
 * - alamat & logo kanan ikut cabang legacy [K] (`L_presensi.php:6512-6521` bawaan, `:7105-7116` pengisian):
 *   bawaan alamat `web_config` `pdf_header_alamat_kementerian` + logo kanan `logo_wonderful_pdf`; unit ∉ {6, 7} dengan
 *   `unit.alamat_pdf_header` terisi → alamat unit (logo tetap wonderful); selain itu unit = 6 dengan
 *   `satker.alamat_pdf_header` terisi → alamat satker + logo kanan `satker.logo_uns`; selain itu bawaan (termasuk unit 7
 *   ber-alamat unit, dan unit 6 tanpa alamat satker). "Terisi" = teks polos setelah `<br>`/tag dibuang tidak kosong;
 * - logo kiri `web_config` `logo_kementerian_pdf`; semua ASSET_PATH relatif dari `Config\Presensi::$akarAset`
 *   ({@see KopSlip::resolveAset}), PNG/JPEG disematkan {@see KopResmi}; `satker.logo_uns` tidak ada/tidak sah
 *   → jatuh ke logo wonderful [V2]; keduanya tidak ada → logo bawaan repo (CR-191), PDF tetap dibuat.
 *
 * **[CATAT] Kop PDF laporan Tukin per pegawai (keputusan Horii 28-10-2026)**
 *
 * | v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
 * |---|---|---|
 * | Kop unit berlogo tata letak `MY_PDF_HEADER_UNIT`; logo & teks dari `web_config`/master unit-satker v2 | `MY_PDF_HEADER_UNIT` (`config/constants.php:257`) diisi `L_presensi.php:7105-7118` (logo `web_config`, unit/satker 6/7 hard-code) | Sama tata letaknya. Usulan awal M5 (kop `KopDokumen` tanpa logo) **DITOLAK** Horii. Id unit 6/7 lewat konstanta F3 |
 * | Alamat & logo kanan ikut cabang legacy (keputusan Horii 29-10-2026, G1/G2 **ikut legacy**): unit 7 ber-alamat unit → alamat kementerian (G1); unit 6 tanpa alamat satker → alamat kementerian + logo wonderful (G2) | `L_presensi.php:6512-6521` (bawaan) + `:7105-7116` (cabang `!in_array(id_unit, ['6','7']) && alamat unit != ''` / `id_unit == '6' && alamat satker != ''`) | Tidak ada untuk cabangnya. [V2]: `satker.logo_uns` tidak sah/tidak ada → logo wonderful (legacy menyusun path rusak); "terisi" dinilai pada teks polos |
 */
final class KopLaporanTukin
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $wc nilai `WebConfigService::values()`
     */
    public static function html(BaseConnection $db, array $wc, Presensi $config, ?int $idUnit, ?int $idSatker): string
    {
        $unit   = $idUnit === null ? null : $db->table('unit')->select('unit, alamat_pdf_header')->where('id_unit', $idUnit)->get()->getRowArray();
        $satker = $idSatker === null ? null : $db->table('satker')->select('satker, alamat_pdf_header, logo_uns')->where('id_satker', $idSatker)->get()->getRowArray();

        $pakaiSatker = $idUnit !== null && in_array($idUnit, [$config->unitTtdSatkerTerpilih, $config->unitTtdSatkerKepegawaian], true);

        $nama = KopResmi::nama($wc);

        $namaUnit = (string) ($pakaiSatker ? ($satker['satker'] ?? '') : ($unit['unit'] ?? ''));

        // Cabang alamat & logo kanan [K] L_presensi.php:7105-7116 (bawaan :6512-6521).
        $alamatUnit   = self::teks($unit['alamat_pdf_header'] ?? null);
        $alamatSatker = self::teks($satker['alamat_pdf_header'] ?? null);
        $alamat       = self::teks($wc['pdf_header_alamat_kementerian'] ?? null);
        $kanan        = null;

        if (! $pakaiSatker && $alamatUnit !== '') {
            $alamat = $alamatUnit;
        } elseif ($idUnit !== null && $idUnit === $config->unitTtdSatkerTerpilih && $alamatSatker !== '') {
            $alamat = $alamatSatker;
            $kanan  = KopSlip::resolveAset($config->akarAset, $satker['logo_uns'] ?? null);
        }

        $kiri = KopSlip::resolveAset($config->akarAset, $wc['logo_kementerian_pdf'] ?? null);
        $kanan ??= KopSlip::resolveAset($config->akarAset, $wc['logo_wonderful_pdf'] ?? null);

        // `$alamat` sudah teks polos: di-escape lagi supaya tidak diurai ulang sebagai HTML oleh komponen kop.
        return KopResmi::html(esc($nama), esc($namaUnit), nl2br(esc($alamat)), $kiri, $kanan);
    }

    /**
     * Teks polos dari nilai `web_config`/master yang mungkin ber-HTML (`<br>` → baris baru, tag lain dibuang).
     */
    private static function teks(mixed $nilai): string
    {
        if (! is_string($nilai)) {
            return '';
        }

        $polos = strip_tags((string) preg_replace('/<br\s*\/?>/i', "\n", $nilai));

        return trim(html_entity_decode($polos, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
