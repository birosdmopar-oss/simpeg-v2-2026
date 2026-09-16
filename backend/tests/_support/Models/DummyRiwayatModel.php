<?php

declare(strict_types=1);

namespace Tests\Support\Models;

use App\Models\BaseSnapshotModel;

/**
 * Model dummy turunan BaseSnapshotModel (F0-05): riwayat_dummy -> snapshot pegawai_dummy.
 */
class DummyRiwayatModel extends BaseSnapshotModel
{
    protected $table         = 'riwayat_dummy';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['nip', 'id_jabatan', 'tmt', 'status'];

    public function snapshotTable(): string
    {
        return 'pegawai_dummy';
    }

    public function snapshotKey(): string
    {
        return 'nip';
    }

    public function snapshotFields(): array
    {
        return ['id_jabatan', 'tmt' => 'tmt_jabatan'];
    }
}
