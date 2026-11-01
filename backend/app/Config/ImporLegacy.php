<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * MAKE-056 — alat impor resmi legacy → v2 (`php spark impor:legacy`, rincian di `app/Libraries/Impor/README.md`).
 */
class ImporLegacy extends BaseConfig
{
    /**
     * Nama database tujuan yang selalu ditolak (perbandingan tanpa beda huruf besar/kecil): DB dev bersama, DB test
     * bersama, dan nama skema legacy produksi. Penolakan TIDAK memakai port (DBA boleh menjalankan MySQL di 3306).
     *
     * @var list<string>
     */
    public array $tujuanTerlarang = ['simpeg_v2', 'simpeg_v2_testing', 'simpeg01'];

    /**
     * Jumlah baris per batch baca/tulis.
     */
    public int $ukuranBatch = 1000;
}
