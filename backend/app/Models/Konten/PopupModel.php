<?php

declare(strict_types=1);

namespace App\Models\Konten;

use App\Models\BaseAuditableModel;

/**
 * Model `popup` (F-04 MAKE-079; DBV-032 §3). Audit otomatis ke audit_logs (create/update/delete, Q7-16) lewat BaseAuditableModel;
 * semua tulis WAJIB lewat model ini. Waktu audit UTC diisi service (tanpa useTimestamps agar `created_at` DB tidak
 * ditimpa), `created_by`/`updated_by` = id_pengguna aktor.
 */
final class PopupModel extends BaseAuditableModel
{
    protected $table         = 'popup';
    protected $primaryKey    = 'id_popup';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'tgl_mulai', 'tgl_akhir', 'order', 'url', 'file', 'keterangan',
        'target_uns', 'target_peg', 'excl_peg',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ];
}
