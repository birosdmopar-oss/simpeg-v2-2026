<?php

declare(strict_types=1);

namespace App\Interfaces\Notifikasi;

use App\Libraries\Auth\AuthContext;

/**
 * Sumber notifikasi eksternal untuk Pusat Notifikasi F-08 (PRD Fase 7 v2.0 §7 A ↔ C; MAKE-085).
 *
 * Berkas milik Jalur C; implementasi milik modul sumbernya (mis. `SumberBerita` Jalur A, F-07). Binding lewat
 * `Config\Services::sumberNotifikasiBerita()` — NoOp ({@see \App\Libraries\Notifikasi\Pusat\SumberNotifikasiNoOp}) sampai
 * modul sumber ada di `main`. Pusat notifikasi TIDAK membaca tabel sumber langsung.
 *
 * Bentuk item daftar notifikasi (semua kunci wajib):
 * `{ id: int, judul: string, keterangan: string, status_label: ?string, reason_note: ?string, url_fe: string,
 *    dibaca: bool, waktu: string (UTC `Y-m-d H:i:s`), dapat_dihapus: bool }`.
 * Bentuk item antrean/histori: item daftar + `nip: ?string`, `nama: ?string`, `unit: ?string` (boleh null).
 */
interface SumberNotifikasiInterface
{
    /**
     * Kunci sumber di id gabungan `<kode>:<id>` (huruf kecil, mis. `berita`).
     */
    public function kode(): string;

    /**
     * false = sumber belum tersedia (NoOp): pusat notifikasi melewati sumber ini dan penghitung terkait bernilai null.
     */
    public function tersedia(): bool;

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int;

    /**
     * Notifikasi milik pemanggil, terbaru dulu, paling banyak $batas.
     *
     * @return list<array<string, mixed>>
     */
    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array;

    /**
     * Tandai dibaca (hanya milik pemanggil). Mengembalikan jumlah yang berubah.
     *
     * @param list<int> $ids
     */
    public function tandaiDibaca(AuthContext $auth, array $ids): int;

    /**
     * Hapus dari daftar notifikasi pemanggil. false = tidak ditemukan / bukan milik pemanggil / tidak boleh dihapus.
     */
    public function hapus(AuthContext $auth, int $id): bool;

    /**
     * Jumlah antrean yang menunggu proses pemanggil (Butuh Approval).
     */
    public function jumlahMenunggu(AuthContext $auth): int;

    /**
     * @return array{items: list<array<string, mixed>>, total: int, url_fe: ?string}
     */
    public function daftarMenunggu(AuthContext $auth, int $page, int $perPage): array;

    /**
     * Histori yang diproses pemanggil pada bulan `YYYY-MM`.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function histori(AuthContext $auth, string $bulan, int $page, int $perPage): array;

    /**
     * Penghitung khusus lonceng di luar daftar (mis. berita portal belum dibuka). null = tidak berlaku.
     */
    public function jumlahLonceng(AuthContext $auth): ?int;
}
