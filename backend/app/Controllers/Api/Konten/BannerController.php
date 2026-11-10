<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Libraries\Dashboard\Konten\Gambar\JenisGambar;

/**
 * F-05 Banner dashboard (MAKE-080). Padanan legacy `hr/announcement/dashban*`.
 */
final class BannerController extends KontenGambarController
{
    protected function jenis(): JenisGambar
    {
        return JenisGambar::Banner;
    }
}
