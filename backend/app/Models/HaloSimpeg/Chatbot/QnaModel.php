<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg\Chatbot;

use App\Models\BaseAuditableModel;

/**
 * QnA chatbot (DBV-033, MAKE-094). Tulis lewat model → `audit_logs` (PRD Fase 8 §7.10.4); relasi tag ditulis QnaService/ImporQna
 * (audit manual entitas `qna_has_tag`). Waktu UTC diisi service.
 */
final class QnaModel extends BaseAuditableModel
{
    protected $table         = 'qna';
    protected $primaryKey    = 'id_qna';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['pertanyaan', 'jawaban', 'id_qna_kategori_fk', 'status_qna_fk', 'is_fallback_option', 'created_at', 'updated_at'];
}
