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

    /** §7.3.1: periode tersedia tetapi slip pegawai tidak ada (404). */
    public const SLIP_TIDAK_DITEMUKAN = 'slip_tidak_ditemukan';

    /** §7.3.3: slip sudah dibuka (422; kode dari `StatusSlip::periksaBuka`). */
    public const SLIP_SUDAH_DIBUKA = 'slip_sudah_dibuka';

    /** §6.1/§7.3.5: cetak pegawai atau buka ulang slip yang belum dibuka (422). */
    public const SLIP_BELUM_DIBUKA = 'slip_belum_dibuka';

    /** §7.3.4: nominal koreksi admin negatif / format salah (422 per field). */
    public const NOMINAL_NEGATIF = 'nominal_negatif';

    public const NOMINAL_TIDAK_VALID = 'nominal_tidak_valid';

    private function __construct()
    {
    }
}
