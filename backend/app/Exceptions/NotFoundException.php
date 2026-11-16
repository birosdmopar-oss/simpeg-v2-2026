<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 404 — resource tidak ditemukan. `$kode` opsional = kode mesin `code` di envelope (aditif, CR-127).
 */
class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Data tidak ditemukan.', ?string $kode = null)
    {
        parent::__construct($message, 404, null, $kode);
    }
}
