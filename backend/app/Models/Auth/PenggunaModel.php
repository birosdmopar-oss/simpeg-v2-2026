<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\BaseAuditableModel;

/**
 * Tabel pengguna (A-01). Turunan BaseAuditableModel → seluruh create/update/delete akun otomatis
 * tercatat di audit_logs (A-10). Kolom hash password DIMASKING di JSON audit.
 */
class PenggunaModel extends BaseAuditableModel
{
    public const STATUS_ACTIVE   = '1';
    public const STATUS_INACTIVE = '0';

    protected $table          = 'pengguna';
    protected $primaryKey     = 'id_pengguna';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = [
        'nip', 'username', 'password', 'password_legacy', 'user_level', 'id_unit', 'id_satker',
        'status', 'last_login_at', 'password_changed_at',
    ];

    protected array $auditMaskedFields = ['password', 'password_legacy'];

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('username', $username)->first();

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNip(string $nip): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('nip', $nip)->first();

        return $row;
    }

    /**
     * Representasi aman untuk response API (tanpa hash password).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function toPublic(array $row): array
    {
        return [
            'id_pengguna'   => (int) $row['id_pengguna'],
            'nip'           => (string) $row['nip'],
            'username'      => (string) $row['username'],
            'user_level'    => (int) $row['user_level'],
            'id_unit'       => $row['id_unit'] ?? null,
            'id_satker'     => $row['id_satker'] ?? null,
            'status'        => (string) $row['status'],
            'last_login_at' => $row['last_login_at'] ?? null,
            'created_at'    => $row['created_at'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
        ];
    }
}
