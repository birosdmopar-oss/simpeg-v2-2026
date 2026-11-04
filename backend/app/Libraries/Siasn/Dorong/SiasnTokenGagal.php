<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use RuntimeException;

/**
 * Token/otorisasi BKN ditolak saat dorong/unggah (status `token` atau 401 sesudah token diperbarui) — batch DIHENTIKAN tanpa
 * menulis hasil baris (review B6 M2 P-3): baris sekali-dorong tidak boleh diberi flag gagal permanen, dan setiap baris
 * berikutnya akan memicu login SSO baru (akun SSO berisiko terkunci). Pesan tanpa nilai rahasia.
 */
final class SiasnTokenGagal extends RuntimeException
{
}
