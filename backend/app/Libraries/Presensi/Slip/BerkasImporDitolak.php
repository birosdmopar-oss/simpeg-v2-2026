<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use RuntimeException;

/**
 * Berkas impor Slip Gaji ditolak saat dibaca (rusak, bukan Excel, baris melebihi batas). Pesan siap tampil sebagai
 * 422 `errors.berkas` (PRD 5A §7.2.3 butir 1); tidak pernah memuat pesan exception mentah atau isi sel.
 */
final class BerkasImporDitolak extends RuntimeException
{
}
