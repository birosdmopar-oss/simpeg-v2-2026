<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use Config\Auth as AuthConfig;

/**
 * Kebijakan password baru — SATU sumber aturan backend (K4, keputusan user 25-09-2026: ikut legacy).
 *
 * Legacy `L_user.php` validate_param_cp() (ganti password) dan rp() (reset password): minimal 8 karakter, minimal
 * 1 huruf besar, 1 huruf kecil, dan 1 angka (pola ASCII [A-Z] / [a-z] / [0-9]). Dipakai oleh semua jalur yang
 * MENETAPKAN password: ganti password sendiri (PasswordService), reset password (ResetPasswordService lewat
 * PasswordService::assertNewPassword), admin buat/ubah akun (UserService), dan password awal akun otomatis
 * (AccountProvisioner). Login TIDAK memeriksa kebijakan: password lama yang tidak memenuhi aturan tetap bisa dipakai
 * dan tidak dipaksa diganti.
 *
 * Perbedaan dari legacy (disengaja): panjang dihitung per karakter (mb_strlen), bukan per byte, dan spasi tidak
 * dibuang diam-diam — password disimpan persis seperti yang diketik.
 *
 * Frontend memakai aturan yang sama di `frontend/src/features/auth/schemas/password.schema.ts` (PASSWORD_RULES):
 * id, urutan, dan pesan WAJIB diubah bersamaan.
 */
final class PasswordPolicy
{
    public const RULE_MIN_LENGTH = 'min_length';
    public const RULE_UPPERCASE  = 'uppercase';
    public const RULE_LOWERCASE  = 'lowercase';
    public const RULE_DIGIT      = 'digit';

    /**
     * Aturan komposisi: id => [pola, pesan]. Urutan = urutan pesan error.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COMPOSITION = [
        self::RULE_UPPERCASE => ['/[A-Z]/', 'Password harus mengandung minimal 1 huruf besar.'],
        self::RULE_LOWERCASE => ['/[a-z]/', 'Password harus mengandung minimal 1 huruf kecil.'],
        self::RULE_DIGIT     => ['/[0-9]/', 'Password harus mengandung minimal 1 angka.'],
    ];

    public function __construct(private int $minLength = 8)
    {
    }

    public static function fromConfig(?AuthConfig $config = null): self
    {
        $config ??= config(AuthConfig::class);

        return new self($config->passwordMinLength);
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /**
     * Id aturan yang TIDAK dipenuhi, urut sesuai daftar aturan (kosong = valid).
     *
     * @return list<string>
     */
    public function failedRules(string $plain): array
    {
        $failed = [];

        if (mb_strlen($plain) < $this->minLength) {
            $failed[] = self::RULE_MIN_LENGTH;
        }

        foreach (self::COMPOSITION as $id => [$pattern]) {
            if (preg_match($pattern, $plain) !== 1) {
                $failed[] = $id;
            }
        }

        return $failed;
    }

    /**
     * Pesan error (Bahasa Indonesia) untuk setiap aturan yang tidak dipenuhi (kosong = valid).
     *
     * @return list<string>
     */
    public function errors(string $plain): array
    {
        return array_map(fn (string $id): string => $this->message($id), $this->failedRules($plain));
    }

    public function message(string $rule): string
    {
        if ($rule === self::RULE_MIN_LENGTH) {
            return sprintf('Password minimal %d karakter.', $this->minLength);
        }

        return self::COMPOSITION[$rule][1] ?? 'Password tidak memenuhi kebijakan.';
    }
}
