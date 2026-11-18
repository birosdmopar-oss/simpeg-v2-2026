<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg;

use App\Models\BaseAuditableModel;

/**
 * Tiket Halo Simpeg (DBV-022, MAKE-071). Tulis lewat model → `audit_logs` (snapshot `before` saat hapus, F8-09). Waktu UTC diisi service.
 */
final class DisiplinTicketModel extends BaseAuditableModel
{
    protected $table         = 'disiplin_ticket';
    protected $primaryKey    = 'id_inbox';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_question_category', 'subject', 'sender', 'sender_name', 'nip', 'jabatan', 'satker', 'receiver', 'receiver_name', 'allow_reply', 'status', 'is_read', 'is_read_off', 'notify_pic', 'notify_at', 'created_at', 'updated_at', 'closed_by'];
}
