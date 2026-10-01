<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

/**
 * CR-025 — satu TMT acuan jarak KGB (dibangun pemanggil dari riwayat ber-DB, `status NOT IN (2, 10)`, tanpa baris yang
 * sedang diedit). TMT dari data sistem, jadi tanggal tidak valid (mis. `0000-00-00` sisa impor) langsung
 * `InvalidArgumentException` (K-CR025-4).
 */
final readonly class ReferensiTmt
{
    public string $tmt;

    public function __construct(public JenisReferensiTmt $jenis, string $tmt)
    {
        $this->tmt = TanggalBisnis::wajibValid($tmt, $jenis->label());
    }
}
