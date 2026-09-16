<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Endpoint dummy untuk verifikasi RBAC (F0-08). Tidak di-route di production.
 * Seluruh otorisasi ditegakkan oleh filter jwt+role di Config\Routes — controller ini hanya echo.
 */
class RbacProbeController extends ApiController
{
    public function any(): ResponseInterface
    {
        return $this->probe('any');
    }

    public function adminCrud(): ResponseInterface
    {
        return $this->probe('admin-crud');
    }

    public function adminView(): ResponseInterface
    {
        return $this->probe('admin-view');
    }

    public function selfService(): ResponseInterface
    {
        return $this->probe('self-service');
    }

    public function leadership(): ResponseInterface
    {
        return $this->probe('leadership');
    }

    public function superAdmin(): ResponseInterface
    {
        return $this->probe('super-admin');
    }

    private function probe(string $pattern): ResponseInterface
    {
        $auth = service('authContext');

        return $this->respondSuccess([
            'pattern' => $pattern,
            'nip'     => $auth->nip(),
            'role'    => $auth->role(),
        ]);
    }
}
