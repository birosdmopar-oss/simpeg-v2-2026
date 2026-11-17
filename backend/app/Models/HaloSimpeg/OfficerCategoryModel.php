<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg;

use App\Models\BaseAuditableModel;

/**
 * Kategori yang ditugaskan ke petugas (DBV-028, MAKE-070). Set kategori diganti = hapus + sisip dalam satu transaksi di service
 * [K `LS:627-629`]; hapus memakai daftar id agar tiap baris tercatat di `audit_logs`.
 */
final class OfficerCategoryModel extends BaseAuditableModel
{
    protected $table         = 'officer_category';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_support_officer', 'id_question_category', 'created_at'];
}
