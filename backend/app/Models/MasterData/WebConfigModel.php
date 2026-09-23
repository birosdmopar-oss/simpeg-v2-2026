<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;

/**
 * Tabel web_config (G-09) — parameter sistem key-value bertipe (tarif tukin, uang makan, jam kerja, header PDF).
 * Turunan BaseAuditableModel: perubahan nilai parameter tercatat di audit_logs (nilai lama & baru).
 */
class WebConfigModel extends BaseAuditableModel
{
    public const TYPE_STRING  = 'string';
    public const TYPE_TEXT    = 'text';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_TIME    = 'time';
    public const TYPE_BOOLEAN = 'boolean';

    /**
     * @var list<string>
     */
    public const TYPES = [self::TYPE_STRING, self::TYPE_TEXT, self::TYPE_INTEGER, self::TYPE_DECIMAL, self::TYPE_TIME, self::TYPE_BOOLEAN];

    protected $table         = 'web_config';
    protected $primaryKey    = 'id_web_config';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['config_name', 'config_value', 'tipe_data', 'keterangan'];

    /**
     * Hard delete config dicatat sebagai event delete (row benar-benar hilang: web_config tidak punya status).
     */
    public function deleteConfig(int $id): void
    {
        $before = $this->fetchRows([$id])[0] ?? null;

        $this->auditEnabled = false;

        try {
            $this->delete($id);
        } finally {
            $this->auditEnabled = true;
        }

        $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, null);
    }
}
