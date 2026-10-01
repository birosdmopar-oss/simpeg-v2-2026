<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

/**
 * CR-027 — transisi status "sudah dibuka" slip gaji (S-1..S-5), bagian murni saja. `$dibukaPada` = nilai kolom
 * `viewed_at` (stempel waktu buka); `null` atau `''` = belum dibuka [K] (`empty()` di `controllers/hr/Sl_gaji.php:120-122`,
 * `:169`, `libraries/hr/Lsl_gaji.php:639`).
 *
 * Penegakan di server (D-11): transisi belum → dibuka hanya lewat aksi buka oleh pegawai pemilik slip, dengan UPDATE
 * bersyarat `viewed_at IS NULL` + cek jumlah baris (satu pemenang untuk permintaan bersamaan); melihat, mencetak,
 * mengoreksi, atau mengimpor oleh admin tidak mengubah status.
 */
final class StatusSlip
{
    private function __construct()
    {
    }

    /**
     * Pegawai boleh membuka slip yang belum dibuka; selain itu `slip_sudah_dibuka` (pesan legacy, S-2).
     */
    public static function periksaBuka(?string $dibukaPada): HasilSlip
    {
        if (self::sudahDibuka($dibukaPada)) {
            return HasilSlip::gagal(
                'slip_sudah_dibuka',
                'Slip gaji ini sudah pernah dibuka. Untuk membuka kembali, silakan hubungi Admin SIMPEG.',
            );
        }

        return HasilSlip::berhasil();
    }

    /**
     * Admin hanya bisa membuka ulang (reset) slip yang sudah dibuka; selain itu `slip_belum_dibuka` (S-5). Reset
     * mengosongkan status dan token aktif, sedangkan riwayat token tetap disimpan.
     */
    public static function periksaReset(?string $dibukaPada): HasilSlip
    {
        if (! self::sudahDibuka($dibukaPada)) {
            return HasilSlip::gagal(
                'slip_belum_dibuka',
                'Slip gaji ini belum pernah dibuka, tidak perlu direset.',
            );
        }

        return HasilSlip::berhasil();
    }

    /**
     * Pegawai boleh mencetak selama status dibuka (aturan server legacy `Sl_gaji.php:169-171`; usul S-4 TUNGGU-USER).
     */
    public static function bolehCetakPegawai(?string $dibukaPada): bool
    {
        return self::sudahDibuka($dibukaPada);
    }

    private static function sudahDibuka(?string $dibukaPada): bool
    {
        return $dibukaPada !== null && $dibukaPada !== '';
    }
}
