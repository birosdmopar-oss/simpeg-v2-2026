<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Auth\PenggunaModel;

/**
 * Ganti password oleh pengguna sendiri (A-06).
 * Password lama salah → 422; baru disimpan Argon2id; SELURUH refresh token lama dicabut.
 * Perubahan tercatat otomatis di audit_logs (PenggunaModel auditable, hash dimasking).
 */
class PasswordService
{
    public function __construct(
        private PenggunaModel $pengguna,
        private PasswordVerifier $passwords,
        private JwtService $jwt,
    ) {
    }

    public function change(string $nip, string $oldPassword, string $newPassword, string $confirmation): void
    {
        $user = $this->pengguna->findByNip($nip);

        if ($user === null) {
            throw new NotFoundException('Akun tidak ditemukan.');
        }

        if (! $this->passwords->verify($user, $oldPassword)['ok']) {
            throw ValidationException::forField('old_password', 'Password lama salah.');
        }

        $this->assertNewPassword($newPassword, $confirmation, $oldPassword);

        $this->pengguna->update((int) $user['id_pengguna'], [
            'password'            => $this->passwords->hash($newPassword),
            'password_legacy'     => null,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        // Seluruh sesi lama tidak berlaku lagi (MTC-006).
        $this->jwt->revokeAllForNip($nip);
    }

    /**
     * @throws ValidationException
     */
    public function assertNewPassword(string $newPassword, string $confirmation, ?string $oldPassword = null): void
    {
        $errors = [];

        foreach ($this->passwords->policyErrors($newPassword) as $msg) {
            $errors['new_password'][] = $msg;
        }

        if ($oldPassword !== null && $newPassword === $oldPassword) {
            $errors['new_password'][] = 'Password baru tidak boleh sama dengan password lama.';
        }

        if ($newPassword !== $confirmation) {
            $errors['new_password_confirmation'][] = 'Konfirmasi password tidak cocok.';
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }
    }
}
