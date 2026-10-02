<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

/**
 * Hasil satu hari. Potongan dalam basis poin. `kategori` berisi kode kolom laporan legacy yang terkena hari itu
 * (mis. `['TPM', 'PSW2']`); `tlMenit`/`pswMenit` adalah selisih bertanda seperti legacy (negatif = datang lebih awal /
 * pulang lebih lambat).
 */
final readonly class HasilHarian
{
    /**
     * @param list<string> $kategori
     */
    public function __construct(
        public string $status,
        public int $potongan = 0,
        public array $kategori = [],
        public int $tlMenit = 0,
        public int $pswMenit = 0,
        public int $potonganLkh = 0,
        public ?string $jenisCuti = null,
    ) {
    }
}
