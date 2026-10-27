<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use RuntimeException;

/**
 * Galat terkendali adapter Tukin (PRD 5A §7.7.1): pengganti `InvalidArgumentException` mentah dari library murni
 * `Presensi/Tukin`. Pesan siap tampil Bahasa Indonesia; pemanggil (5B: laporan Tukin) memetakannya ke respons.
 */
abstract class GalatDataTukin extends RuntimeException
{
}
