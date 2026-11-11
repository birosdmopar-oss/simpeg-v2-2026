<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use RuntimeException;

/**
 * MAKE-089 — form/jawaban legacy tidak bisa dikonversi ke format v2. Ditangkap pra-pindai lalu menjadi baris ambigu
 * (pola keputusan F-02); pesannya hanya alasan umum, tanpa isi jawaban.
 */
final class KonversiGagal extends RuntimeException
{
}
