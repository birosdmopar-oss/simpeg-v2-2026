<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konstanta angka kredit jabatan fungsional menurut PermenPAN-RB 1/2023 (MAKE-021, keputusan user 16-10-2026 Q3).
 *
 * Nilai bawaan = legacy PERSIS [K] `application/helpers/function_helper.php` getAKAccu() :2049-2110
 * (`$const_AKKP`, `$const_golVal`, `$const_maxGolJenj`). Dipakai `App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb`.
 * Diletakkan di config (bukan di kode) agar perubahan peraturan cukup mengubah berkas ini; tidak ada override `.env`
 * (tabel bertingkat, perubahan lewat rilis yang direview).
 */
class AngkaKredit extends BaseConfig
{
    /**
     * Angka kredit kumulatif yang dibutuhkan untuk kenaikan pangkat, per nama jenjang JF (`jenjang_jf.jenjang_jf`) ×
     * golongan ruang (`pangkat.gol_ruang`).
     *
     * @var array<string, array<string, float|int>>
     */
    public array $akUntukKp = [
        'Pemula'       => ['II/a' => 15],
        'Terampil'     => ['II/b' => 20, 'II/c' => 40, 'II/d' => 60],
        'Mahir'        => ['III/a' => 50, 'III/b' => 100],
        'Penyelia'     => ['III/c' => 100],
        'Ahli Pertama' => ['III/a' => 50, 'III/b' => 100],
        'Ahli Muda'    => ['III/c' => 100, 'III/d' => 200],
        'Ahli Madya'   => ['IV/a' => 150, 'IV/b' => 300, 'IV/c' => 450],
        'Ahli Utama'   => ['IV/d' => 200],
    ];

    /**
     * Nilai urut golongan ruang (untuk membandingkan dengan batas golongan jenjang).
     *
     * @var array<string, int>
     */
    public array $nilaiGolongan = [
        'II/a'  => 10, 'II/b' => 11, 'II/c' => 12, 'II/d' => 13,
        'III/a' => 14, 'III/b' => 15, 'III/c' => 16, 'III/d' => 17,
        'IV/a'  => 18, 'IV/b' => 19, 'IV/c' => 20, 'IV/d' => 21, 'IV/e' => 22,
    ];

    /**
     * Golongan tertinggi (nilai urut) per jenjang; pegawai dengan golongan di atas batas memakai AK terbesar jenjangnya.
     *
     * @var array<string, int>
     */
    public array $batasGolonganJenjang = [
        'Pemula'       => 10,
        'Terampil'     => 13,
        'Mahir'        => 15,
        'Penyelia'     => 17,
        'Ahli Pertama' => 15,
        'Ahli Muda'    => 17,
        'Ahli Madya'   => 20,
        'Ahli Utama'   => 22,
    ];
}
