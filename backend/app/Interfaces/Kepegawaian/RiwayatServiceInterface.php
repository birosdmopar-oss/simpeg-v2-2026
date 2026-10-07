<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;

/**
 * Mesin riwayat generik (RiwayatEngine, WS-1 M1 MAKE-004) di balik endpoint `api/v1/pegawai/{nip}/riwayat/{jenis}`
 * (kontrak beku S0-A MAKE-002). Payload & hasil snake_case = kolom DDL.
 *
 * Error dilempar sebagai App\Exceptions\*: NotFoundException 404 (NIP/jenis/baris tidak ada), ForbiddenException 403
 * (role atau lingkup tidak berhak), ValidationException 422 (errors per field). Tulis memakai transaksi engine,
 * approve/reject dicatat `audit_logs` aksi `update` (before/after), waktu disimpan UTC.
 */
interface RiwayatServiceInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function daftar(AuthContext $auth, string $nip, string $jenis): array;

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, string $nip, string $jenis, int $id): array;

    /**
     * Status awal 0 Menunggu untuk UL_PEGAWAI (role 2/6/7) pada alur self-service, 1 Disetujui untuk admin.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> baris tersimpan
     */
    public function tambah(AuthContext $auth, string $nip, string $jenis, array $data): array;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> baris tersimpan
     */
    public function ubah(AuthContext $auth, string $nip, string $jenis, int $id, array $data): array;

    /**
     * Hapus lunak → status 10 (snapshot dihitung ulang bila baris itu aktif).
     */
    public function hapus(AuthContext $auth, string $nip, string $jenis, int $id): void;

    /**
     * $aksi 'setujui' | 'tolak'; $reasonNote wajib saat tolak (422 bila kosong).
     *
     * @return array<string, mixed> baris setelah diproses
     */
    public function proses(AuthContext $auth, string $nip, string $jenis, int $id, string $aksi, ?string $reasonNote): array;
}
