<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Biodata pegawai dua jalur + approval draft (B-03/B-04) — milik WS-2.
 *
 * Didaftarkan di Config\Services sejak S0-A (MAKE-002) dengan stub fail-closed; implementasi nyata
 * `App\Libraries\Kepegawaian\Pegawai\BiodataService` (MAKE-010; approval B-04 aditif MAKE-011).
 */
interface BiodataServiceInterface
{
    /**
     * Ubah biodata (Matriks `hr/employee/edit` = role 1, 2, 3, 6, 7). Role 1 → langsung ke `pegawai`; role 2/3/6/7 →
     * `pegawai.flag_update` = 1 + satu `pegawai_hist` diajukan per NIP (pengajuan aktif diperbarui, bukan ditambah).
     * Urutan: role (403) → lingkup `bolehUbah` (403) → NIP (404) → validasi (422 per kolom DDL; berkas foto `foto`).
     *
     * @param array<string, mixed> $data kolom DDL `pegawai` + `email` (akun) + `jenis_kerabat_lain`
     *
     * Email yang sudah dipakai akun lain: pengajuan → 422 `errors.email`; jalur langsung → biodata tersimpan, email akun
     * tidak disinkron (`email_skipped` true) (MAKE-011).
     *
     * @return array{jalur: 'langsung'|'pengajuan', pegawai: array<string, mixed>, pengajuan: array<string, mixed>|null, email_skipped: bool}
     */
    public function ubah(AuthContext $auth, string $nip, array $data, ?UploadedFile $foto = null): array;

    /**
     * Foto profil terkini pegawai (`pegawai.foto`) untuk ditampilkan — lingkup `bolehLihat` (403), tanpa foto atau
     * berkas tidak ada di penyimpanan v2 (mis. path arsip remote legacy) → 404.
     *
     * @return array{isi: string, mime: string, nama: string}
     */
    public function foto(AuthContext $auth, string $nip): array;

    /**
     * B-04 (MAKE-011): antrean pengajuan biodata yang menunggu (`flag_update` 1) — role 1, 3 (403 selain itu), disaring
     * lingkup jalur PROSES.
     *
     * @param array<string, mixed> $filter search, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function antreanPengajuan(AuthContext $auth, array $filter = []): array;

    /**
     * B-04: detail pengajuan `{ pengajuan, pegawai, perubahan, can_process }` — pemroses dalam lingkup proses atau
     * pemilik pengajuan (403); tidak ada → 404.
     *
     * @return array<string, mixed>
     */
    public function pengajuan(AuthContext $auth, int $idHist): array;

    /**
     * B-04: foto pada pengajuan (untuk dibandingkan) — hak sama dengan pengajuan(); tanpa foto/berkas → 404.
     *
     * @return array{isi: string, mime: string, nama: string}
     */
    public function fotoPengajuan(AuthContext $auth, int $idHist): array;

    /**
     * B-04: `$aksi` 'setujui' | 'tolak' (alasan wajib saat tolak, 422). Urutan 403 role → 404 → 403 lingkup proses →
     * 422 validasi/status.
     *
     * @return array<string, mixed> detail pengajuan sesudah diproses
     */
    public function prosesPengajuan(AuthContext $auth, int $idHist, string $aksi, ?string $reasonNote): array;
}
