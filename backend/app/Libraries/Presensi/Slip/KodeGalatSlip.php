<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

/**
 * Kode galat mesin (field `code` envelope, `ApiException` parameter `kode`) Slip Gaji Fase 5A (PRD 5A §6, §7.2, §7.3).
 * Kode galat validasi logika murni (`HasilSlip::$kode`, mis. `filter_periode_tidak_lengkap`) diteruskan apa adanya.
 */
final class KodeGalatSlip
{
    /** §7.2.4 butir 2: token pratinjau tidak ada / kedaluwarsa / bukan milik pemanggil / berkas berubah (404). */
    public const PRATINJAU_TIDAK_DITEMUKAN = 'pratinjau_tidak_ditemukan';

    /** §7.2.4 butir 5: 1062 saat commit impor (409, seluruh transaksi di-rollback). */
    public const KONFLIK_DATA = 'konflik_data';

    private function __construct()
    {
    }
}
