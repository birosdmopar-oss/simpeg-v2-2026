<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

/**
 * Hasil satu hari. Potongan dalam basis poin (sudah dikali faktor presensi 0,2). `kategori` berisi kode kejadian
 * hari itu (mis. `['TPM', 'PSW2']`); `rincian` membagi potongan ke kolom rekap legacy `TL`, `PSW`, `TA`, `TK`, `CUTI`
 * (`laporan_tukin_us_skp` :13380-13384). `tlMenit`/`pswMenit` adalah selisih bertanda seperti legacy (negatif =
 * datang lebih awal / pulang lebih lambat).
 */
final readonly class HasilHarian
{
    /**
     * @param list<string>       $kategori
     * @param array<string, int> $rincian
     */
    public function __construct(
        public string $status,
        public int $potongan = 0,
        public array $kategori = [],
        public int $tlMenit = 0,
        public int $pswMenit = 0,
        public ?string $jenisCuti = null,
        public array $rincian = [],
    ) {
    }
}
