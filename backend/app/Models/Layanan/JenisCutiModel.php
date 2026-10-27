<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Master `jenis_cuti` (id 1..6, DBV-022). Hanya dibaca modul Layanan; pengelolaan master tidak termasuk Fase 4.
 */
class JenisCutiModel extends Model
{
    protected $table         = 'jenis_cuti';
    protected $primaryKey    = 'id_jenis_cuti';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [];
}
