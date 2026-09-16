<?php

declare(strict_types=1);

namespace App\Filters;

use App\Exceptions\AuthException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Jwt as JwtConfig;

/**
 * JwtAuthFilter (F0-06) — validasi access token, 401 kalau tidak ada/invalid/expired.
 * Sumber token: cookie httpOnly (utama, ADR-004) atau header Authorization: Bearer (fallback API client).
 * Claims yang valid disimpan ke service('authContext') untuk dipakai RoleFilter/Service/Model.
 */
class JwtAuthFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->unauthorized();
        }

        try {
            $claims = service('jwt')->verifyAccessToken($token);
        } catch (AuthException) {
            return $this->unauthorized();
        }

        service('authContext')->setClaims($claims);

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }

    private function extractToken(RequestInterface $request): ?string
    {
        if ($request instanceof IncomingRequest) {
            $cookie = $request->getCookie(config(JwtConfig::class)->accessCookie);

            if (is_string($cookie) && $cookie !== '') {
                return $cookie;
            }
        }

        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function unauthorized(): ResponseInterface
    {
        // Pesan generik — tidak membocorkan alasan detail (Tech Spec Bagian 9).
        return service('response')
            ->setStatusCode(401)
            ->setJSON(['status' => 'error', 'message' => 'Unauthorized']);
    }
}
