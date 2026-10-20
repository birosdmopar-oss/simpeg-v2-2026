<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Sumber data cuti & tugas belajar harian untuk Tukin (PRD 5A §7.7.6). Implementasi 5A
 * ({@see SumberCutiTbBelumTersedia}) fail-closed; 5B menggantinya lewat `Config\Presensi::$sumberCutiTb` dengan data
 * Fase 4 (`riwayat_cuti.status = 1`, `id_jenis_cuti` 1..6, `bebasPresensiPenuh`, `rentangBebasPresensi`).
 */
interface SumberCutiTbInterface
{
    /**
     * @return array<string, array{tugas_belajar?: mixed, cuti?: array<string, mixed>}> per tanggal YYYY-MM-DD
     */
    public function untuk(string $nip, string $awal, string $akhir): array;
}
