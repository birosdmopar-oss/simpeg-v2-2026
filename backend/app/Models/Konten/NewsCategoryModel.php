<?php

declare(strict_types=1);

namespace App\Models\Konten;

use App\Models\BaseAuditableModel;

/**
 * Model `news_category` (F-07 MAKE-081; DBV-032 §3.5). Audit otomatis (Q7-16).
 */
final class NewsCategoryModel extends BaseAuditableModel
{
    protected $table         = 'news_category';
    protected $primaryKey    = 'id_news_category';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'category', 'order', 'allow_ap', 'keterangan', 'status',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ];
}
