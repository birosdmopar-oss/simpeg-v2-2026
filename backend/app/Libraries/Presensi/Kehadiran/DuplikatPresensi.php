<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

use RuntimeException;

/**
 * 1062 PK `(nip, tgl)` `dh_online` saat menyisip (permintaan bersamaan) — ditangkap service lalu diulang/dipetakan sesuai
 * aturan task (presensi online: baca ulang; manual: 422), tidak pernah 500 (PRD 5A §8.2).
 */
final class DuplikatPresensi extends RuntimeException
{
}
