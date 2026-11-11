<?php

declare(strict_types=1);

namespace App\Models\Konten;

use App\Models\BaseAuditableModel;

/**
 * Model `news` (F-07 MAKE-081; DBV-032 §3.6). Audit otomatis (Q7-16). Waktu UTC dan `created_by`/`updated_by` diisi service.
 */
final class NewsModel extends BaseAuditableModel
{
    protected $table         = 'news';
    protected $primaryKey    = 'id_news';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'id_news_category', 'title', 'news', 'lampiran', 'publish_at', 'status', 'show_notif', 'notif_date',
        'target', 'id_unit_target', 'id_satker_target', 'ul_creator', 'id_unit_creator', 'id_satker_creator',
        'approved_at', 'approved_by', 'reason_note',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ];
}
