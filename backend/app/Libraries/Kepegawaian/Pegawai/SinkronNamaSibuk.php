<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use RuntimeException;

/**
 * MAKE-062 — run nyata `pengguna:sinkron-nama` lain sedang berjalan (kunci `GET_LOCK` dipegang). Pesan disusun aplikasi
 * tanpa NIP/nama.
 */
final class SinkronNamaSibuk extends RuntimeException
{
}
