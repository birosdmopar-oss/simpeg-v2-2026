<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak pola dual snapshot (F0-05, ADR-006): tabel riwayat_* (histori) vs pegawai_* (snapshot aktif).
 *
 * Sinkronisasi ke snapshot HANYA terjadi lewat pemanggilan eksplisit syncToActiveSnapshot()
 * di final approval step — TIDAK PERNAH otomatis lewat Model Events.
 */
interface SyncsToSnapshot
{
    /**
     * Nama tabel snapshot aktif, mis. 'pegawai_mutasi_jabatan'.
     */
    public function snapshotTable(): string;

    /**
     * Kolom identitas yang sama di riwayat & snapshot (umumnya 'nip'); satu row snapshot per nilai key.
     */
    public function snapshotKey(): string;

    /**
     * Pemetaan kolom riwayat => kolom snapshot. Elemen dengan key numerik berarti nama kolom sama.
     * Contoh: ['id_jabatan', 'tmt' => 'tmt_jabatan'].
     *
     * @return array<int|string, string>
     */
    public function snapshotFields(): array;

    /**
     * Salin row riwayat ber-id $riwayatId ke snapshot aktif (insert atau update by snapshotKey).
     * Wajib menulis audit_logs secara manual karena memakai raw query builder.
     */
    public function syncToActiveSnapshot(int|string $riwayatId): bool;
}
