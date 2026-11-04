<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use RuntimeException;

/**
 * Tarik SIASN jenis yang sama sedang berjalan di proses lain (kunci `GET_LOCK` per jenis, BP-6; review B6 M1 P-5).
 */
final class SiasnTarikSibuk extends RuntimeException
{
}
