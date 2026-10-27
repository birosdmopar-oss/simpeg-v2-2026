<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;

/**
 * MAKE-022 Tahap 2b — state machine cuti murni (PRD Fase 4 v1.1 §6.4.2 T4–T10, §6.4.3), tanpa penentuan AL/PyB
 * (M3) dan tanpa notifikasi. Input: status saat ini, apakah alur langkah tunggal, tahap pemanggil, aksi; output:
 * status/`status_al`/`status_pyb` baru + penanda efek final.
 *
 * - Dua langkah: 0 → AL setujui → 3 → PyB setujui/tolak/tangguhkan → 1/2/4; AL tolak/tangguhkan dari 0 → 2/4 [K A-05].
 * - Langkah tunggal (`nip_atasan_langsung` kosong atau = `nip_yang_menyetujui`, D2): keputusan pertama langsung final;
 *   `status_al = status_pyb = keputusan`.
 * - T4 (AL setujui): `status_al = 1`, **`status_pyb` tidak ditulis** (tetap 0) [K `L:2654-2660`, `L:3810-3818`;
 *   keputusan #3]. Nilai yang tidak ditulis dikembalikan sebagai `null`.
 * - Tangguhkan hanya jenis 1; tolak wajib `reason_note` (≤ 255 byte). Setiap transisi lain → `errors.status`.
 * - `keterangan_petugas_approval`: baris AL ditulis ulang, baris PyB ditambahkan dengan pemisah `"\n"`
 *   (jalur `process()` `L:2672-2686`; keputusan #7).
 */
final class TransisiCuti
{
    public const AKSI_SETUJUI = 'setujui';

    public const AKSI_TOLAK = 'tolak';

    public const AKSI_TANGGUHKAN = 'tangguhkan';

    public const AKSI = [self::AKSI_SETUJUI, self::AKSI_TOLAK, self::AKSI_TANGGUHKAN];

    public const TAHAP_AL = 'al';

    public const TAHAP_PYB = 'pyb';

    public const PESAN_AKSI = 'Silahkan setujui, tolak, atau tangguhkan permohonan cuti online pegawai.';

    /** [K] `L:3657-3663`. */
    public const PESAN_REASON_WAJIB = 'Silahkan isi alasan penolakan jika menolak permohonan cuti online pegawai.';

    public const PESAN_REASON_PANJANG = 'Alasan maksimal 255 karakter.';

    /** [K] `views/template/modal.php:1323-1334`. */
    public const PESAN_TANGGUHKAN_BUKAN_TAHUNAN = 'Penangguhan hanya berlaku untuk cuti tahunan.';

    public const PESAN_TAHAP = 'Permohonan cuti tidak dalam tahap yang dapat Anda proses.';

    public const MAKS_BYTE_REASON = 255;

    private const KEPUTUSAN = [
        self::AKSI_SETUJUI    => StatusCuti::DISETUJUI,
        self::AKSI_TOLAK      => StatusCuti::DITOLAK,
        self::AKSI_TANGGUHKAN => StatusCuti::DITANGGUHKAN,
    ];

    private const LABEL_KEPUTUSAN = [
        self::AKSI_SETUJUI    => 'Disetujui',
        self::AKSI_TOLAK      => 'Ditolak',
        self::AKSI_TANGGUHKAN => 'Ditangguhkan',
    ];

    private function __construct()
    {
    }

    /**
     * Langkah tunggal (D2): AL kosong/NULL atau AL = PyB.
     */
    public static function langkahTunggal(?string $nipAl, ?string $nipPyb): bool
    {
        $al = trim((string) $nipAl);

        return $al === '' || $al === trim((string) $nipPyb);
    }

    /**
     * Tahap yang sedang menunggu keputusan: `al` (status 0 dua langkah), `pyb` (status 3, atau status 0 langkah
     * tunggal), `null` bila final.
     */
    public static function tahapAktif(int $status, bool $langkahTunggal): ?string
    {
        return match ($status) {
            StatusCuti::PENGAJUAN    => $langkahTunggal ? self::TAHAP_PYB : self::TAHAP_AL,
            StatusCuti::DISETUJUI_AL => self::TAHAP_PYB,
            default                  => null,
        };
    }

