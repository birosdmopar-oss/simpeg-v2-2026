<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Lebih dari satu rentang `rentang_puasa` beririsan dengan satu periode Tukin (PRD 5A §7.7.3, AC-TK.2):
 * `KonfigurasiTukin` hanya menerima satu rentang puasa.
 */
final class RentangPuasaGanda extends GalatDataTukin
{
    public function __construct(string $awal, string $akhir)
    {
        parent::__construct("Lebih dari satu rentang puasa di Web Config beririsan dengan periode Tukin {$awal} s.d. {$akhir}.");
    }
}
