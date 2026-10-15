<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Konket — Konfirmasi Ketidakhadiran (B-13) — milik WS-2; implementasi nyata MAKE-013
 * (`App\Libraries\Kepegawaian\Konket\KonketService`). Arti mengikuti legacy `absen_ijin` (bukan "kondisi kerja"
 * dokumen 03-Kepegawaian; deviasi dicatat di progres).
 */
interface KonketServiceInterface
{
    /**
     * Mode pegawai (UL_PEGAWAI): pengajuan sendiri. Mode admin (role 1/3): antrean `list_ad` dalam rentang tanggal.
     *
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $filter = []): array;

    /**
     * Opsi form: jenis konket per role, tanggal minimal, mode.
     *
     * @return array<string, mixed>
     */
    public function opsi(AuthContext $auth): array;

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $data, ?UploadedFile $berkas): array;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $data, ?UploadedFile $berkas): array;

    /**
     * @return array{deleted: true, id: int, ids: list<int>}
     */
    public function hapus(AuthContext $auth, int $id): array;

    /**
     * @return array<string, mixed>
     */
    public function proses(AuthContext $auth, int $id, string $aksi, ?string $alasan): array;

    /**
     * @return array{isi: string, nama: string}
     */
    public function berkas(AuthContext $auth, int $id): array;
}
