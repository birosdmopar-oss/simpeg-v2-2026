<?php

declare(strict_types=1);

namespace App\Interfaces\Layanan;

/**
 * Titik panggil penerbitan surat cuti belum-TTE saat efek final (PRD Fase 4 §6.4.5 #3). Diisi M4 (MAKE-023,
 * `SuratCutiService`); di M3 terikat ke {@see \App\Libraries\Layanan\Cuti\PenerbitSuratCutiNoOp}. Dipanggil di dalam
 * transaksi persetujuan, tepat sekali per transisi final ke 1 atau 4.
 */
interface PenerbitSuratCutiInterface
{
    /**
     * @param array<string, mixed> $cuti baris `riwayat_cuti` setelah status final ditulis
     */
    public function terbitkanBelumTte(array $cuti): void;
}
