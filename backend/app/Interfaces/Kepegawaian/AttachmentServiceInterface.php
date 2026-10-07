<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Lampiran riwayat B-18 (`document_attachment`; kontrak beku S0-A MAKE-002; implementasi nyata milik WS-2).
 *
 * $idRiwayat = kode `jenis_rwy.id_jenis_rwy` (kolom `document_attachment.id_riwayat`), $idEntri = PK baris riwayat
 * (kolom `id_entri`). simpan()/hapus() dipanggil DI DALAM transaksi pemanggil (tidak membuka/commit transaksi sendiri);
 * berkas yang sudah ditulis dikompensasi (dihapus) bila transaksi pemanggil rollback.
 */
interface AttachmentServiceInterface
{
    /**
     * Validasi berkas terhadap $aturan (ekstensi, batas MB per jenis — 422 bila gagal), tulis lewat StorageAdapter,
     * dan sisipkan baris `document_attachment`.
     *
     * @return array<string, mixed> baris `document_attachment` (snake_case = kolom DDL)
     */
    public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array;

    /**
     * Hapus keras baris + berkas, dengan audit.
     */
    public function hapus(int $idAttachment): void;

    /**
     * @return list<array<string, mixed>> baris `document_attachment`
     */
    public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array;
}
