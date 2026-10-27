<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

/**
 * Daftar skema Fase 5A Presensi & Slip Gaji (DBV-023, disetujui DB Validator 20-10-2026) yang dipakai bersama test skema
 * (`SkemaPresensiGajiTest`, `MigrasiPresensiGajiSiklusTest`). Ditaruh di `tests/_support` (autoload) karena test di-shard:
 * kelas test lain belum tentu dimuat di shard yang sama (pelajaran DBV-022 M2).
 */
final class SkemaPresensiGaji
{
    /**
     * Versi migration DBV-023 pertama (`2026-10-27-100000_CreatePresensiGaji`); `2026-10-27-100100` = ENUM audit.
     */
    public const VERSI_PERTAMA = '2026-10-27-100000';

    public const VERSI_ENUM = '2026-10-27-100100';

    public const TABEL = ['dh_online', 'gaji_pegawai', 'gaji_pegawai_qr_history'];

    /** FK DBV-023: nama => [tabel, kolom, induk, kolom induk]. */
    public const FK = [
        'fk_gpqh_id_gaji_pegawai'      => ['gaji_pegawai_qr_history', 'id_gaji_pegawai', 'gaji_pegawai', 'id'],
        'fk_id_lokasi_masuk_dh_to_lp'  => ['dh_online', 'id_lokasi_masuk', 'lokasi_presensi', 'id_lokasi_presensi'],
        'fk_id_lokasi_pulang_dh_to_lp' => ['dh_online', 'id_lokasi_pulang', 'lokasi_presensi', 'id_lokasi_presensi'],
        'fk_nip_dh_to_pegawai'         => ['dh_online', 'nip', 'pegawai', 'nip'],
    ];

    /** Nama CHECK DBV-023 => tabel. */
    public const CHECK = [
        'chk_dh_online_jam'        => 'dh_online',
        'chk_dh_online_sumber'     => 'dh_online',
        'chk_gaji_pegawai_bulan'   => 'gaji_pegawai',
        'chk_gaji_pegawai_nominal' => 'gaji_pegawai',
        'chk_gaji_pegawai_tahun'   => 'gaji_pegawai',
    ];

    /** Nilai ENUM `audit_logs.event` setelah DBV-023 (5 nilai lama + `warning`, `import`). */
    public const ENUM_EVENT = ['create', 'update', 'delete', 'login', 'logout', 'warning', 'import'];
}
