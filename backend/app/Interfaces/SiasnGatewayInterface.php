<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak gateway SIASN/SAPK BKN (F0-16, ADR-015) — sinkronisasi data kepegawaian nasional.
 * Implementasi: MockSiasnAdapter (Fase 0-5), adapter real SIASN (Fase 6).
 *
 * Seluruh method mengembalikan array dengan minimal: status_code (string), message (string), data.
 * Kegagalan SIASN di-log dan TIDAK boleh menggagalkan operasi utama consumer (Tech Spec Bagian 9).
 */
interface SiasnGatewayInterface
{
    /**
     * Ambil data utama pegawai dari SIASN.
     *
     * @return array{status_code: string, message: string, data: array<string, mixed>|null}
     */
    public function getPegawai(string $nip): array;

    /**
     * Ambil satu jenis riwayat (mis. 'jabatan', 'golongan', 'pendidikan').
     *
     * @return array{status_code: string, message: string, data: list<array<string, mixed>>}
     */
    public function getRiwayat(string $nip, string $jenis): array;

    /**
     * Kirim (push) perubahan data lokal ke SIASN.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{status_code: string, message: string, data: array<string, mixed>|null, synced_at: string|null}
     */
    public function syncPegawai(string $nip, array $payload): array;
}
