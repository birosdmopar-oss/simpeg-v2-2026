<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use RuntimeException;

/**
 * Data tarif uang makan tidak sah (PRD 5B §7.2.3): mis. `gol_pppk.uang_makan` tidak bulat sampai sen. Padanan
 * `DataTukinTidakSah` di namespace Slip; pesan tanpa nominal (aman dicatat log).
 */
final class DataUangMakanTidakSah extends RuntimeException
{
}
