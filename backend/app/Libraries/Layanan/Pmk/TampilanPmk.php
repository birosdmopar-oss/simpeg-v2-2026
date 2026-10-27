<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

/**
 * Badge & stepper pengajuan PMK yang dihitung server (PRD Fase 4 §6.11.4, §6.9.2): `label_status` (label UI
 * `StatusPmk::labelUi`: 0 Menunggu PIC, 3 Diproses, 1 Selesai, 2 Ditolak), `nada_status` (warning / info / success /
 * danger), `tahap[]` = Diajukan → Diproses PIC → Selesai; tolak ditandai pada tahap PIC. Murni.
 */
final class TampilanPmk
{
    private const NADA = [
        StatusPmk::PENGAJUAN => 'warning',
        StatusPmk::PROSES    => 'info',
        StatusPmk::SELESAI   => 'success',
        StatusPmk::DITOLAK   => 'danger',
    ];

    private function __construct()
    {
    }

    public static function nadaStatus(int $status): string
    {
        return self::NADA[$status] ?? 'secondary';
    }

    /**
     * @return list<array{kode: string, label: string, state: string, nama_petugas: ?string, waktu: ?string}>
     */
    public static function tahap(int $status, ?string $diajukanPada, ?string $diprosesPada): array
    {
        $final = StatusPmk::final($status);
        $tolak = $status === StatusPmk::DITOLAK;
        $mulai = $status !== StatusPmk::PENGAJUAN;

        return [
            ['kode' => 'diajukan', 'label' => 'Diajukan', 'state' => 'done', 'nama_petugas' => null, 'waktu' => $diajukanPada],
            [
                'kode'         => 'pic',
                'label'        => $tolak ? 'Ditolak oleh PIC' : 'Diproses PIC',
                'state'        => $final ? 'done' : 'current',
                'nama_petugas' => null,
                'waktu'        => $mulai ? $diprosesPada : null,
            ],
            ['kode' => 'selesai', 'label' => 'Selesai', 'state' => $final ? 'done' : 'todo', 'nama_petugas' => null, 'waktu' => $final ? $diprosesPada : null],
        ];
    }
}
