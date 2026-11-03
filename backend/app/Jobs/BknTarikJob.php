<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Bkn\Ekinerja\TarikEkinerja;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use RuntimeException;

/**
 * Job `bkn-tarik` di antrean `integrasi` (PRD Fase 6 §6.2, H-10): dijalankan worker
 * `php spark queue:work integrasi --stop-when-empty --max-time 55` (runbook B7). Data `{jenis: periode|skp_periodik,
 * tahun: ?int, bulan: ?int}` dari {@see \App\Libraries\Bkn\Ekinerja\AntreTarikEkinerja}. Tarik idempoten (AC-10.1) sehingga
 * aman di-retry; gagal (HTTP/respons/token) → dilempar agar worker mencoba ulang sampai {@see self::TRIES}, lalu
 * `queue_jobs_failed`. "Periode belum ada" dan "periode manual" bukan galat retry.
 */
class BknTarikJob extends BaseJob implements JobInterface
{
    public const ANTREAN = 'integrasi';

    public const NAMA = 'bkn-tarik';

    public const TRIES = 3;

    protected int $retryAfter = 300;

    protected int $tries = self::TRIES;

    public function process(): string
    {
        $jenis = (string) ($this->data['jenis'] ?? '');
        $tarik = TarikEkinerja::dariLayanan();

        $hasil = match ($jenis) {
            'periode'      => $tarik->periode(),
            'skp_periodik' => $tarik->skpPeriodik(
                isset($this->data['tahun']) ? (int) $this->data['tahun'] : null,
                isset($this->data['bulan']) ? (int) $this->data['bulan'] : null,
            ),
            default => throw new RuntimeException('Jenis tarik e-Kinerja tidak dikenal.'),
        };

        if ($hasil['status'] === TarikEkinerja::STATUS_GAGAL) {
            throw new RuntimeException("Tarik e-Kinerja {$jenis} gagal: HTTP " . ($hasil['http'] ?? '-') . '.');
        }

        return $hasil['status'];
    }
}
