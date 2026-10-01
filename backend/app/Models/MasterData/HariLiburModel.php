<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Model `hari_libur` (G-08, DBV-003/CR-010). Bukan master engine (tidak terdaftar di Config\MasterData: unik =
 * `tgl_mulai`, urut = `tgl_mulai DESC`, tanpa `order`), tetapi memakai MasterModel supaya perilaku tulis sama dengan
 * master lain: audit_logs create/update/delete, soft delete status 10, waktu UTC dari aplikasi, dan `updated_by`
 * diisi saat tambah MAUPUN ubah seperti legacy `sp_holiday` (L_presensi.php:20553-20564; tabel tanpa `created_by`).
 * Tulisan gagal (1062, CHECK 3819/4025) melempar DatabaseException berkode error MySQL/MariaDB.
 */
class HariLiburModel extends MasterModel
{
    public function __construct(?ConnectionInterface $db = null)
    {
        parent::__construct(self::hariLiburDefinition(), $db);
    }

    /**
     * Definisi statis (kolom yang boleh ditulis + kolom audit); tidak dipakai registry/routing master.
     */
    public static function hariLiburDefinition(): MasterDefinition
    {
        return new MasterDefinition(
            key: 'hari-libur',
            label: 'Hari Libur',
            controller: 'HariLiburController',
            table: 'hari_libur',
            primaryKey: 'id_libur',
            idMaxLength: 11,
            nameField: 'nama_libur',
            nameLabel: 'Nama Libur',
            nameMaxLength: 100,
            autoIncrement: true,
            hasOrder: false,
            fields: [
                new MasterField('id_jenis_libur', 'Jenis Libur', MasterField::TYPE_REF, true, entity: 'jenis-libur'),
                new MasterField('tgl_mulai', 'Tanggal Mulai', MasterField::TYPE_DATE, true),
                new MasterField('tgl_akhir', 'Tanggal Selesai', MasterField::TYPE_DATE, true),
                new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 65535),
            ],
            auditColumns: [MasterDefinition::AUDIT_CREATED_AT, MasterDefinition::AUDIT_UPDATED_AT, MasterDefinition::AUDIT_UPDATED_BY],
        );
    }
}
