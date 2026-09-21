<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A-06 — POST api/v1/auth/change-password (filter jwt; UL_ALL).
 *
 * Payload : { old_password, new_password, new_password_confirmation }
 * 200     : { status:'success', data:{ changed:true, sessions_revoked:true } } — seluruh refresh token dicabut,
 *           klien harus login ulang (cookie sesi ini ikut dihapus).
 * 422     : password lama salah / kebijakan password baru / konfirmasi tidak cocok
 */
class PasswordController extends ApiController
{
    public function change(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'old_password'              => 'required|string',
            'new_password'              => 'required|string',
            'new_password_confirmation' => 'required|string',
        ]);

        $auth = service('authContext');

        service('passwordService')->change(
            (string) $auth->nip(),
            (string) $data['old_password'],
            (string) $data['new_password'],
            (string) $data['new_password_confirmation'],
        );

        foreach (service('jwt')->expiredCookies() as $cookie) {
            $this->response->setCookie($cookie);
        }

        return $this->respondSuccess(['changed' => true, 'sessions_revoked' => true]);
    }
}
