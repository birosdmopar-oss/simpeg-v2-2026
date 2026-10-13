<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;

/**
 * LKH — Laporan Kerja Harian, verifikasi atasan langsung (B-12b) — milik WS-2; implementasi nyata MAKE-013
 * (`App\Libraries\Kepegawaian\Lkh\LkhService`), tabel `riwayat_lckh`.
 */
interface LkhServiceInterface
{
    /**
     * LKH milik `nip` (bawaan: pegawai pemanggil). Status 0 Menunggu, 1 Disetujui, 3 Revisi (2/10 tidak ditampilkan).
     *
     * @param array<string, mixed> $filter `nip`, `page`, `per_page`
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $filter = []): array;

    /**
     * Antrean verifikasi atasan langsung: LKH yang `nip_atasan`-nya = NIP pemanggil.
     *
     * @param array<string, mixed> $filter `status` (bawaan 0), `ds`, `de`, `search`, `page`, `per_page`
     *
     * @return array<string, mixed>
     */
    public function verifikasi(AuthContext $auth, array $filter = []): array;

    /**
     * Rentang tanggal laporan & atasan langsung untuk form (bawaan: pegawai pemanggil).
     *
     * @return array<string, mixed>
     */
    public function opsi(AuthContext $auth, ?string $nip = null): array;

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $data): array;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $data): array;

    /**
     * @return array{deleted: true, id: int}
     */
    public function hapus(AuthContext $auth, int $id): array;

    /**
     * Verifikasi atasan langsung: `setujui` (1), `tolak` (2), `revisi` (3).
     *
     * @return array<string, mixed>
     */
    public function proses(AuthContext $auth, int $id, string $aksi, ?string $catatan): array;

    /**
     * PDF LKH (TCPDF, ADR-033).
     *
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, int $id): array;

    /**
     * Descriptor tab non-engine `lkh` untuk Detail Pegawai (MAKE-014; bentuk sama dengan descriptor engine), atau null
     * bila pemanggil tidak boleh melihat LKH pegawai itu (hak = `daftar(['nip' => $nip])`).
     *
     * @return array{jenis: string, label: string, can_view: bool, can_create: bool, can_edit: bool, can_delete: bool, can_process: bool}|null
     */
    public function descriptorTab(AuthContext $auth, string $nip): ?array;
}
