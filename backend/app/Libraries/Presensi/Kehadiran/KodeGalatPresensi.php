<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

/**
 * Kode galat mesin (field `code` envelope, `ApiException` parameter `kode`) presensi Fase 5A (PRD 5A §7.5, §7.6).
 */
final class KodeGalatPresensi
{
    /** §7.5.5: jam lokal di luar jendela masuk/pulang (422 `errors.jam`). */
    public const DI_LUAR_JENDELA = 'di_luar_jendela';

    /** §7.5.6: masuk kedua pada tanggal yang sama (first-wins, 422 `errors.jam`). */
    public const SUDAH_PRESENSI_MASUK = 'sudah_presensi_masuk';

    /** §7.5.4: tercakup aturan lokasi tetapi di luar radius semua lokasi berlaku (422 `errors.lokasi`). */
    public const DI_LUAR_RADIUS = 'di_luar_radius';

    /** §7.5.3: tercakup aturan tetapi semua lokasi berlaku berkoordinat tidak sah (422 `errors.lokasi`, fail-closed). */
    public const LOKASI_TIDAK_VALID = 'lokasi_tidak_valid';

    /** §7.5.4: tercakup aturan tetapi koordinat tidak dikirim (422 `errors.latitude`). */
    public const KOORDINAT_WAJIB = 'koordinat_wajib';

    /** §7.5.4: koordinat dikirim tetapi bukan angka sah / di luar rentang (422 `errors.latitude`). */
    public const KOORDINAT_TIDAK_SAH = 'koordinat_tidak_valid';

    /** §7.6.1: akun role 1 di luar `Config\Presensi::$akunPresensiManual` (403). */
    public const BUKAN_AKUN_PRESENSI_MANUAL = 'bukan_akun_presensi_manual';

    private function __construct()
    {
    }
}
