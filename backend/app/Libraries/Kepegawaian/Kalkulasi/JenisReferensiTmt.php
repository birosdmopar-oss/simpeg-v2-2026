<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

/**
 * CR-025 (K-CR025-7) — jenis TMT acuan jarak KGB. Urutan case = prioritas bila dua acuan ber-TMT sama (KGB > KP > CPNS >
 * awal PPPK). Acuan legacy `next_kgb` (`libraries/hr/L_employee.php:3984-4020`): TMT KGB terakhir, diganti TMT CPNS (PNS)
 * atau TMT jabatan (PPPK, `id_jenis_pegawai = 6`) bila lebih baru; TMT KP [V2] berasal dari SRS-FR-026. Isi daftar acuan
 * ditentukan pemanggil (B-09).
 */
enum JenisReferensiTmt: string
{
    case Kgb      = 'kgb';
    case Kp       = 'kp';
    case Cpns     = 'cpns';
    case AwalPppk = 'awal_pppk';

    /**
     * Label di pesan pengguna ("… minimal 2 tahun dari TMT KP terakhir (1 April 2025) …").
     */
    public function label(): string
    {
        return match ($this) {
            self::Kgb      => 'TMT KGB terakhir',
            self::Kp       => 'TMT KP terakhir',
            self::Cpns     => 'TMT CPNS',
            self::AwalPppk => 'TMT awal PPPK',
        };
    }

    /**
     * Prioritas bila TMT sama; angka kecil menang (= urutan deklarasi case).
     */
    public function prioritas(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
