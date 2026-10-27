<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Implementasi 5A: selalu melempar {@see DataTukinBelumTersedia} — 5A DILARANG membaca `riwayat_cuti`/`riwayat_tb`
 * (PRD 5A §1.2). Akibatnya `TukinDataService::siapkan()` gagal terkendali sampai 5B mengganti sumbernya.
 */
final class SumberCutiTbBelumTersedia implements SumberCutiTbInterface
{
    public function untuk(string $nip, string $awal, string $akhir): array
    {
        throw new DataTukinBelumTersedia('Data cuti/tugas belajar tersedia di Fase 5B.');
    }
}
