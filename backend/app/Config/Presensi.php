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
    // --- M3 impor Slip Gaji (MAKE-030) ---
    // Override `.env` (opsional): `presensi.maksUkuranImporByte`, `presensi.maksBarisImporPerSheet`,
    // `presensi.ttlPratinjauDetik` — hanya bilangan bulat positif yang sah; nilai lain diabaikan dan bawaan dipakai
    // (pola `Config\Konket::daftarDariEnv`).

    /** Batas ukuran berkas impor Slip Gaji (PRD 5A §7.2.1, Q5A-05 default). */
    public int $maksUkuranImporByte = 5 * 1024 * 1024;

    /** Batas baris data per sheet impor (Q5A-05 default). */
    public int $maksBarisImporPerSheet = 10000;

    /** Masa berlaku pratinjau impor dalam detik (Q5A-05 default 30 menit). */
    public int $ttlPratinjauDetik = 1800;

    /** Direktori berkas pratinjau impor (di bawah `writable/`, tidak publik). */
    public string $direktoriPratinjau = WRITEPATH . 'uploads/slip-impor/';

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

        $this->maksUkuranImporByte    = self::angkaPositifDariEnv('presensi.maksUkuranImporByte', $this->maksUkuranImporByte);
        $this->maksBarisImporPerSheet = self::angkaPositifDariEnv('presensi.maksBarisImporPerSheet', $this->maksBarisImporPerSheet);
        $this->ttlPratinjauDetik      = self::angkaPositifDariEnv('presensi.ttlPratinjauDetik', $this->ttlPratinjauDetik);

        $this->akarAset = self::direktoriAbsolut($this->akarAset) ?? WRITEPATH . 'aset/';
    }

    /**
     * Bilangan bulat positif dari variabel lingkungan `$kunci`; tidak ada / kosong / tidak sah → `$bawaan`.
     */
    public static function angkaPositifDariEnv(string $kunci, int $bawaan): int
    {
        $nilai = env($kunci);

        return self::angkaPositif($nilai) ?? $bawaan;
    }

    public static function angkaPositif(mixed $nilai): ?int
    {
        if (is_int($nilai)) {
            return $nilai > 0 ? $nilai : null;
        }

        if (! is_string($nilai) || preg_match('/^[1-9][0-9]{0,9}\z/', trim($nilai)) !== 1) {
            return null;
        }

        $angka = (int) trim($nilai);

        return $angka <= 2147483647 ? $angka : null;
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
