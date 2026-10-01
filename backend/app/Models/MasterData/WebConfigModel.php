<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Model `web_config` (G-09, DBV-006/CR-030). Bukan master engine (tidak terdaftar di Config\MasterData: tanpa
 * `order`/`status`, key dari katalog Config\WebConfig), tetapi memakai MasterModel supaya perilaku tulisnya sama:
 * audit_logs create/update/delete (before/after), waktu `updated_at` dari aplikasi (UTC), dan `updated_by` diisi saat
 * tambah MAUPUN ubah seperti legacy `Lm_web::set_param_config` (`Lm_web.php:204-210`; tabel tanpa `created_*`).
 * Tulisan gagal (mis. 1062 UNIQUE `config_name`) melempar DatabaseException berkode error MySQL/MariaDB.
 *
 * Hapus = hard delete baris (nilai kembali ke bawaan katalog), dicatat audit 'delete' dengan before lengkap
 * (G-09 Bagian 4 #6).
 */
class WebConfigModel extends MasterModel
{
    public function __construct(?ConnectionInterface $db = null)
    {
        parent::__construct(self::webConfigDefinition(), $db);
    }

    public static function webConfigDefinition(): MasterDefinition
    {
        return new MasterDefinition(
            key: 'web-config',
            label: 'Web Config',
            controller: 'WebConfigController',
            table: 'web_config',
            primaryKey: 'id_web_config',
            idMaxLength: 11,
            nameField: 'config_name',
            nameLabel: 'Nama Config',
            nameMaxLength: 255,
            autoIncrement: true,
            hasOrder: false,
            hasStatus: false,
            fields: [
                new MasterField('config_value', 'Nilai', MasterField::TYPE_TEXTAREA, true, maxBytes: 65535),
                new MasterField('remark', 'Keterangan', MasterField::TYPE_TEXT),
            ],
            auditColumns: [MasterDefinition::AUDIT_UPDATED_AT, MasterDefinition::AUDIT_UPDATED_BY],
        );
    }
}
