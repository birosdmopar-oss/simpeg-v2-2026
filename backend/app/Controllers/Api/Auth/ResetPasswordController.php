<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A-07 — Lupa / reset password (Publik/Guest).
 *
 * POST api/v1/auth/forgot-password  { username }
 *   200 selalu generik: { accepted:true } (+ token/expires_at HANYA kalau auth.exposeResetTokenInResponse=true, development)
 *   429 kalau melebihi rate limit forgot_attempts
 * POST api/v1/auth/reset-password   { token, new_password, new_password_confirmation }
 *   200 { reset:true } — token reset lain milik akun dibatalkan, seluruh refresh token akun dicabut (satu transaksi)
 *   422 token invalid / sudah dipakai / tidak berlaku lagi (dibatalkan) / kedaluwarsa / kebijakan password
 *   500 penyimpanan gagal (error database) — tidak ada yang tersimpan, token belum terpakai
 */
class ResetPasswordController extends ApiController
{
    public function forgot(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'username' => 'required|string|max_length[30]',
        ]);

        $result = service('resetPasswordService')->request((string) $data['username'], $this->request->getIPAddress());

        $payload = [
            'accepted' => true,
            'message'  => 'Jika username terdaftar, instruksi reset password akan dikirimkan.',
        ];

        if ($result['token'] !== null) {
            $payload['token']      = $result['token'];
            $payload['expires_at'] = $result['expires_at'];
        }

        return $this->respondSuccess($payload);
    }

    public function reset(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'token'                     => 'required|string',
            'new_password'              => 'required|string',
            'new_password_confirmation' => 'required|string',
        ]);

        service('resetPasswordService')->reset(
            (string) $data['token'],
            (string) $data['new_password'],
            (string) $data['new_password_confirmation'],
        );

        return $this->respondSuccess(['reset' => true]);
    }
}
