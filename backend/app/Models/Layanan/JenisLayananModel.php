<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Master `jenis_layanan` (id 1..5, DBV-022). Hanya dibaca modul Layanan (`GET layanan/jenis`, validasi PIC); pengelolaan
 * master tidak termasuk Fase 4.
 */
class JenisLayananModel extends Model
{
    protected $table         = 'jenis_layanan';
    protected $primaryKey    = 'id_jenis_layanan';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [];

    /** `jenis_layanan.status` aktif (domain master 1/2/10). */
    public const STATUS_AKTIF = 1;

    /**
     * Jenis layanan aktif, urut id.
     *
     * @return list<array{id_jenis_layanan: int, jenis_layanan: string, module_name: string}>
     */
    public function aktif(): array
    {
        $rows = $this->builder()->select('id_jenis_layanan, jenis_layanan, module_name')
            ->where('status', self::STATUS_AKTIF)->orderBy('id_jenis_layanan')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['id_jenis_layanan' => (int) $r['id_jenis_layanan'], 'jenis_layanan' => (string) $r['jenis_layanan'], 'module_name' => (string) $r['module_name']], $rows);
    }
}
