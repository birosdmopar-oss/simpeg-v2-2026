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
     * Bagan struktur organisasi ikut templat legacy so_012025 (keputusan user 13-10-2026): format per pilihan
     * `id_unit_satker` ('0' kementerian, '<id_unit>', 'sat_<id_satker>') dan pejabat (definitif, lalu Plt/Plh) pada
     * tanggal acuan. Semua pengguna login (legacy `hr/so/full` = sesi, `hr/rwy/jabatan/so` = UL_ALL).
     *
     * @param array<string, mixed> $filter `id_unit_satker` (bawaan '0'), `bulan` YYYY-MM (bawaan bulan berjalan; sejak 2022-01)
     *
     * @return array<string, mixed> `{ periode, tanggal, bulan, bulan_tersedia, id_unit_satker, kementerian, pilihan, judul, format, data }`
     */
    public function pohon(AuthContext $auth, array $filter = []): array;

    /**
     * Ekspor PDF bagan (TCPDF, ADR-033) untuk filter yang sama dengan pohon().
     *
     * @param array<string, mixed> $filter
     *
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, array $filter = []): array;
}
