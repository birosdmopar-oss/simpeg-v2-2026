<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;

/**
 * MAKE-024 (bagian murni) — state machine pengajuan PMK (PRD Fase 4 v1.1 §6.9.2, D16), tanpa cek PIC (service M5),
 * notifikasi, atau efek DB. Pola galat `TransisiCuti`.
 *
 * | Dari | Aksi    | Ke |
 * |------|---------|----|
 * | 0    | proses  | 3  |
 * | 0, 3 | setujui | 1 (final) — [K P-14] 0 → 1 langsung boleh |
 * | 0, 3 | tolak   | 2 (final) — `reason_note` wajib [K `L_pmk.php:1763-1767`], ≤ 255 byte [V2 Q-23] |
 * | 1, 2 | apa pun | `errors.status` — status final dikunci [V2 single end node; legacy PIC bisa mengubahnya, P-14] |
 *
 * Setiap transisi berhasil menandai `show_notif = 1` (+ `notif_date`, `approved_at`, `approved_by` oleh service)
 * [K `L_pmk.php:1774-1792`]. Persetujuan tidak menyalin ke `riwayat_pmk` dan tidak mengubah masa kerja (D16).
 */
final class TransisiPmk
{
    public const AKSI_PROSES = 'proses';

    public const AKSI_SETUJUI = 'setujui';

    public const AKSI_TOLAK = 'tolak';

    public const AKSI = [self::AKSI_PROSES, self::AKSI_SETUJUI, self::AKSI_TOLAK];

    /** [K `L_pmk.php:1761`] diperluas dengan aksi proses [V2]. */
    public const PESAN_AKSI = 'Silahkan proses, setujui, atau tolak pengajuan PMK pegawai.';

    /** [K `L_pmk.php:1766`]. */
    public const PESAN_REASON_WAJIB = 'Alasan ditolak tidak boleh kosong.';

    public const PESAN_REASON_PANJANG = 'Alasan maksimal 255 karakter.';

    public const PESAN_STATUS = 'Pengajuan PMK ini tidak dalam status yang dapat diproses.';

    /** Transisi sah: status asal → aksi → status tujuan. */
    private const TRANSISI = [
        StatusPmk::PENGAJUAN => [
            self::AKSI_PROSES  => StatusPmk::PROSES,
            self::AKSI_SETUJUI => StatusPmk::SELESAI,
            self::AKSI_TOLAK   => StatusPmk::DITOLAK,
        ],
        StatusPmk::PROSES => [
            self::AKSI_SETUJUI => StatusPmk::SELESAI,
            self::AKSI_TOLAK   => StatusPmk::DITOLAK,
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Aksi yang tersedia untuk status (untuk `aksi {}` respons/FE); kosong bila final.
     *
     * @return list<string>
     */
    public static function aksiTersedia(int $status): array
    {
        return array_keys(self::TRANSISI[$status] ?? []);
    }

    /**
     * Berhasil → `detail`: `status`, `final`, `show_notif` (1), `reason_note`.
     */
    public static function proses(int $status, string $aksi, ?string $reasonNote, AturanPmk $aturan): HasilKalkulasi
    {
        if (! in_array($aksi, self::AKSI, true)) {
            return HasilKalkulasi::gagal('aksi', self::PESAN_AKSI);
        }

        $tujuan = self::TRANSISI[$status][$aksi] ?? null;

        if ($tujuan === null) {
            return HasilKalkulasi::gagal('status', self::PESAN_STATUS);
        }

        $reason = trim((string) $reasonNote);

        if ($aksi === self::AKSI_TOLAK) {
            if ($reason === '') {
                return HasilKalkulasi::gagal('reason_note', self::PESAN_REASON_WAJIB);
            }

            if (strlen($reason) > $aturan->maksByteReason) {
                return HasilKalkulasi::gagal('reason_note', self::PESAN_REASON_PANJANG);
            }
        }

        return HasilKalkulasi::berhasil(null, [
            'status'      => $tujuan,
            'final'       => StatusPmk::final($tujuan),
            'show_notif'  => 1,
            'reason_note' => $aksi === self::AKSI_TOLAK ? $reason : null,
        ]);
    }
}
