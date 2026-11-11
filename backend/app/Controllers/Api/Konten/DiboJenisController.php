<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Libraries\Dashboard\Konten\JenisMaster;

/**
 * Master jenis DIBO (F-12, Q7-11): baca pilihan = semua role; kelola = role 1.
 */
final class DiboJenisController extends MasterKontenController
{
    protected function jenis(): JenisMaster
    {
        return JenisMaster::DiboJenis;
    }
}
