<?php

declare(strict_types=1);

namespace App\Filters;

use App\Constants\Role;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * RoleFilter (F0-08) — cek role dari claims JWT terhadap daftar role yang diizinkan per route.
 * Dipakai deklaratif di routing, SELALU setelah 'jwt': ['filter' => ['jwt', Role::filter(1, 3)]].
 * Scoping data (mis. Admin Satker hanya satkernya) BUKAN di sini, tapi di Service (ADR-005).
 */
class RoleFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments daftar kode role, contoh ['1', '3']
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $auth = service('authContext');

        if (! $auth->isAuthenticated()) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['status' => 'error', 'message' => 'Unauthorized']);
        }

        $allowed = array_map('intval', array_filter((array) ($arguments ?? []), 'is_numeric'));
        $role    = $auth->role();

        if ($allowed === [] || $role === null || ! Role::isValid($role) || ! in_array($role, $allowed, true)) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['status' => 'error', 'message' => 'Forbidden']);
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
