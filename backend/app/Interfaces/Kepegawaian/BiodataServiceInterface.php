<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Biodata pegawai dua jalur + approval draft (B-03/B-04) — milik WS-2.
 *
 * Didaftarkan di Config\Services sejak S0-A (MAKE-002) dengan stub fail-closed; implementasi nyata
 * `App\Libraries\Kepegawaian\Pegawai\BiodataService` (MAKE-010). Method aditif oleh pemiliknya (approval B-04 di
 * MAKE-011).
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
     * @return array{jalur: 'langsung'|'pengajuan', pegawai: array<string, mixed>, pengajuan: array<string, mixed>|null}
     */
    public function ubah(AuthContext $auth, string $nip, array $data, ?UploadedFile $foto = null): array;

    /**
     * Foto profil terkini pegawai (`pegawai.foto`) untuk ditampilkan — lingkup `bolehLihat` (403), tanpa foto atau
     * berkas tidak ada di penyimpanan v2 (mis. path arsip remote legacy) → 404.
     *
     * @return array{isi: string, mime: string, nama: string}
     */
    public function foto(AuthContext $auth, string $nip): array;
}
