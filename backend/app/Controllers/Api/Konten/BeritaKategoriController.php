<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Libraries\Dashboard\Konten\JenisMaster;

/**
 * Kategori berita (F-07, Q7-07): baca pilihan = semua role; kelola = role 1 (role 3 membaca yang `allow_ap`).
 */
final class BeritaKategoriController extends MasterKontenController
{
    protected function jenis(): JenisMaster
    {
        return JenisMaster::BeritaKategori;
    }
}
