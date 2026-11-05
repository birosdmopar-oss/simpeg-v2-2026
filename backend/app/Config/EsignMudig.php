<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * `services/s_esign` untuk MuDig (F6-16, MAKE-065 B4b): batas unggahan [V2] (legacy menerima path server, tanpa batas).
 * Bisa diubah lewat `.env` (`esignMudig.pdfMaks`, `esignMudig.gambarMaks`); naikkan juga `client_max_body_size` Nginx dan
 * `upload_max_filesize`/`post_max_size` PHP (README-deploy §1c). Lewat batas → `status` "2" "Invalid parameter.".
 */
class EsignMudig extends BaseConfig
{
    /** Ukuran maksimum `file` (PDF), byte. */
    public int $pdfMaks = 10 * 1024 * 1024;

    /** Ukuran maksimum `imageTTD` (JPEG/PNG), byte. */
    public int $gambarMaks = 2 * 1024 * 1024;
}
