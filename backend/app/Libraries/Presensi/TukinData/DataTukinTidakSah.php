<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Data sumber Tukin tidak dapat dipakai apa adanya: baris `bkn_periode_ekinper` ganda/tidak sah untuk satu bulan, atau
 * nilai `web_config` yang ditolak `KonfigurasiTukin` (mis. tarif dengan hasil faktor presensi tidak bulat).
 */
final class DataTukinTidakSah extends GalatDataTukin
{
}
