<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\MasterData\HariLiburService;

/**
 * Hari libur untuk Tukin (PRD 5A §7.7.4): `hari_libur` aktif lewat `HariLiburService::tanggalLibur` (Fase 2, dibaca
 * saja). Tanggal DATE tanpa konversi zona; akhir pekan dieliminasi library `HariKerja`.
 */
final class SumberHariLibur
{
    public function __construct(private readonly HariLiburService $hariLibur)
    {
    }

    /**
     * @return list<string>
     */
    public function untuk(string $awal, string $akhir): array
    {
        return $this->hariLibur->tanggalLibur($awal, $akhir);
    }
}
