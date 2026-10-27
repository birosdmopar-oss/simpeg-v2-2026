<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

/**
 * Badge & stepper TB/IB yang dihitung server (PRD Fase 4 §6.11.4): `label_status`, `nada_status`, `tahap[]`. Murni.
 *
 * Stepper TB/IB = Diajukan → Verifikasi PIC → Selesai; tolak ditandai pada tahap Verifikasi PIC ("Ditolak oleh …").
 * Status 3 [K] (hanya data impor) ditampilkan sebagai tahap PIC berjalan.
 */
final class TampilanStudi
{
    private const LABEL = [
        StatusStudi::PENGAJUAN => 'Menunggu PIC',
        StatusStudi::DISETUJUI => 'Disetujui',
        StatusStudi::DITOLAK   => 'Ditolak',
        StatusStudi::DIPROSES  => 'Menunggu PIC',
    ];

    private const NADA = [
        StatusStudi::PENGAJUAN => 'warning',
        StatusStudi::DISETUJUI => 'success',
        StatusStudi::DITOLAK   => 'danger',
        StatusStudi::DIPROSES  => 'warning',
    ];

    private function __construct()
    {
    }

    public static function labelStatus(int $status): string
    {
        return self::LABEL[$status] ?? StatusStudi::label($status);
    }

    public static function nadaStatus(int $status): string
    {
        return self::NADA[$status] ?? 'secondary';
    }

    /**
     * @param string|null $diajukanPada waktu pengajuan (`created_at`)
     * @param string|null $diputusPada  waktu keputusan (`notif_date`/`updated_at` saat final)
     * @param string|null $namaPetugas  nama PIC yang memutus (bila diketahui)
     *
     * @return list<array{kode: string, label: string, state: string, nama_petugas: ?string, waktu: ?string}>
     */
    public static function tahap(int $status, ?string $diajukanPada, ?string $diputusPada, ?string $namaPetugas): array
    {
        $final = StatusStudi::final($status);
        $tolak = $status === StatusStudi::DITOLAK;

        return [
            ['kode' => 'diajukan', 'label' => 'Diajukan', 'state' => 'done', 'nama_petugas' => null, 'waktu' => $diajukanPada],
            [
                'kode'         => 'pic',
                'label'        => $tolak ? 'Ditolak oleh ' . ($namaPetugas ?? 'PIC') : 'Verifikasi PIC',
                'state'        => $final ? 'done' : 'current',
                'nama_petugas' => $final ? $namaPetugas : null,
                'waktu'        => $final ? $diputusPada : null,
            ],
            ['kode' => 'selesai', 'label' => 'Selesai', 'state' => $final ? 'done' : 'todo', 'nama_petugas' => null, 'waktu' => $final ? $diputusPada : null],
        ];
    }
}
