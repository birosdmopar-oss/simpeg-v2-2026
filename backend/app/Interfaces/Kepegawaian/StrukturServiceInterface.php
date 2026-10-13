<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;

/**
 * Struktur organisasi (B-19) — milik WS-2.
 *
 * Didaftarkan di Config\Services sejak S0-A (MAKE-002) dengan stub fail-closed; implementasi nyata MAKE-012
 * (`App\Libraries\Kepegawaian\Struktur\StrukturService`).
 */
interface StrukturServiceInterface
{
    /**
     * Pohon struktur organisasi periode aktif (struktur_jabatan) beserta pejabat saat ini: definitif dari
     * `pegawai_mutasi_jabatan`, bila kosong Plt lalu Plh yang aktif hari ini. Semua pengguna yang login (legacy
     * `hr/so/full` = sesi, `hr/rwy/jabatan/so` = UL_ALL).
     *
     * @param array<string, mixed> $filter `id_unit`, `id_satker` (opsional)
     *
     * @return array{periode: array{id_periode_struktur_jabatan: int, periode_struktur_jabatan: string}|null, filter: array{id_unit: int|null, id_satker: int|null}, nodes: list<array<string, mixed>>}
     */
    public function pohon(AuthContext $auth, array $filter = []): array;
}
