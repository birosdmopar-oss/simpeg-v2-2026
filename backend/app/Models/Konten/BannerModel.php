<?php

declare(strict_types=1);

namespace App\Models\Konten;

use App\Models\BaseAuditableModel;

/**
 * Model `banner` (F-05 MAKE-080; DBV-032 §3). Audit otomatis ke audit_logs (create/update/delete, Q7-16) lewat BaseAuditableModel;
 * semua tulis WAJIB lewat model ini. Waktu audit UTC diisi service (tanpa useTimestamps agar `created_at` DB tidak
 * ditimpa), `created_by`/`updated_by` = id_pengguna aktor.
 */
final class BannerModel extends BaseAuditableModel
{
    protected $table         = 'banner';
    protected $primaryKey    = 'id_banner';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'tgl_mulai', 'tgl_akhir', 'order', 'url', 'file', 'keterangan',
        'target_uns', 'target_peg', 'excl_peg',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ];
}
