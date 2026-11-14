<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg\Chatbot;

use App\Models\BaseAuditableModel;

/**
 * Tag QnA chatbot (DBV-033, MAKE-094). Tulis lewat model → `audit_logs` (PRD Fase 8 §7.10.4). Kolom bangkitan `judul_unik` tidak
 * pernah ditulis (bukan allowedFields, catatan DBV N4). Waktu UTC diisi service.
 */
final class QnaTagModel extends BaseAuditableModel
{
    protected $table         = 'qna_tag';
    protected $primaryKey    = 'id_qna_tag';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['judul_tag', 'status_qna_tag_fk', 'created_at', 'updated_at'];
}
