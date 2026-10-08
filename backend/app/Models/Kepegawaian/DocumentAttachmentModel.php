<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `document_attachment` (B-02 DBV-013; dipakai B-18 MAKE-009). Tulis HANYA lewat model ini (insert/delete) agar
 * audit otomatis tercatat: unggah = create, hapus keras = delete (DBV #9, tanpa soft delete).
 *
 * Kolom `NIP` memang huruf besar di DDL. `path` = path relatif di StorageAdapter, internal (tidak pernah dikirim ke
 * klien). `created_at`/`updated_at` diisi aplikasi (UTC).
 */
class DocumentAttachmentModel extends BaseAuditableModel
{
    protected $table          = 'document_attachment';
    protected $primaryKey     = 'id_attachment';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $allowedFields  = [
        'NIP', 'document_id', 'filename', 'id_riwayat', 'nama_riwayat', 'id_entri', 'tag', 'path', 'url', 'basename',
        'display_name', 'file_size', 'file_ext', 'file_type',
    ];
}
