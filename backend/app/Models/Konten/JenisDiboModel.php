<?php

declare(strict_types=1);

namespace App\Models\Konten;

use App\Models\BaseAuditableModel;

/**
 * Model `jenis_dibo` (F-12 MAKE-082, Q7-11; DBV-032 §3.8). Audit otomatis (Q7-16).
 */
final class JenisDiboModel extends BaseAuditableModel
{
    protected $table         = 'jenis_dibo';
    protected $primaryKey    = 'id_jenis_dibo';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'jenis_dibo', 'order', 'status',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ];
}
