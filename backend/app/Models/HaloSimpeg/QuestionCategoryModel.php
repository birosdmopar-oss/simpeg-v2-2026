<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg;

use App\Models\BaseAuditableModel;

/**
 * Kategori pertanyaan helpdesk (DBV-022, dipakai ulang Fase 8 E-02, MAKE-070). Tulis lewat model → `audit_logs` (AC-E02.6). Status 0/1
 * (CHECK DBV-022 DQ-5). `created_at` ber-DEFAULT DB dan `updated_at` ber-`ON UPDATE` di DBV-022; service tetap mengisi UTC.
 */
final class QuestionCategoryModel extends BaseAuditableModel
{
    protected $table         = 'question_category';
    protected $primaryKey    = 'id_question_category';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['question_category', 'status', 'created_at', 'updated_at'];
}
