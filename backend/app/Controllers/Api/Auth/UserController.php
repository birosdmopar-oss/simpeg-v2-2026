<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A-08 — CRUD akun pengguna, scoped (filter jwt + role:1,3; scoping satker di UserService).
 *
 * GET    api/v1/auth/users?search=&user_level=&status=&id_satker=&sort=&order=&page=&per_page=
 * POST   api/v1/auth/users              { nip, username?, password, user_level, id_unit?, id_satker?, status? }  → 201
 * GET    api/v1/auth/users/{id}
 * PUT    api/v1/auth/users/{id}         { username?, user_level?, id_unit?, id_satker?, status?, password? }
 * PATCH  api/v1/auth/users/{id}/status  { status: '0'|'1' }   (nonaktifkan/aktifkan; sesi akun dicabut)
 * DELETE api/v1/auth/users/{id}         soft delete + sesi dicabut
 *
 * Role 1: seluruh akun. Role 3: hanya akun dengan id_satker miliknya (di luar itu → 403). Role lain → 403.
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
        $data = $this->validateOrFail($this->payload(), [
            'nip'        => 'required|string',
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
        $data = $this->validateOrFail($this->payload(), [
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
        $data = $this->validateOrFail($this->payload(), [
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
