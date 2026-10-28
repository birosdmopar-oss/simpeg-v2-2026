<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * Bentuk item cuti untuk klien (PRD Fase 4 §6.2.4, §6.11.4): kolom DDL tanpa path berkas + `label_status`,
 * `nada_status`, `tahap[]`, dan `aksi {}` yang dihitung server. Tidak membaca DB: semua fakta diberikan pemanggil.
 */
final class CutiPenyaji
{
    /** Kolom path berkas: internal, tidak pernah dikirim ke klien. */
    public const KOLOM_INTERNAL = ['file_bukti', 'file_sc_unsign', 'file_sc_signed_bef', 'file_surat_cuti'];

    private function __construct()
    {
    }

    /**
     * @return array{label: string, nada: string}
     */
    public static function label(int $status, bool $langkahTunggal, bool $eselonI = false): array
    {
        return match (true) {
            $status === StatusCuti::PENGAJUAN && $eselonI                           => ['label' => 'Menunggu persetujuan Menteri (diproses admin)', 'nada' => 'info'],
            $status === StatusCuti::PENGAJUAN && ! $langkahTunggal                  => ['label' => 'Menunggu Atasan', 'nada' => 'warning'],
            $status === StatusCuti::PENGAJUAN, $status === StatusCuti::DISETUJUI_AL => ['label' => 'Menunggu PyB', 'nada' => 'info'],
            $status === StatusCuti::DISETUJUI                                       => ['label' => 'Disetujui', 'nada' => 'success'],
            $status === StatusCuti::DITOLAK                                         => ['label' => 'Ditolak', 'nada' => 'danger'],
            $status === StatusCuti::DITANGGUHKAN                                    => ['label' => 'Ditangguhkan', 'nada' => 'secondary'],
            default                                                                 => ['label' => StatusCuti::label($status), 'nada' => 'secondary'],
        };
    }

    /**
     * Stepper: dua langkah = Diajukan → Atasan Langsung → Pejabat Berwenang → Selesai; langkah tunggal tanpa Atasan.
     *
     * @param array<string, mixed> $cuti
     *
     * @return list<array{kode: string, label: string, state: string, nama_petugas: string|null}>
     */
    public static function tahap(array $cuti, bool $langkahTunggal): array
    {
        $status = (int) $cuti['status'];
        $final  = StatusCuti::final($status);
        $tahap  = [['kode' => 'diajukan', 'label' => 'Diajukan', 'state' => 'done', 'nama_petugas' => $cuti['nama'] ?? null]];

        if (! $langkahTunggal) {
            $keputusanAl = (int) ($cuti['status_al'] ?? 0);
            $stateAl     = $status === StatusCuti::PENGAJUAN ? 'current' : 'done';
            $labelAl     = 'Atasan Langsung';

            if ($keputusanAl === StatusCuti::DITOLAK) {
                $labelAl = 'Ditolak oleh Atasan Langsung';
            } elseif ($keputusanAl === StatusCuti::DITANGGUHKAN) {
                $labelAl = 'Ditangguhkan oleh Atasan Langsung';
            }

            $tahap[] = ['kode' => 'atasan', 'label' => $labelAl, 'state' => $stateAl, 'nama_petugas' => $cuti['nama_atasan_langsung'] ?? null];
        }

        $sudahAl = ! $langkahTunggal && (int) ($cuti['status_al'] ?? 0) !== 0 && in_array($status, [StatusCuti::DITOLAK, StatusCuti::DITANGGUHKAN], true)
            && (int) ($cuti['status_pyb'] ?? 0) === 0;

        $labelPyb = 'Pejabat Berwenang';
        $statePyb = $final ? 'done' : (($status === StatusCuti::DISETUJUI_AL || ($langkahTunggal && $status === StatusCuti::PENGAJUAN)) ? 'current' : 'todo');

        if ($sudahAl) {
            $statePyb = 'todo';
        } elseif ((int) ($cuti['status_pyb'] ?? 0) === StatusCuti::DITOLAK) {
            $labelPyb = 'Ditolak oleh Pejabat Berwenang';
        } elseif ((int) ($cuti['status_pyb'] ?? 0) === StatusCuti::DITANGGUHKAN) {
            $labelPyb = 'Ditangguhkan oleh Pejabat Berwenang';
        }

        $tahap[] = ['kode' => 'pejabat', 'label' => $labelPyb, 'state' => $statePyb, 'nama_petugas' => $cuti['nama_yang_menyetujui'] ?? null];
        // Label tetap "Selesai" seperti TB/IB/PMK (§6.11.4, CR-073); hasil terlihat dari badge & label tahap yang memutus.
        $tahap[] = ['kode' => 'selesai', 'label' => 'Selesai', 'state' => $final ? 'done' : 'todo', 'nama_petugas' => null];

        return $tahap;
    }

    /**
     * @param array<string, mixed> $cuti
     * @param array<string, bool>  $aksi
     * @param array<string, mixed> $ekstra
     *
     * @return array<string, mixed>
     */
    public static function item(array $cuti, bool $langkahTunggal, bool $eselonI, array $aksi, array $ekstra = []): array
    {
        $item   = array_diff_key($cuti, array_flip(self::KOLOM_INTERNAL));
        $status = (int) $cuti['status'];
        $label  = self::label($status, $langkahTunggal, $eselonI);

        return $item + [
            'label_status' => $label['label'],
            'nada_status'  => $label['nada'],
            'tahap'        => self::tahap($cuti, $langkahTunggal),
            'aksi'         => $aksi,
        ] + $ekstra;
    }
}
