<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use RuntimeException;

/**
 * Penanda jalur `kd_rpangkat_2` legacy yang tidak pernah selesai (loop tak berujung) atau berakhir TypeError di PHP 8 —
 * kartu Pangkat diperlakukan 0 (Q-D15 A, keputusan Horii 10-11-2026). Internal {@see KelengkapanData}.
 */
final class JalurLegacyBuntu extends RuntimeException
{
}
