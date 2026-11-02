<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi;

use RuntimeException;

/**
 * Driver integrasi real dibuat dengan konfigurasi kosong (AC-K.4). Pesan memuat nama kunci `.env`, bukan nilai.
 */
final class KonfigurasiIntegrasiTidakLengkap extends RuntimeException
{
    /**
     * @param list<string> $kunci
     */
    public function __construct(public readonly string $layanan, public readonly array $kunci)
    {
        $env = array_map(static fn (string $k): string => "integrations.{$layanan}.{$k}", $kunci);

        parent::__construct("Konfigurasi integrasi '{$layanan}' belum lengkap: " . implode(', ', $env) . ' kosong (isi di .env server).');
    }
}
