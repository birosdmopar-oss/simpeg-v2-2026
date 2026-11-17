<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg;

use App\Models\BaseAuditableModel;

/**
 * Petugas Halo Simpeg (DBV-028, MAKE-070). Tulis lewat model → `audit_logs` (AC-E02.6). Waktu UTC diisi service (BP8-8).
 */
final class SupportOfficerModel extends BaseAuditableModel
{
    protected $table         = 'support_officer';
    protected $primaryKey    = 'id_support_officer';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_user', 'email', 'no_hp', 'status', 'created_at', 'updated_at'];
}
