<?php

declare(strict_types=1);

namespace Tests\Support\Models;

use App\Models\BaseAuditableModel;

/**
 * Model dummy turunan BaseAuditableModel (F0-04) — hard delete.
 */
class DummyAuditableModel extends BaseAuditableModel
{
    protected $table         = 'dummy_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['name', 'qty', 'deleted_at'];
}
