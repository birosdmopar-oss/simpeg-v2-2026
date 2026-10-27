<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;

/**
 * MAKE-024 (bagian murni) — state machine satu langkah TB (PRD Fase 4 v1.1 §6.7.3, D14) dan IB (§6.8.2), tanpa cek PIC
 * (service M5 memeriksa `petugas_layanan`) dan tanpa notifikasi/efek DB. Pola galat `TransisiCuti`.
 *
 * - 0 → PIC setujui → 1 (final): data dasar surat & SK wajib (TB `ds_tb`, `no_ds_tb`, `no_sk`, `tgl_sk`; IB `ds_ib`,
 *   `no_ds_ib`, `no_sk`, `tgl_sk`) [K `L_tb.php:1245-1262`, `L_ib.php:871-960`]; `reason_note` = NULL, `show_pl` = 1.
 * - 0 → PIC tolak → 2 (final): `reason_note` wajib [V2: legacy hanya wajib di klien] dan ≤ 255 byte [V2 Q-23].
 * - Status lain (1, 2 final; 3 data impor) → `errors.status` (single end node; menutup "process status 0/3/4" legacy).
 * - Aksi selain setujui/tolak → `errors.aksi` [K pesan `L_tb.php:1243`; legacy menerima status apa pun].
 * Efek final (TB: `id_jenis_status`, surat; IB: surat otomatis) dikerjakan service M5 bila `efek_final`.
 */
final class TransisiStudi
{
    public const AKSI_SETUJUI = 'setujui';

    public const AKSI_TOLAK = 'tolak';

    public const AKSI = [self::AKSI_SETUJUI, self::AKSI_TOLAK];

    /** Data dasar surat & SK wajib saat setujui IB [K `L_ib.php`]. */
    public const SK_IB = [
        'no_sk'    => 'No. SK Izin Belajar',
        'tgl_sk'   => 'Tgl. SK Izin Belajar',
        'ds_ib'    => 'Dasar Surat Izin Belajar',
        'no_ds_ib' => 'No. Dasar Surat Izin Belajar',
    ];

    /** [K `L_pmk.php:1766`] — teks yang sama dipakai TB/IB (legacy TB/IB tidak punya teks server). */
    public const PESAN_REASON_WAJIB = 'Alasan ditolak tidak boleh kosong.';

    public const PESAN_REASON_PANJANG = 'Alasan maksimal 255 karakter.';

    private function __construct()
    {
    }

    /**
     * Pesan aksi tidak dikenal [K `L_tb.php:1243`].
     */
    public static function pesanAksi(string $jenis): string
    {
        return 'Silahkan setujui atau tolak permohonan data ' . AturanStudi::namaLayanan($jenis) . ' pegawai.';
    }

    public static function pesanStatus(string $jenis): string
    {
        return 'Permohonan ' . AturanStudi::namaLayanan($jenis) . ' ini tidak dalam status yang dapat diproses.';
    }

    /**
     * Data SK yang wajib saat setujui per jenis.
     *
     * @return array<string, string>
     */
    public static function fieldSk(string $jenis): array
    {
        AturanStudi::namaLayanan($jenis);

        return $jenis === AturanStudi::JENIS_TB ? ValidasiStudi::SK_TB : self::SK_IB;
    }

    /**
     * Proses satu keputusan PIC. Berhasil → `detail`: `status`, `final`, `efek_final`, `show_pl` (1 saat setujui, `null` =
     * tidak ditulis), `reason_note`. Gagal → kode field (`aksi`, `status`, `reason_note`, atau field SK pertama) dengan
     * `detail.errors` berisi seluruh galat field SK.
     *
     * @param array<string, mixed> $data data SK dari form proses
     */
    public static function proses(string $jenis, int $status, string $aksi, array $data, ?string $reasonNote, AturanStudi $aturan): HasilKalkulasi
    {
        if (! in_array($aksi, self::AKSI, true)) {
            return HasilKalkulasi::gagal('aksi', self::pesanAksi($jenis));
        }

        if ($status !== StatusStudi::PENGAJUAN) {
            return HasilKalkulasi::gagal('status', self::pesanStatus($jenis));
        }

        $reason = trim((string) $reasonNote);

        if ($aksi === self::AKSI_TOLAK) {
            if ($reason === '') {
                return HasilKalkulasi::gagal('reason_note', self::PESAN_REASON_WAJIB);
            }

            if (strlen($reason) > $aturan->maksByteReason) {
                return HasilKalkulasi::gagal('reason_note', self::PESAN_REASON_PANJANG);
            }

            return HasilKalkulasi::berhasil(null, [
                'status'      => StatusStudi::DITOLAK,
                'final'       => true,
                'efek_final'  => false,
                'show_pl'     => null,
                'reason_note' => $reason,
            ]);
        }

        $errors = ValidasiStudi::periksaSk($data, self::fieldSk($jenis));

        if ($errors !== []) {
            $field = (string) array_key_first($errors);

            return HasilKalkulasi::gagal($field, $errors[$field], ['errors' => $errors]);
        }

        return HasilKalkulasi::berhasil(null, [
            'status'      => StatusStudi::DISETUJUI,
            'final'       => true,
            'efek_final'  => true,
            'show_pl'     => 1,
            'reason_note' => null,
        ]);
    }
}
