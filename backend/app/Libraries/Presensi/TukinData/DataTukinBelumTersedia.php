<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Sumber data Tukin belum tersedia di paket ini (fail-closed). 5A: data cuti/tugas belajar baru tersedia di 5B
 * (PRD 5A §7.7.6) — Tukin tanpa cuti/TB tidak boleh dihitung.
 */
final class DataTukinBelumTersedia extends GalatDataTukin
{
}
