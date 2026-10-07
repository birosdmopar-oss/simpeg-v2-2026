<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Mesin riwayat generik (RiwayatEngine, WS-1 M1 MAKE-004) di balik endpoint `api/v1/pegawai/{nip}/riwayat/{jenis}`
 * (kontrak beku S0-A MAKE-002). Payload & hasil snake_case = kolom DDL.
 *
 * Urutan pemeriksaan (wajib, sama untuk semua method):
 *  1. `{jenis}` terdaftar (RiwayatRegistry) → 404 bila tidak.
 *  2. Izin Definisi × role untuk aksinya (`RiwayatDefinisi::boleh()`) → 403.
 *  3. PegawaiScope (`bolehLihat` untuk baca, `bolehUbah` untuk tulis) atas {nip} → 403. Untuk pemanggil ber-lingkup,
 *     NIP yang tidak ada dan NIP di luar lingkup dijawab SAMA (403), tidak membocorkan keberadaan NIP.
 *  4. Keberadaan data: NIP (404 bagi pemanggil yang lingkupnya mencakup NIP itu), lalu baris dicari
 *     `WHERE <pk> = {id} AND <kolomNip> = {nip}` — baris milik NIP lain = 404 (bukan IDOR).
 *
 * Error dilempar sebagai App\Exceptions\*: NotFoundException 404, ForbiddenException 403, ValidationException 422
 * (errors per field). Tulis riwayat + snapshot + lampiran dalam SATU transaksi engine; approve/reject dicatat
 * `audit_logs` aksi `update` (before/after); waktu disimpan UTC.
 *
 * Lampiran ikut request tambah/ubah yang sama (`multipart/form-data`, field `berkas[<id_riwayat>]`; keputusan reviewer
 * CR 07-10-2026): $berkas = kode `jenis_rwy` => UploadedFile, disimpan lewat AttachmentServiceInterface di transaksi
 * engine. Kode yang bukan milik `RiwayatDefinisi::lampiran()` jenis itu → 422 `errors.berkas.<id_riwayat>`.
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
     * Setiap AturanLampiran ber-`wajib` milik jenis ini harus ada di $berkas → 422 `errors.berkas.<id_riwayat>`.
     *
     * @param array<string, mixed>      $data
     * @param array<int, UploadedFile>  $berkas kode jenis_rwy => berkas
     *
     * @return array<string, mixed> baris tersimpan
     */
    public function tambah(AuthContext $auth, string $nip, string $jenis, array $data, array $berkas = []): array;

    /**
     * Berkas di $berkas menambah lampiran kode itu pada baris ini. Setelah request, setiap AturanLampiran ber-`wajib`
     * harus punya minimal satu lampiran untuk baris ini (yang sudah ada atau yang baru) → 422 bila tidak.
     *
     * @param array<string, mixed>     $data
     * @param array<int, UploadedFile> $berkas kode jenis_rwy => berkas
     *
     * @return array<string, mixed> baris tersimpan
     */
    public function ubah(AuthContext $auth, string $nip, string $jenis, int $id, array $data, array $berkas = []): array;

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
