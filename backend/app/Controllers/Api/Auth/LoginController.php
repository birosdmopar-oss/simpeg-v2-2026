<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A-02 / A-03 / A-04 — POST api/v1/auth/login (Publik/Guest).
 *
 * Payload : { username: string, password: string, captcha_token: string }
 * 200     : { status:'success', data:{ user, access_token, access_expires_at, refresh_expires_at } }
 *           + Set-Cookie access_token & refresh_token (httpOnly)
 * 422     : captcha kosong/invalid (sebelum kredensial dicek) atau field wajib kosong
 * 423     : akun terkunci sementara (lockout)
 * 401     : username/password salah (pesan generik)
 */
class LoginController extends ApiController
{
    public function login(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'username'      => 'required|string|max_length[30]',
            'password'      => 'required|string',
            'captcha_token' => 'permit_empty|string',
        ]);

        $result = service('authService')->login(
            (string) $data['username'],
            (string) $data['password'],
            (string) ($data['captcha_token'] ?? ''),
            $this->request->getIPAddress(),
        );

        $jwt = service('jwt');
        $this->response->setCookie($jwt->accessCookie($result['tokens']['access_token']));
        $this->response->setCookie($jwt->refreshCookie($result['tokens']['refresh_token']));

        return $this->respondSuccess([
            'user'               => PenggunaModel::toPublic($result['user']),
            'access_token'       => $result['tokens']['access_token'],
            'access_expires_at'  => $result['tokens']['access_expires_at'],
            'refresh_expires_at' => $result['tokens']['refresh_expires_at'],
        ]);
    }
}
