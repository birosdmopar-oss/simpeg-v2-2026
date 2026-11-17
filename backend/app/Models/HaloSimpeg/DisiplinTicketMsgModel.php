<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg;

use App\Models\BaseAuditableModel;

/**
 * Pesan tiket Halo Simpeg (DBV-022, MAKE-071). Tulis lewat model → `audit_logs`. Teks disimpan apa adanya (BP8-4, tanpa addslashes legacy).
 */
final class DisiplinTicketMsgModel extends BaseAuditableModel
{
    protected $table         = 'disiplin_ticket_msg';
    protected $primaryKey    = 'id_inbox_msg';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_inbox', 'msg_sender', 'msg_sender_name', 'msg_receiver', 'msg_receiver_name', 'msg', 'attachments', 'show_notif', 'is_read', 'created_at'];
}
