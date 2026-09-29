<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A-08 — CRUD akun pengguna, scoped (filter jwt + role:1,3; scoping satker di UserService).
 *
 * GET    api/v1/auth/users?search=&user_level=&status=&id_satker=&sort=&order=&page=&per_page=
 * POST   api/v1/auth/users              { nip?, name?, email?, username?, password, user_level, id_unit?, id_satker?, status? }  → 201
 * GET    api/v1/auth/users/{id}
 * PUT    api/v1/auth/users/{id}         { nip?, name?, email?, username?, user_level?, id_unit?, id_satker?, status?, password? }
 *
 * Aturan akun (DBV-010/CR-013, detail di UserService): NIP wajib untuk role 2/6/7, opsional untuk role 1/3/4/5/8;
 * akun tanpa NIP wajib nama; username default = NIP. PUT `nip` hanya untuk mengisi NIP akun yang belum punya NIP.
 * PATCH  api/v1/auth/users/{id}/status  { status: '0'|'1' }   (nonaktifkan/aktifkan; sesi akun dicabut)
 * DELETE api/v1/auth/users/{id}         soft delete + sesi dicabut
 *
 * Role 1: seluruh akun. Role 3: hanya akun dengan id_satker miliknya (di luar itu → 403). Role lain → 403.
 * Setiap field body hanya boleh null/teks/angka bulat: array/objek/boolean → 422 "Isian harus berupa teks." per field
 * (validateTextOrFail, ISSUE-023/CR-019). Email yang dipakai akun lain → 422 errors.email (syarat DBV-010 D-6, detail di
 * UserService).
 */
class UserController extends ApiController
{
    public function index(): ResponseInterface
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->request->getGet();

        return $this->respondSuccess(service('userService')->list(service('authContext'), $filters));
    }

    public function show(int $id): ResponseInterface
    {
        return $this->respondSuccess(service('userService')->get(service('authContext'), $id));
    }

    public function create(): ResponseInterface
    {
        $data = $this->validateTextOrFail($this->payload(), [
            'nip'        => 'permit_empty|string',
            'name'       => 'permit_empty|string',
            'email'      => 'permit_empty|string',
            'username'   => 'permit_empty|string',
            'password'   => 'required|string',
            'user_level' => 'required|integer',
            'id_unit'    => 'permit_empty|string|max_length[10]',
            'id_satker'  => 'permit_empty|string|max_length[10]',
            'status'     => 'permit_empty|in_list[0,1]',
        ]);

        return $this->respondSuccess(service('userService')->create(service('authContext'), $data), 201);
    }

    public function update(int $id): ResponseInterface
    {
        $data = $this->validateTextOrFail($this->payload(), [
            'nip'        => 'permit_empty|string',
            'name'       => 'permit_empty|string',
            'email'      => 'permit_empty|string',
            'username'   => 'permit_empty|string',
            'user_level' => 'permit_empty|integer',
            'id_unit'    => 'permit_empty|string|max_length[10]',
            'id_satker'  => 'permit_empty|string|max_length[10]',
            'status'     => 'permit_empty|in_list[0,1]',
            'password'   => 'permit_empty|string',
        ]);

        return $this->respondSuccess(service('userService')->update(service('authContext'), $id, $data));
    }

    public function setStatus(int $id): ResponseInterface
    {
        $data = $this->validateTextOrFail($this->payload(), [
            'status' => 'required|in_list[0,1]',
        ]);

        return $this->respondSuccess(service('userService')->setStatus(service('authContext'), $id, (string) $data['status'] === '1'));
    }

    public function delete(int $id): ResponseInterface
    {
        service('userService')->delete(service('authContext'), $id);

        return $this->respondSuccess(['deleted' => true]);
    }
}
