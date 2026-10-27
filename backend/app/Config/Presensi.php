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
    // --- M5 presensi online & manual (MAKE-032) ---

    /** Jendela presensi masuk, jam lokal satker, inklusif [K PO-4] (PRD 5A §7.5.5; tanpa override `.env`). */
    public string $jamMasukMulai = '04:00:00';

    public string $jamMasukSelesai = '12:30:59';

    /** Jendela presensi pulang, jam lokal satker, inklusif [K PO-4]. */
    public string $jamPulangMulai = '12:31:00';

    public string $jamPulangSelesai = '23:59:59';

    /**
     * `pengguna.id_pengguna` akun khusus presensi manual [K `config/constants.php:324-325`, K5-11] (PRD 5A §7.6.1).
     * Override `.env` `presensi.akunPresensiManual = 4675,4676`: daftar bilangan bulat positif dipisah koma; kosong / ada
     * token tidak sah → seluruhnya diabaikan dan bawaan dipakai (pola `Config\Konket::daftarDariEnv`).
     *
     * @var list<int>
     */
    public array $akunPresensiManual = [4675, 4676];

    public function __construct()
    {
        parent::__construct();

        $this->akunPresensiManual = Konket::daftarDariEnv('presensi.akunPresensiManual', [4675, 4676]);
    }
}
