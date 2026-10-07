<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Modul B — Kepegawaian Core (Fase 3, S0-A MAKE-002).
 */
class Kepegawaian extends BaseConfig
{
    /**
     * Status riwayat 3 "Diproses" (keputusan #8 Sprint 0): nilai ada di StatusRiwayat, tetapi hanya berlaku bila flag
     * ini aktif. Nonaktif secara default — alur riwayat memakai 0/1/2/10 seperti legacy.
     */
    public bool $statusDiprosesAktif = false;

    /**
     * Folder Definisi riwayat yang dipindai RiwayatRegistry (auto-discovery: satu berkas per jenis, tanpa daftar
     * terpusat). Namespace harus cocok dengan folder (PSR-4).
     */
    public string $definisiRiwayatPath = APPPATH . 'Libraries/Kepegawaian/Riwayat/Definisi';

    public string $definisiRiwayatNamespace = 'App\Libraries\Kepegawaian\Riwayat\Definisi';
}
