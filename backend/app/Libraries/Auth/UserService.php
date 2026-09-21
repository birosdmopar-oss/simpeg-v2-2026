<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Auth\PenggunaModel;

/**
 * CRUD akun pengguna dengan scoping per satker (A-08, ADR-005: scoping di Service, bukan Filter).
 *
 * - Role 1 (Super Admin): seluruh akun.
 * - Role 3 (Admin Satker): hanya akun dengan id_satker = id_satker miliknya (dari claims JWT).
 *   Akun di luar satker → 403 (tidak muncul di daftar, tidak bisa dibaca/diubah/dihapus).
 *   Admin Satker juga tidak bisa membuat/mengubah akun menjadi role 1 (mencegah eskalasi hak; asumsi keamanan).
 * - Role lain: 403 di semua operasi (sudah dicegat RoleFilter; dicek ulang di sini sebagai lapis kedua).
 */
class UserService
{
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private PenggunaModel $pengguna,
        private PasswordVerifier $passwords,
        private JwtService $jwt,
    ) {
    }

    /**
     * @param array<string, mixed> $filters search, user_level, status, id_satker, sort, order, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(AuthContext $actor, array $filters = []): array
    {
        $this->assertAdmin($actor);

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($filters['per_page'] ?? 20)));

        $builder = $this->pengguna->builder()->where('deleted_at', null);

        if ($actor->role() === Role::ADMIN_SATKER) {
            $builder->where('id_satker', $actor->idSatker());
        } elseif (! empty($filters['id_satker'])) {
            $builder->where('id_satker', (string) $filters['id_satker']);
        }

        if (! empty($filters['search'])) {
            $s = (string) $filters['search'];
            $builder->groupStart()->like('username', $s)->orLike('nip', $s)->groupEnd();
        }

        if (isset($filters['user_level']) && $filters['user_level'] !== '') {
            $builder->where('user_level', (int) $filters['user_level']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $builder->where('status', (string) $filters['status']);
        }

        $total = (clone $builder)->countAllResults();

        $sortable = ['username', 'nip', 'user_level', 'status', 'created_at', 'last_login_at'];
        $sort     = in_array($filters['sort'] ?? '', $sortable, true) ? (string) $filters['sort'] : 'username';
        $order    = strtolower((string) ($filters['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy($sort, $order)->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'    => array_map([PenggunaModel::class, 'toPublic'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(AuthContext $actor, int $id): array
    {
        $this->assertAdmin($actor);

        return PenggunaModel::toPublic($this->findInScope($actor, $id));
    }

    /**
     * @param array<string, mixed> $data nip, username?, password, user_level, id_unit?, id_satker?, status?
     *
     * @return array<string, mixed>
     */
    public function create(AuthContext $actor, array $data): array
    {
        $this->assertAdmin($actor);

        $nip      = trim((string) ($data['nip'] ?? ''));
        $username = trim((string) ($data['username'] ?? '')) ?: $nip;
        $level    = (int) ($data['user_level'] ?? 0);
        $errors   = [];

        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            $errors['nip'][] = 'NIP harus 18 digit angka.';
        }

        if ($username === '' || mb_strlen($username) > 30) {
            $errors['username'][] = 'Username wajib diisi (maks. 30 karakter).';
        }

        if (! Role::isValid($level)) {
            $errors['user_level'][] = 'Role tidak valid (1-8).';
        }

        $password = (string) ($data['password'] ?? '');

        foreach ($this->passwords->policyErrors($password) as $msg) {
            $errors['password'][] = $msg;
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        // Scoping Admin Satker: akun baru wajib di satkernya sendiri, dan tidak boleh role Super Admin.
        if ($actor->role() === Role::ADMIN_SATKER) {
            if (! empty($data['id_satker']) && (string) $data['id_satker'] !== $actor->idSatker()) {
                throw new ForbiddenException('Admin Satker hanya dapat membuat akun di satkernya sendiri.');
            }
            $data['id_satker'] = $actor->idSatker();
            $data['id_unit']   = $data['id_unit'] ?? $actor->idUnit();

            if ($level === Role::SUPER_ADMIN) {
                throw new ForbiddenException('Admin Satker tidak dapat membuat akun Super Admin.');
            }
        }

        if ($this->pengguna->withDeleted()->where('nip', $nip)->countAllResults() > 0) {
            throw new ValidationException('Validasi gagal.', ['nip' => ['NIP sudah memiliki akun.']]);
        }

        if ($this->pengguna->withDeleted()->where('username', $username)->countAllResults() > 0) {
            throw new ValidationException('Validasi gagal.', ['username' => ['Username sudah dipakai.']]);
        }

        $id = $this->pengguna->insert([
            'nip'                 => $nip,
            'username'            => $username,
            'password'            => $this->passwords->hash($password),
            'password_legacy'     => null,
            'user_level'          => $level,
            'id_unit'             => $data['id_unit'] ?? null,
            'id_satker'           => $data['id_satker'] ?? null,
            'status'              => (string) ($data['status'] ?? PenggunaModel::STATUS_ACTIVE) === PenggunaModel::STATUS_INACTIVE ? PenggunaModel::STATUS_INACTIVE : PenggunaModel::STATUS_ACTIVE,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        return PenggunaModel::toPublic($this->pengguna->find((int) $id));
    }

    /**
     * @param array<string, mixed> $data username?, user_level?, id_unit?, id_satker?, status?, password?
     *
     * @return array<string, mixed>
     */
    public function update(AuthContext $actor, int $id, array $data): array
    {
        $this->assertAdmin($actor);
        $row    = $this->findInScope($actor, $id);
        $update = [];
        $errors = [];

        if (array_key_exists('username', $data)) {
            $username = trim((string) $data['username']);

            if ($username === '' || mb_strlen($username) > 30) {
                $errors['username'][] = 'Username wajib diisi (maks. 30 karakter).';
            } elseif ($this->pengguna->withDeleted()->where('username', $username)->where('id_pengguna !=', $id)->countAllResults() > 0) {
                $errors['username'][] = 'Username sudah dipakai.';
            } else {
                $update['username'] = $username;
            }
        }

        if (array_key_exists('user_level', $data)) {
            $level = (int) $data['user_level'];

            if (! Role::isValid($level)) {
                $errors['user_level'][] = 'Role tidak valid (1-8).';
            } elseif ($actor->role() === Role::ADMIN_SATKER && $level === Role::SUPER_ADMIN) {
                throw new ForbiddenException('Admin Satker tidak dapat memberikan role Super Admin.');
            } else {
                $update['user_level'] = $level;
            }
        }

        if (array_key_exists('status', $data)) {
            $update['status'] = (string) $data['status'] === PenggunaModel::STATUS_INACTIVE ? PenggunaModel::STATUS_INACTIVE : PenggunaModel::STATUS_ACTIVE;
        }

        if (array_key_exists('id_unit', $data)) {
            $update['id_unit'] = $data['id_unit'] === '' ? null : $data['id_unit'];
        }

        if (array_key_exists('id_satker', $data)) {
            if ($actor->role() === Role::ADMIN_SATKER && (string) $data['id_satker'] !== $actor->idSatker()) {
                throw new ForbiddenException('Admin Satker tidak dapat memindahkan akun ke satker lain.');
            }
            $update['id_satker'] = $data['id_satker'] === '' ? null : $data['id_satker'];
        }

        if (! empty($data['password'])) {
            $password = (string) $data['password'];

            foreach ($this->passwords->policyErrors($password) as $msg) {
                $errors['password'][] = $msg;
            }

            if (! isset($errors['password'])) {
                $update['password']            = $this->passwords->hash($password);
                $update['password_legacy']     = null;
                $update['password_changed_at'] = date('Y-m-d H:i:s');
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        if ($update !== []) {
            $this->pengguna->update($id, $update);
        }

        // Perubahan role/status/password/satker langsung berlaku: cabut sesi lama akun tsb (MTC-004).
        if (isset($update['user_level']) || isset($update['status']) || isset($update['password']) || array_key_exists('id_satker', $update)) {
            $this->jwt->revokeAllForNip((string) $row['nip']);
        }

        return PenggunaModel::toPublic($this->pengguna->find($id));
    }

    /**
     * Nonaktifkan (status 0) — akun tidak bisa login lagi, sesi dicabut.
     *
     * @return array<string, mixed>
     */
    public function setStatus(AuthContext $actor, int $id, bool $active): array
    {
        return $this->update($actor, $id, ['status' => $active ? PenggunaModel::STATUS_ACTIVE : PenggunaModel::STATUS_INACTIVE]);
    }

    /**
     * Hapus akun (soft delete) + cabut seluruh sesi.
     */
    public function delete(AuthContext $actor, int $id): void
    {
        $this->assertAdmin($actor);
        $row = $this->findInScope($actor, $id);

        if ((string) $row['nip'] === $actor->nip()) {
            throw new ValidationException('Tidak dapat menghapus akun sendiri.', ['id' => ['Tidak dapat menghapus akun sendiri.']]);
        }

        $this->pengguna->delete($id);
        $this->jwt->revokeAllForNip((string) $row['nip']);
    }

    // ------------------------------------------------------------------

    private function assertAdmin(AuthContext $actor): void
    {
        if (! in_array($actor->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException();
        }

        if ($actor->role() === Role::ADMIN_SATKER && ($actor->idSatker() === null || $actor->idSatker() === '')) {
            throw new ForbiddenException('Admin Satker tanpa id_satker tidak dapat mengelola akun.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findInScope(AuthContext $actor, int $id): array
    {
        $row = $this->pengguna->find($id);

        if ($row === null) {
            throw new NotFoundException('Akun tidak ditemukan.');
        }

        if ($actor->role() === Role::ADMIN_SATKER && (string) $row['id_satker'] !== $actor->idSatker()) {
            // Di luar scope satker → 403 (bukan 404) sesuai DoD A-08.
            throw new ForbiddenException('Akun berada di luar satker Anda.');
        }

        return $row;
    }
}
