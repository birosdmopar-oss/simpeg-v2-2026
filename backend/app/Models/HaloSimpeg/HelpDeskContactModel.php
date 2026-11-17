<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg;

use App\Models\BaseAuditableModel;

/**
 * Kontak helpdesk (DBV-028, E-07, MAKE-070). Tulis lewat model → `audit_logs` (AC-E02.6). Waktu UTC diisi service (BP8-8).
 */
final class HelpDeskContactModel extends BaseAuditableModel
{
    protected $table         = 'help_desk_contact';
    protected $primaryKey    = 'id_help_desk_contact';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['name', 'contact', 'email', 'status', 'created_at', 'updated_at'];
}
