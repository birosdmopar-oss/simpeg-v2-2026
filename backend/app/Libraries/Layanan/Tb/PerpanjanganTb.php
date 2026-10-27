<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Layanan\Studi\RentangTanggal;
use App\Libraries\Layanan\Studi\StatusStudi;

/**
 * MAKE-024 (bagian murni) — perpanjangan TB (`riwayat_tb_perpan`, PRD Fase 4 v1.1 §6.7.4, D15) [K `L_tb.php:1297-1537`].
 * Perpanjangan **tanpa status/approval**, langsung berlaku, hanya role 1 (Q-12 [K `Tb.php:614-674`]); boleh berganda.
 *
 * - Validasi: `tgl_mulai_perpan`, `tgl_akhir_perpan` wajib & sah [K `L_tb.php:1349-1361`]; `akhir ≥ mulai` dan induk
 *   berstatus 1 [V2 TB-13, Q-15 — legacy tidak mengecek keduanya]. Berkas `file_sperpan` diperiksa pemanggil.
 * - Saran `tgl_mulai_perpan` = tanggal akhir efektif induk + 1 hari [K `L_tb.php:1300-1303`, tidak ditegakkan].
 * - Ringkasan induk = `MAX(tgl_mulai_perpan)`, `MAX(tgl_akhir_perpan)` dari semua perpanjangan, masing-masing terpisah
 *   (bisa dari baris berbeda, persis trigger `riwayat_tb_perpan_af*` DDL :10487-10527); tak ada perpanjangan → NULL.
 *   `riwayat_tb.tgl_akhir` tidak pernah diubah.
 * - Tanggal akhir efektif (tampilan/saran) = MAX(`tgl_akhir`, `tgl_akhir_perpan`).
 */
final class PerpanjanganTb
{
    public const PESAN_MULAI_WAJIB = 'Tanggal mulai perpanjangan TB wajib diisi.';

    public const PESAN_AKHIR_WAJIB = 'Tanggal akhir perpanjangan TB wajib diisi.';

    public const PESAN_MULAI_TIDAK_VALID = 'Tanggal mulai perpanjangan TB tidak valid.';

    public const PESAN_AKHIR_TIDAK_VALID = 'Tanggal akhir perpanjangan TB tidak valid.';

    public const PESAN_AKHIR_SEBELUM_MULAI = 'Tanggal akhir perpanjangan tidak boleh sebelum tanggal mulai perpanjangan.';

    public const PESAN_INDUK_BELUM_DISETUJUI = 'Perpanjangan hanya untuk tugas belajar yang sudah disetujui.';

    private function __construct()
    {
    }

    /**
     * @return array<string, string> `errors.<field>` → pesan; kosong = sah
     */
    public static function periksa(?string $tglMulaiPerpan, ?string $tglAkhirPerpan, int $statusInduk): array
    {
        $errors = [];
        $mulai  = self::tanggal($tglMulaiPerpan, 'tgl_mulai_perpan', self::PESAN_MULAI_WAJIB, self::PESAN_MULAI_TIDAK_VALID, $errors);
        $akhir  = self::tanggal($tglAkhirPerpan, 'tgl_akhir_perpan', self::PESAN_AKHIR_WAJIB, self::PESAN_AKHIR_TIDAK_VALID, $errors);

        if ($mulai !== null && $akhir !== null && $akhir < $mulai) {
            $errors['tgl_akhir_perpan'] = self::PESAN_AKHIR_SEBELUM_MULAI;
        }

        if ($statusInduk !== StatusStudi::DISETUJUI) {
            $errors['status'] = self::PESAN_INDUK_BELUM_DISETUJUI;
        }

        return $errors;
    }

    /**
     * Tanggal akhir efektif induk = MAX(`tgl_akhir`, `tgl_akhir_perpan`).
     */
    public static function tglAkhirEfektif(string $tglAkhir, ?string $tglAkhirPerpan): string
    {
        $perpan = trim((string) $tglAkhirPerpan);

        return $perpan !== '' && $perpan > $tglAkhir ? $perpan : $tglAkhir;
    }

    /**
     * Saran `tgl_mulai_perpan` = (`tgl_akhir_perpan` induk bila ada, selain itu `tgl_akhir`) + 1 hari [K].
     */
    public static function saranMulai(string $tglAkhir, ?string $tglAkhirPerpan): string
    {
        $perpan = trim((string) $tglAkhirPerpan);

        return RentangTanggal::tambahHari($perpan !== '' ? $perpan : $tglAkhir, 1);
    }

    /**
     * Ringkasan kolom induk dari semua perpanjangan (pengganti trigger).
     *
     * @param list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}> $perpanjangan
     *
     * @return array{tgl_mulai_perpan: string|null, tgl_akhir_perpan: string|null}
     */
    public static function ringkasan(array $perpanjangan): array
    {
        $mulai = null;
        $akhir = null;

        foreach ($perpanjangan as $p) {
            $mulai = $mulai === null || $p['tgl_mulai_perpan'] > $mulai ? $p['tgl_mulai_perpan'] : $mulai;
            $akhir = $akhir === null || $p['tgl_akhir_perpan'] > $akhir ? $p['tgl_akhir_perpan'] : $akhir;
        }

        return ['tgl_mulai_perpan' => $mulai, 'tgl_akhir_perpan' => $akhir];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function tanggal(?string $nilai, string $field, string $pesanWajib, string $pesanTidakValid, array &$errors): ?string
    {
        $nilai = trim((string) $nilai);

        if ($nilai === '') {
            $errors[$field] = $pesanWajib;

            return null;
        }

        if (! TanggalBisnis::valid($nilai)) {
            $errors[$field] = $pesanTidakValid;

            return null;
        }

        return $nilai;
    }
}
