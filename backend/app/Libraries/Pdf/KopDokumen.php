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
 * baris 2 dihilangkan. Tanpa logo (keputusan F7-15, konsisten DRH/LKH).
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

        return '<table width="100%" cellpadding="0"><tr><td style="text-align:center;font-size:12pt;"><b>'
            . esc(mb_strtoupper(trim(strip_tags($nama)))) . '</b></td></tr>'
            . ($baris2 !== '' ? '<tr><td style="text-align:center;font-size:9pt;"><b>' . esc(mb_strtoupper($baris2)) . '</b></td></tr>' : '')
            . '<tr><td style="text-align:center;font-size:8pt;">' . nl2br(esc(trim(strip_tags($alamat)))) . '</td></tr></table>'
            . '<hr style="height:2px;" />';
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
