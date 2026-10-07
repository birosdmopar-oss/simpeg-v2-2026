<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\MasterData\MasterField;

/**
 * Definisi satu jenis riwayat sebagai DATA (kontrak beku S0-A MAKE-002; pemilik WS-1). RiwayatEngine/SnapshotSync
 * (WS-1 M1) membaca definisi ini; tidak ada kode khusus per jenis di luar hook opsional di bawah.
 *
 * Satu berkas per jenis di `app/Libraries/Kepegawaian/Riwayat/Definisi/<Nama>.php` (namespace
 * `App\Libraries\Kepegawaian\Riwayat\Definisi`, nama kelas = nama berkas). RiwayatRegistry menemukannya lewat
 * auto-discovery — tidak ada daftar terpusat yang harus diedit.
 *
 * Hak akses per aksi per role disimpan di izin() mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`), sehingga
 * perubahan hak cukup satu baris di definisi jenisnya. Lingkup pegawai (unit/satker/milik sendiri) BUKAN urusan
 * definisi — itu PegawaiScopeInterface.
 */
abstract class RiwayatDefinisi
{
    /**
     * Slug URL `{jenis}` — wajib salah satu JenisRiwayat::SLUG.
     */
    abstract public function jenis(): string;

    /**
     * Label tab/halaman, mis. 'Riwayat Pendidikan'.
     */
    abstract public function label(): string;

    /**
     * Tabel riwayat, mis. 'riwayat_pendidikan'.
     */
    abstract public function tabel(): string;

    /**
     * Kolom PK tabel riwayat, mis. 'id_riwayat_pendidikan'.
     */
    abstract public function primaryKey(): string;

    /**
     * Field yang boleh diisi lewat API (nama = kolom DDL, snake_case). Kolom sistem (PK, nip, status, created_at,
     * updated_at, updated_by, approved_by, reason_note) tidak ditulis di sini — dikelola engine.
     *
     * @return list<MasterField>
     */
    abstract public function fields(): array;

    /**
     * Izin per aksi: key AksiRiwayat::value ('lihat', 'tambah', 'ubah', 'hapus', 'proses') => daftar role
     * (App\Constants\Role). Aksi yang tidak ditulis = tidak ada role yang boleh.
     *
     * @return array<string, list<int>>
     */
    abstract public function izin(): array;

    abstract public function alur(): AlurRiwayat;

    public function kolomNip(): string
    {
        return 'nip';
    }

    public function kolomStatus(): string
    {
        return 'status';
    }

    /**
     * Pemetaan domain status: nilai kolom status => StatusRiwayat. Default = nilai StatusRiwayat yang berlaku
     * (0/1/2/10, plus 3 bila flag status Diproses aktif). Override bila tabel legacy memakai nilai lain.
     *
     * WAJIB override tanpa 3 untuk tabel yang CHECK status-nya `IN (0, 1, 2, 10)` di DDL main: `riwayat_ak`,
     * `riwayat_ak_siasn`, `riwayat_alamat`, `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_skp_periodik` — kalau
     * tidak, flag status Diproses aktif membuat tulis status 3 ditolak DB.
     *
     * @return array<int, StatusRiwayat>
     */
    public function pemetaanStatus(): array
    {
        $peta = [];

        foreach (StatusRiwayat::berlaku() as $status) {
            $peta[$status->value] = $status;
        }

        return $peta;
    }

    /**
     * Baris berstatus Disetujui terkunci untuk UL_PEGAWAI (role 2/6/7): pegawai tidak bisa mengubah/menghapus baris
     * yang sudah disetujui; admin yang berizin tetap bisa. Override false bila jenis ini membolehkannya.
     */
    public function kunciBarisDisetujui(): bool
    {
        return true;
    }

    /**
     * Aturan snapshot `pegawai_*` (boleh lebih dari satu target; kosong = jenis tanpa snapshot).
     *
     * @return list<AturanSnapshot>
     */
    public function snapshot(): array
    {
        return [];
    }

    /**
     * Aturan lampiran, SATU entri per kode `jenis_rwy` (kolom `document_attachment.id_riwayat`); [] = jenis tanpa
     * lampiran. Satu tabel riwayat bisa memakai beberapa kode, mis. `riwayat_pendidikan` = 14 pendidikan,
     * 39 pencantuman_gelar, 40 transkrip_nilai; `riwayat_mutasi_jabatan` = 9 jabatan, 36 jabatan_pjft,
     * 41 perjanjian_kerja (seed `jenis_rwy` di main). Satu kode hanya boleh dimiliki satu Definisi (RiwayatRegistry
     * menolak kode ganda).
     *
     * @return list<AturanLampiran>
     */
    public function lampiran(): array
    {
        return [];
    }

    /**
     * Urutan tab di Detail Pegawai (kecil lebih dulu; seri diurutkan slug).
     */
    public function urutanTab(): int
    {
        return 100;
    }

    /**
     * Hook validasi tambahan setelah validasi field. $lama = baris sebelum diubah (null saat tambah).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, list<string>> error per field (kosong = lolos) → 422
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        return [];
    }

    /**
     * Hook sebelum simpan (mis. mengisi kolom teks turunan dari master). Dipanggil di dalam transaksi engine.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, mixed>
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        return $data;
    }

    /**
     * Hook setelah baris disetujui (approval final), di dalam transaksi engine, setelah SnapshotSync.
     *
     * @param array<string, mixed> $baris
     */
    public function afterApprove(array $baris, AuthContext $auth): void
    {
    }

    /**
     * Role $role punya izin $aksi pada jenis ini (tanpa memeriksa lingkup pegawai).
     */
    final public function boleh(AksiRiwayat $aksi, ?int $role): bool
    {
        if ($role === null || ! Role::isValid($role)) {
            return false;
        }

        return in_array($role, $this->izin()[$aksi->value] ?? [], true);
    }
}
