<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

final readonly class HasilHarian
{
    public function __construct(public string $status, public float $potongan, public ?string $kategori = null, public int $tlMenit = 0, public int $pswMenit = 0) {}
}