    /**
     * NIP yang berhak memproses tahap aktif (langkah tunggal: PyB, atau AL bila PyB kosong); `null` bila final.
     */
    public static function nipPelaku(int $status, ?string $nipAl, ?string $nipPyb): ?string
    {
        $al  = trim((string) $nipAl);
        $pyb = trim((string) $nipPyb);

        return match (self::tahapAktif($status, self::langkahTunggal($nipAl, $nipPyb))) {
            self::TAHAP_AL  => $al,
            self::TAHAP_PYB => $pyb !== '' ? $pyb : $al,
            default         => null,
        };
    }

    /**
     * Proses satu keputusan. Berhasil → `detail`: `status`, `status_al`, `status_pyb` (int, atau `null` = kolom tidak
     * ditulis), `final`, `efek_final`, `tahap`, `flag_tampil` (`show_atasan`/`show_pejabat`), `reason_note`.
     */
    public static function proses(
        int $status,
        bool $langkahTunggal,
        string $tahapPemanggil,
        string $aksi,
        int $jenis,
        ?string $reasonNote = null,
    ): HasilKalkulasi {
        if (! in_array($aksi, self::AKSI, true)) {
            return HasilKalkulasi::gagal('aksi', self::PESAN_AKSI);
        }

        $tahap = self::tahapAktif($status, $langkahTunggal);

        if ($tahap === null || $tahap !== $tahapPemanggil) {
            return HasilKalkulasi::gagal('status', self::PESAN_TAHAP);
        }

        $reason = trim((string) $reasonNote);

        if ($aksi === self::AKSI_TOLAK && $reason === '') {
            return HasilKalkulasi::gagal('reason_note', self::PESAN_REASON_WAJIB);
        }

        if (strlen($reason) > self::MAKS_BYTE_REASON) {
            return HasilKalkulasi::gagal('reason_note', self::PESAN_REASON_PANJANG);
        }

        if ($aksi === self::AKSI_TANGGUHKAN && $jenis !== AturanCuti::JENIS_TAHUNAN) {
            return HasilKalkulasi::gagal('aksi', self::PESAN_TANGGUHKAN_BUKAN_TAHUNAN);
        }

        $keputusan = self::KEPUTUSAN[$aksi];

        if ($tahap === self::TAHAP_AL) {
            // Dua langkah dari 0: setujui → 3 (status_pyb tidak ditulis); tolak/tangguhkan → final.
            $statusBaru = $aksi === self::AKSI_SETUJUI ? StatusCuti::DISETUJUI_AL : $keputusan;
            $statusAl   = $keputusan;
            $statusPyb  = null;
        } elseif ($status === StatusCuti::PENGAJUAN) {
            // Langkah tunggal dari 0.
            $statusBaru = $keputusan;
            $statusAl   = $keputusan;
            $statusPyb  = $keputusan;
        } else {
            // PyB dari 3.
            $statusBaru = $keputusan;
            $statusAl   = null;
            $statusPyb  = $keputusan;
        }

        return HasilKalkulasi::berhasil(null, [
            'status'      => $statusBaru,
            'status_al'   => $statusAl,
            'status_pyb'  => $statusPyb,
            'final'       => StatusCuti::final($statusBaru),
            'efek_final'  => in_array($statusBaru, StatusCuti::EFEK_FINAL, true),
            'tahap'       => $tahap,
            'flag_tampil' => $tahap === self::TAHAP_AL ? 'show_atasan' : 'show_pejabat',
            'reason_note' => $reason === '' ? null : $reason,
        ]);
    }

    /**
     * Baris `keterangan_petugas_approval`: "- Disetujui {nama} - ({jabatan})" [K `L:2672-2686`].
     */
    public static function barisKeterangan(string $aksi, string $nama, string $jabatan): string
    {
        $label = self::LABEL_KEPUTUSAN[$aksi] ?? $aksi;

        return "- {$label} {$nama} - ({$jabatan})";
    }

    /**
     * Gabungkan ke nilai lama: tahap AL menulis ulang, tahap PyB menambahkan dengan pemisah `"\n"` (keputusan #7).
     */
    public static function keteranganPetugas(?string $lama, string $baris, string $tahap): string
    {
        $lama = trim((string) $lama);

        if ($tahap === self::TAHAP_AL || $lama === '') {
            return $baris;
        }

        return $lama . "\n" . $baris;
    }
}
