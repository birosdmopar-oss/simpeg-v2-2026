<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Libraries\Dashboard\Konten\Gambar\JenisGambar;

/**
 * F-04 Popup pengumuman (MAKE-079). Padanan legacy `hr/announcement/popup*` (Announcement.php, L_announcement.php).
 */
final class PopupController extends KontenGambarController
{
    protected function jenis(): JenisGambar
    {
        return JenisGambar::Popup;
    }
}
