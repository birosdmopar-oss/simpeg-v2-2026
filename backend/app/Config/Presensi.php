<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Modul D Presensi & Remunerasi paket 5A (PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`).
 * Berkas milik 5A (PRD §3.1); milestone lain (adapter Tukin M6, akses slip M4, presensi M5) menambah propertinya secara
 * aditif.
 *
 * Override `.env` (opsional) untuk batas impor: `presensi.maksUkuranImporByte`, `presensi.maksBarisImporPerSheet`,
 * `presensi.ttlPratinjauDetik` — hanya bilangan bulat positif yang sah; nilai lain diabaikan dan bawaan dipakai (pola
 * `Config\Konket::daftarDariEnv`).
 */
class Presensi extends BaseConfig
{
    /** Batas ukuran berkas impor Slip Gaji (PRD 5A §7.2.1, Q5A-05 default). */
    public int $maksUkuranImporByte = 5 * 1024 * 1024;

    /** Batas baris data per sheet impor (Q5A-05 default). */
    public int $maksBarisImporPerSheet = 10000;

    /** Masa berlaku pratinjau impor dalam detik (Q5A-05 default 30 menit). */
    public int $ttlPratinjauDetik = 1800;

    /** Direktori berkas pratinjau impor (di bawah `writable/`, tidak publik). */
    public string $direktoriPratinjau = WRITEPATH . 'uploads/slip-impor/';

    public function __construct()
    {
        parent::__construct();

        $this->maksUkuranImporByte    = self::angkaPositifDariEnv('presensi.maksUkuranImporByte', $this->maksUkuranImporByte);
        $this->maksBarisImporPerSheet = self::angkaPositifDariEnv('presensi.maksBarisImporPerSheet', $this->maksBarisImporPerSheet);
        $this->ttlPratinjauDetik      = self::angkaPositifDariEnv('presensi.ttlPratinjauDetik', $this->ttlPratinjauDetik);
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
}
