<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use App\Exceptions\AuthException;
use App\Exceptions\NotFoundException;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Jwt as JwtConfig;

/**
 * A-05 — Refresh token & logout, plus profil sesi.
 *
 * POST api/v1/auth/refresh  (cookie refresh_token; tanpa access token)   → token pair baru (rotating)
 * POST api/v1/auth/logout   (filter jwt; cookie refresh_token)            → refresh token dihapus dari DB, cookie dihapus
 * GET  api/v1/auth/me       (filter jwt)                                  → akun yang sedang login (dipakai route guard FE)
 */
class TokenController extends ApiController
{
    public function refresh(): ResponseInterface
    {
        $refreshToken = $this->refreshTokenFromRequest();

        if ($refreshToken === null) {
            throw AuthException::missingToken();
        }

        $tokens = service('authService')->refresh($refreshToken);

        $jwt = service('jwt');
        $this->response->setCookie($jwt->accessCookie($tokens['access_token']));
        $this->response->setCookie($jwt->refreshCookie($tokens['refresh_token']));

        return $this->respondSuccess([
            'access_token'       => $tokens['access_token'],
            'access_expires_at'  => $tokens['access_expires_at'],
            'refresh_expires_at' => $tokens['refresh_expires_at'],
        ]);
    }

    public function logout(): ResponseInterface
    {
        $auth = service('authContext');

        service('authService')->logout($this->refreshTokenFromRequest(), $auth->nip(), $this->request->getIPAddress());

        foreach (service('jwt')->expiredCookies() as $cookie) {
            $this->response->setCookie($cookie);
        }

        $auth->clear();

        return $this->respondSuccess(['logged_out' => true]);
    }

    public function me(): ResponseInterface
    {
        $auth = service('authContext');
        $user = (new PenggunaModel())->findByNip((string) $auth->nip());

        if ($user === null) {
            throw new NotFoundException('Akun tidak ditemukan.');
        }

        return $this->respondSuccess([
            'user'   => PenggunaModel::toPublic($user),
            'claims' => [
                'role'      => $auth->role(),
                'id_unit'   => $auth->idUnit(),
                'id_satker' => $auth->idSatker(),
                'exp'       => $auth->claims()['exp'] ?? null,
            ],
        ]);
    }

    private function refreshTokenFromRequest(): ?string
    {
        $cookie = $this->request->getCookie(config(JwtConfig::class)->refreshCookie);

        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        // Fallback klien non-browser (mobile): body { refresh_token }
        $body = $this->payload();

        return isset($body['refresh_token']) && is_string($body['refresh_token']) && $body['refresh_token'] !== ''
            ? $body['refresh_token']
            : null;
    }
}
