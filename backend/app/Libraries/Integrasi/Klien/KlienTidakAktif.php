<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use RuntimeException;

/**
 * MAKE-058 — token SIMAPI diminta untuk klien yang tidak ada atau tidak berstatus 1 (mis. dinonaktifkan tepat saat handshake
 * berlangsung). Pesan disusun aplikasi tanpa data klien.
 */
final class KlienTidakAktif extends RuntimeException
{
}
