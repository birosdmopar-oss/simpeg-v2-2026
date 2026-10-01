<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

final readonly class HasilTukin
{
    /** @param array<string, int> $jumlah */
    public function __construct(public PeriodeTukin $periode, public int $hariKerja, public array $jumlah, public float $totalPotongan) {}
}
