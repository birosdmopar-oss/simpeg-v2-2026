<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\BaseAuditableModel;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\ResultInterface;

/**
 * Tabel pengguna (A-01). Turunan BaseAuditableModel → seluruh create/update/delete akun otomatis
 * tercatat di audit_logs (A-10). Kolom hash password DIMASKING di JSON audit.
 *
 * DBV-010/CR-013 (K2): `nip` NULL untuk akun non-pegawai (role 1/3/4/5/8), kolom legacy `name`, `email`, `expired_at`.
 * Identitas akun = `id_pengguna`.
 */
class PenggunaModel extends BaseAuditableModel
{
    public const STATUS_ACTIVE   = '1';
    public const STATUS_INACTIVE = '0';

    /**
     * Panjang maksimum username (kolom VARCHAR(100), legacy — D-5).
     */
    public const USERNAME_MAX = 100;

    /**
     * NIP: angka saja, maksimal 18 digit (NIP PNS 18 digit, NIK Non-PNS 16 digit; kolom VARCHAR(30) ikut legacy).
     */
    public const NIP_MAX_DIGITS = 18;

    public const NAME_MAX  = 150;
    public const EMAIL_MAX = 150;

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
        'nip', 'username', 'name', 'email', 'password', 'password_legacy', 'user_level', 'id_unit', 'id_satker',
        'status', 'last_login_at', 'password_changed_at', 'expired_at',
    ];

    protected array $auditMaskedFields = ['password', 'password_legacy'];

    /**
     * Actor audit eksplisit untuk operasi tanpa sesi login (mis. reset password via token) — lihat withActor().
     *
     * @var array{id: int|null, nip: string|null}|null
     */
    private ?array $actorOverride = null;

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
     * Jalankan $work dengan pelaku audit = akun $user (id_pengguna + NIP-nya, NIP boleh NULL). Dipakai jalur tanpa JWT
     * (AuthContext kosong: login, lazy rehash, reset password) agar audit tidak tercatat dengan actor NULL
     * (DEV-002 Bagian 8 #4 / ISSUE-005, T-02).
     *
     * @template T
     *
     * @param array<string, mixed> $user  row pengguna (minimal id_pengguna, nip)
     * @param Closure(): T         $work
     *
     * @return T
     */
    public function withActor(array $user, Closure $work): mixed
    {
        $nip = $user['nip'] ?? null;

        $previous            = $this->actorOverride;
        $this->actorOverride = [
            'id'  => (int) $user['id_pengguna'],
            'nip' => $nip === null || $nip === '' ? null : (string) $nip,
        ];

        try {
            return $work();
        } finally {
            $this->actorOverride = $previous;
        }
    }

    /**
     * Kunci baris akun (`SELECT ... FOR UPDATE`) di dalam transaksi yang sedang berjalan. Dipakai sebagai langkah
     * pertama transaksi yang menulis beberapa tabel milik satu akun (reset password) agar urutan lock selalu sama
     * dan transaksi paralel untuk akun yang sama berjalan berurutan (DEV-002 Bagian 8 #2).
     *
     * @return bool false bila baris tidak ada
     *
     * @throws DatabaseException query gagal (lock wait timeout, deadlock, dll.); di dalam transaksi CI4 query gagal
     *                           tidak melempar exception sendiri
     */
    public function lockForUpdate(int $idPengguna): bool
    {
        $sql = $this->db->table($this->table)
            ->select($this->primaryKey)
            ->where($this->primaryKey, $idPengguna)
            ->getCompiledSelect() . ' FOR UPDATE';

        $query = $this->db->query($sql);

        if (! $query instanceof ResultInterface) {
            throw new DatabaseException('Gagal mengunci baris pengguna.');
        }

        return $query->getRowArray() !== null;
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
            'nip'           => isset($row['nip']) && $row['nip'] !== '' ? (string) $row['nip'] : null,
            'username'      => (string) $row['username'],
            'name'          => $row['name'] ?? null,
            'email'         => $row['email'] ?? null,
            'user_level'    => (int) $row['user_level'],
            'id_unit'       => $row['id_unit'] ?? null,
            'id_satker'     => $row['id_satker'] ?? null,
            'status'        => (string) $row['status'],
            'last_login_at' => $row['last_login_at'] ?? null,
            'created_at'    => $row['created_at'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @return array{id: int|null, nip: string|null}
     */
    protected function currentActor(): array
    {
        return $this->actorOverride ?? parent::currentActor();
    }
}
