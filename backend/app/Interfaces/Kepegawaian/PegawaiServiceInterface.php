<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Daftar & detail pegawai (B-20 dasar, `GET api/v1/pegawai`, `GET api/v1/pegawai/{nip}`) — milik WS-2.
 *
 * Didaftarkan di Config\Services sejak S0-A (MAKE-002) dengan stub fail-closed; implementasi nyata
 * `App\Libraries\Kepegawaian\Pegawai\PegawaiService` (MAKE-010). Method aditif oleh pemiliknya.
 */
interface PegawaiServiceInterface
{
    /**
     * Daftar pegawai disaring lingkup pemanggil (`PegawaiScope::terapkanKeQuery`, jalur daftar) — role 1, 3, 4, 5, 8
     * (Matriks `hr/employee/index`), selain itu 403.
     *
     * @param array<string, mixed> $filter search, status, id_unit, id_satker, id_jenis_pegawai, sort, order, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(AuthContext $auth, array $filter = []): array;

    /**
     * Detail pegawai + `tabs` (descriptor `RiwayatRegistryInterface::descriptorUntuk`) — UL_ALL dalam lingkup
     * `bolehLihat`. Urutan: 403 lingkup (NIP tidak ada bagi role ber-lingkup juga 403) → 404 NIP.
     *
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, string $nip): array;

    /**
     * B-05 (MAKE-011, role 1): tambah pegawai + foto + akun A-09 dalam satu transaksi. 403 → 422 (`nip`, kolom biodata,
     * `foto`).
     *
     * @param array<string, mixed> $data kolom DDL `pegawai` + `nip` + `email` + `jenis_kerabat_lain`
     *
     * @return array{pegawai: array<string, mixed>, akun: array<string, mixed>}
     */
    public function tambah(AuthContext $auth, array $data, ?UploadedFile $foto): array;

    /**
     * B-05 (MAKE-011, role 1): hapus FISIK ikut legacy (keputusan user 13-10-2026). 403 → 404 → 422 `errors.nip` bila
     * masih ada data terkait (FK RESTRICT ke pegawai, akun ber-NIP).
     *
     * @return array<string, mixed> baris pegawai sebelum dihapus
     */
    public function hapus(AuthContext $auth, string $nip): array;
}
