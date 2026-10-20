<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Presensi\TukinData\SumberCutiTbBelumTersedia;
use App\Libraries\Presensi\TukinData\SumberCutiTbInterface;
use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Modul D Presensi & Remunerasi paket 5A (PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`).
 * Berkas milik 5A (PRD §3.1); milestone lain (impor Slip Gaji M3, akses slip M4, presensi M5) menambah propertinya secara
 * aditif.
 */
class Presensi extends BaseConfig
{
    /**
     * Sumber data cuti & tugas belajar harian untuk adapter Tukin (PRD 5A §7.7.6). Bawaan 5A = fail-closed
     * (`DataTukinBelumTersedia`); 5B mengganti nilai ini dengan implementasi berbasis data Fase 4. Nama kelas
     * divalidasi saat dipakai (`TukinDataService::sumberCutiTb()`: wajib mengimplementasikan {@see SumberCutiTbInterface}).
     */
    public string $sumberCutiTb = SumberCutiTbBelumTersedia::class;
}
