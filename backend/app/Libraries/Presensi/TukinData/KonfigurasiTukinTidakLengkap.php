<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Tarif potongan wajib di `web_config` belum diatur (nilai null). Tidak pernah diganti 0 atau nilai karangan
 * (PRD 5A §7.7.3, AC-TK.3).
 */
final class KonfigurasiTukinTidakLengkap extends GalatDataTukin
{
    /**
     * @param list<string> $kunci key web_config yang belum diatur
     */
    public function __construct(public readonly array $kunci)
    {
        parent::__construct('Tarif potongan Tukin belum diatur di Web Config: ' . implode(', ', $kunci) . '.');
    }
}
