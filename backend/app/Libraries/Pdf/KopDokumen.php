<?php

declare(strict_types=1);

namespace App\Libraries\Pdf;

use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Kop dokumen PDF dari `web_config` (PRD Fase 7 F7-15, §7.2.1; aditif — tiga generator Fase 3 DRH/LKH/tanda terima
 * tidak diubah). Pola sama dengan `DrhPegawai::kop()` [K `L_employee.php:7147-7166`]: baris 1 nama kementerian
 * (`pdf_header_nama_kementerian` ?? `nama_kementerian`, huruf besar), baris 2 nama unit/satker (unit UPT/lain →
 * satker), baris 3 alamat (`unit.alamat_pdf_header` / `satker.alamat_pdf_header` bila terisi, selain itu
 * `pdf_header_alamat_kementerian`), garis. Nama kosong → `NAMA_INSTANSI_BAWAAN` (CR-066). Tanpa lingkup (Baperjakat)
 * baris 2 dihilangkan.
 *
 * [CATAT] Kop seragam (keputusan Horii 24-11-2026, acuan gambar; CR-191): tata letak dan logo lewat {@see KopResmi}
 * (logo kiri/kanan `web_config` atau bawaan repo) — menggantikan keputusan F7-15 "tanpa logo". Sumber teks di atas
 * tidak berubah. Surat Cuti/TB/IB memakai {@see self::htmlSurat()}: baris 2 = `web_config` `pdf_header_nama_unit` (K2).
 */
final class KopDokumen
{
    /**
     * Nama instansi bila `web_config` kosong [K legacy `print/cetak.php:201`]. SATU sumber untuk kop dokumen daftar;
     * generator PDF lain yang butuh bawaan sama (mis. slip gaji `DataPdfSlip`) wajib merujuk konstanta ini.
     */
    public const NAMA_INSTANSI_BAWAAN = 'Kementerian Pariwisata';

    private function __construct()
    {
    }

    /**
     * @param int|null $idUnit   unit lingkup (null = kementerian)
     * @param int|null $idSatker satker lingkup (null = seluruh unit)
     */
    public static function html(?int $idUnit, ?int $idSatker, ?BaseConnection $db = null): string
    {
        return self::susun($idUnit, $idSatker, $db, false);
    }

    /**
     * Kop surat tingkat kementerian (Surat Cuti / Tugas Belajar / Izin Belajar): baris 2 = `web_config`
     * `pdf_header_nama_unit` (K2, CR-191); kosong → baris dihilangkan.
     */
    public static function htmlSurat(?BaseConnection $db = null): string
    {
        return self::susun(null, null, $db, true);
    }

    private static function susun(?int $idUnit, ?int $idSatker, ?BaseConnection $db, bool $unitWebConfig): string
    {
        $db ??= Database::connect();
        $wc     = self::webConfig();
        $alamat = (string) ($wc['pdf_header_alamat_kementerian'] ?? '');
        $baris2 = '';

        if ($idSatker !== null) {
            $sat = $db->table('satker')->select('satker, id_unit, alamat_pdf_header')->where('id_satker', $idSatker)->get()->getRowArray();

            if (is_array($sat)) {
                $idUnit ??= $sat['id_unit'] === null ? null : (int) $sat['id_unit'];
                $baris2 = (string) $sat['satker'];

                if (is_string($sat['alamat_pdf_header']) && trim($sat['alamat_pdf_header']) !== '') {
                    $alamat = $sat['alamat_pdf_header'];
                }
            }
        }

        if ($idUnit !== null && ($baris2 === '' || ! in_array($idUnit, [PegawaiScope::UNIT_UPT, PegawaiScope::UNIT_LAIN], true))) {
            $unit = $db->table('unit')->select('unit, alamat_pdf_header')->where('id_unit', $idUnit)->get()->getRowArray();

            if (is_array($unit)) {
                $baris2 = $baris2 === '' ? (string) $unit['unit'] : $baris2;

                if ($idSatker === null && is_string($unit['alamat_pdf_header']) && trim($unit['alamat_pdf_header']) !== '') {
                    $alamat = $unit['alamat_pdf_header'];
                }
            }
        }

        $nama = (string) ($wc['pdf_header_nama_kementerian'] ?? '');

        if (trim(strip_tags($nama)) === '') {
            $nama = (string) ($wc['nama_kementerian'] ?? '');
        }

        if (trim(strip_tags($nama)) === '') {
            $nama = self::NAMA_INSTANSI_BAWAAN;
        }

        // Nama unit/satker master = teks polos (di-escape supaya tidak diurai sebagai HTML); `web_config` = HTML tersanitasi.
        $baris2 = $unitWebConfig && $baris2 === '' ? (string) ($wc['pdf_header_nama_unit'] ?? '') : esc($baris2);

        return KopResmi::html($nama, $baris2, $alamat, ...KopResmi::logo($wc));
    }

    /**
     * Nilai `web_config` bertipe dan ter-cache; tabel belum ada / gagal → kosong (dokumen tetap terbit dengan kop minimal).
     *
     * @return array<string, mixed>
     */
    private static function webConfig(): array
    {
        try {
            return Services::webConfigService()->values();
        } catch (Throwable) {
            return [];
        }
    }
}
