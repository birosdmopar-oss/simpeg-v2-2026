<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Lampiran riwayat B-18 (`document_attachment`; kontrak beku S0-A MAKE-002; implementasi nyata milik WS-2).
 *
 * $idRiwayat = kode `jenis_rwy.id_jenis_rwy` (kolom `document_attachment.id_riwayat`), $idEntri = PK baris riwayat
 * (kolom `id_entri`). Setiap lampiran terikat ke NIP pemiliknya: method yang menerima $idAttachment melempar
 * NotFoundException (404) bila lampiran itu bukan milik $nip — tidak pernah memproses lampiran NIP lain (IDOR).
 *
 * Otorisasi (WAJIB, ditegakkan pemanggil — endpoint `pegawai/{nip}/lampiran*` dan RiwayatEngine — sebelum memanggil
 * service ini; urutan sama dengan endpoint riwayat, README kontrak):
 *  1. `id_riwayat` dipetakan ke Definisi pemilik lewat RiwayatRegistryInterface::definisiUntukLampiran(); kode yang
 *     tidak dimiliki jenis terdaftar → 404.
 *  2. Izin Definisi × role: GET/unduh = `boleh(Lihat)`; POST = `boleh(Tambah)` atau `boleh(Ubah)`; DELETE =
 *     `boleh(Hapus)` atau `boleh(Ubah)` → 403 bila tidak.
 *  3. PegawaiScope: `bolehLihat` (baca) / `bolehUbah` (tulis) atas {nip} → 403.
 *  4. `id_entri` wajib baris milik {nip} di `tabel()` Definisi itu (`WHERE <pk> = id_entri AND <kolomNip> = nip`) → 404.
 *
 * simpan()/hapus() dipanggil DI DALAM transaksi pemanggil (tidak membuka/commit transaksi sendiri); berkas yang sudah
 * ditulis dikompensasi (dihapus) bila transaksi pemanggil rollback.
 *
 * Baris yang dikembalikan = kolom DDL `document_attachment` apa adanya, termasuk kunci `NIP` huruf besar (pengecualian
 * eksplisit aturan snake_case: nama kolom DDL-nya memang `NIP`). Error 422 memakai kunci field request `berkas`.
 */
interface AttachmentServiceInterface
{
    /**
     * Validasi berkas terhadap $aturan, tulis lewat StorageAdapter, dan sisipkan baris `document_attachment`.
     *
     * - Ekstensi/MIME divalidasi dari ISI berkas (finfo / `UploadedFile::guessExtension()`), bukan dari nama kiriman
     *   klien; ukuran > `AturanLampiran::batasByte()` atau jenis tidak diizinkan → ValidationException 422
     *   (`errors.berkas`).
     * - Nama & path simpan dibangkitkan server; nama asli klien hanya metadata (`display_name`).
     *
     * @return array<string, mixed> baris `document_attachment`
     */
    public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array;

    /**
     * Satu lampiran milik $nip untuk diunduh: `lampiran` = baris `document_attachment`, `isi` = isi berkas.
     *
     * @return array{lampiran: array<string, mixed>, isi: string}
     *
     * @throws \App\Exceptions\NotFoundException bila tidak ada atau bukan milik $nip
     */
    public function ambil(string $nip, int $idAttachment): array;

    /**
     * Hapus keras baris + berkas lampiran milik $nip, dengan audit.
     *
     * @throws \App\Exceptions\NotFoundException bila tidak ada atau bukan milik $nip
     */
    public function hapus(string $nip, int $idAttachment): void;

    /**
     * @return list<array<string, mixed>> baris `document_attachment` milik $nip untuk kode & entri itu
     */
    public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array;
}
