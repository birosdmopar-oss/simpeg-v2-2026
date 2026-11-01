<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use RuntimeException;

/**
 * Impor dihentikan (gagal-cepat). Pesan hanya memuat nama tabel/kolom, kode aturan, id, dan jumlah — tidak pernah NIP,
 * nama orang, atau isi kolom teks.
 */
final class ImporGagal extends RuntimeException
{
}
