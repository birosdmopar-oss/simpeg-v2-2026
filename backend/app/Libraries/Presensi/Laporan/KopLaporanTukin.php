<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Presensi\Slip\KopSlip;
use App\Libraries\Presensi\Slip\PdfSlipGaji;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;

/**
 * Kop PDF laporan Tukin per pegawai — **ikut legacy berlogo** (keputusan Horii 28-10-2026): port tata letak konstanta
 * `MY_PDF_HEADER_UNIT` legacy `0cf7e67` (`config/constants.php:257`) dan pengisiannya (`L_presensi.php:7105-7118`) [K]:
 * tabel tiga baris — logo kiri (`###LOGO_KEMENTERIAN###`, lebar 94) | nama kementerian huruf besar tebal 12pt | logo kanan
 * (`###LOGO_UNS###`); baris 2 nama unit huruf besar tebal 9pt; baris 3 alamat 9pt; dua garis (putih 7px, hitam 2px).
 *
 * Sumber v2 (tanpa path di-hard-code, pola CR-066 / slip gaji):
 * - nama: `web_config` `pdf_header_nama_kementerian` → `nama_kementerian` → {@see KopDokumen::NAMA_INSTANSI_BAWAAN};
 * - baris unit: nama **satker** bila unit ∈ {`$unitTtdSatkerTerpilih`, `$unitTtdSatkerKepegawaian`} (legacy id 6/7, F3),
 *   selain itu nama unit;
 * - alamat & logo kanan ikut cabang legacy [K] (`L_presensi.php:6512-6521` bawaan, `:7105-7116` pengisian):
 *   bawaan alamat `web_config` `pdf_header_alamat_kementerian` + logo kanan `logo_wonderful_pdf`; unit ∉ {6, 7} dengan
 *   `unit.alamat_pdf_header` terisi → alamat unit (logo tetap wonderful); selain itu unit = 6 dengan
 *   `satker.alamat_pdf_header` terisi → alamat satker + logo kanan `satker.logo_uns`; selain itu bawaan (termasuk unit 7
 *   ber-alamat unit, dan unit 6 tanpa alamat satker). "Terisi" = teks polos setelah `<br>`/tag dibuang tidak kosong;
 * - logo kiri `web_config` `logo_kementerian_pdf`; semua ASSET_PATH relatif dari `Config\Presensi::$akarAset`
 *   ({@see KopSlip::resolveAset}), PNG/JPEG disematkan ({@see PdfSlipGaji::logo()}); `satker.logo_uns` tidak ada/tidak sah
 *   → jatuh ke logo wonderful [V2]; keduanya tidak ada → sel kosong, PDF tetap dibuat.
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

        $nama = self::teks($wc['pdf_header_nama_kementerian'] ?? null);
        $nama = $nama !== '' ? $nama : self::teks($wc['nama_kementerian'] ?? null);
        $nama = $nama !== '' ? $nama : KopDokumen::NAMA_INSTANSI_BAWAAN;

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

        $logoKiri  = PdfSlipGaji::logo($kiri, 94);
        $logoKanan = PdfSlipGaji::logo($kanan, 94);
        $spasi     = '<div style="font-size: 7px; line-height: .5;">&nbsp;</div>';

        return '<table width="100%" cellpadding="0" border="0">'
            . '<tr><td width="14%" rowspan="3">' . $logoKiri . '</td>'
            . '<td width="72%" style="text-align: center; font-size: 12pt; line-height: 1;"><div style="font-size: 7px; line-height: 1;">&nbsp;</div><b>' . esc(mb_strtoupper($nama)) . '</b></td>'
            . '<td width="14%" rowspan="3" style="text-align: right;">' . $logoKanan . '</td></tr>'
            . '<tr><td style="text-align: center; font-size: 9pt; line-height: 1;">' . $spasi . '<b>' . esc(mb_strtoupper($namaUnit)) . '</b></td></tr>'
            . '<tr><td style="text-align: center; font-size: 9pt; line-height: 1;">' . $spasi . nl2br(esc($alamat)) . '</td></tr>'
            . '</table>'
            . '<hr style="height: 7px; border-color: #ffffff; color: #ffffff; background-color: #ffffff;" />'
            . '<hr style="height: 2px; border-color: #000000; color: #000000; background-color: #000000;" />';
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
