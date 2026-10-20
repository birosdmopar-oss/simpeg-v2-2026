<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Modul D Presensi & Remunerasi paket 5A (PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`).
 * Berkas milik 5A (PRD §3.1); tiap milestone menambah propertinya secara aditif (satu blok per milestone).
 */
class Presensi extends BaseConfig
{
    // --- M4 akses slip (MAKE-031) ---

    /**
     * Akar penyimpanan aset v2 untuk path relatif `web_config` tipe ASSET_PATH (logo kop PDF slip,
     * `App\Libraries\Presensi\Slip\KopSlip`). Override `.env` `presensi.akarAset`: hanya path direktori absolut tanpa
     * segmen `..`; nilai lain diabaikan dan bawaan dipakai. Akar final ditetapkan saat cutover bersama G-09.
     */
    public string $akarAset = WRITEPATH . 'aset/';

    public function __construct()
    {
        parent::__construct();

        $this->akarAset = self::direktoriAbsolut($this->akarAset) ?? WRITEPATH . 'aset/';
    }

    /**
     * Path direktori absolut yang sah (diakhiri `/`), atau null bila kosong / relatif / memuat `..` / karakter kendali.
     */
    public static function direktoriAbsolut(mixed $nilai): ?string
    {
        if (! is_string($nilai)) {
            return null;
        }

        $nilai = trim($nilai);

        if ($nilai === '' || preg_match('/[\x00-\x1F]/', $nilai) === 1
            || preg_match('#^(/|[A-Za-z]:[\\\/])#', $nilai) !== 1
            || preg_match('#(^|[\\\/])\.\.([\\\/]|$)#', $nilai) === 1) {
            return null;
        }

        return rtrim($nilai, '\\/') . '/';
    }
}
