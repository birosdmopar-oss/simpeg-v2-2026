<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi;

use RuntimeException;

/**
 * Akun `sistem.integrasi` sudah ada tetapi tidak sesuai bentuk akun sistem (DBV-027 Z-1). Pesan hanya memuat nama kolom,
 * tidak pernah nilainya; tidak diperbaiki otomatis — diperiksa manual (runbook cutover).
 */
final class AkunSistemMenyimpang extends RuntimeException
{
    /**
     * @param list<string> $kolom
     */
    public function __construct(public readonly array $kolom)
    {
        parent::__construct('Akun sistem integrasi sudah ada tetapi menyimpang pada kolom: ' . implode(', ', $kolom) . '. Periksa manual; tidak diperbaiki otomatis.');
    }
}
