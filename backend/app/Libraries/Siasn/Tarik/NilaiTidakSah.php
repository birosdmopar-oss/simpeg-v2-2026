<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use RuntimeException;

/**
 * Nilai respons SIASN tidak sah untuk kolom tujuan; {@see self::$alasan} = `<kunci SIASN>_<tipe|panjang|tanggal|rentang>`
 * (tanpa nilai) untuk ringkasan `ditolak`.
 */
final class NilaiTidakSah extends RuntimeException
{
    public readonly string $alasan;

    public function __construct(string $kunci, string $jenis)
    {
        $this->alasan = $kunci . '_' . $jenis;

        parent::__construct("Nilai SIASN {$this->alasan}.");
    }
}
