<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak pengiriman tautan reset password ke pemilik akun (A-07, ISSUE-006, ADR-015).
 *
 * Kanal final = email (K3, keputusan user 25-09-2026). Implementasi: MockResetTokenNotifier (test),
 * LogResetTokenNotifier (development; menulis tautan ke log). Keduanya ditolak di production. Driver email (SMTP)
 * menyusul tanpa mengubah ResetPasswordService; driver itu WAJIB menjadwalkan pengiriman lewat Queue (ADR-013)
 * supaya waktu respons forgot-password tetap seragam dan tidak membocorkan username mana yang terdaftar.
 *
 * Hanya dipanggil untuk akun aktif yang dikenal, setelah hash token tersimpan di forgot_attempts.
 * Kegagalan kirim cukup dilempar sebagai exception: ResetPasswordService mencatatnya di log dan tetap mengembalikan
 * respons generik ke pemohon.
 */
interface ResetTokenNotifierInterface
{
    /**
     * @param array<string, mixed> $user      row pengguna pemilik akun (nip, username, dan kolom kontak bila ada)
     * @param string               $token     token reset plaintext (hanya ada di memori; DB menyimpan hash-nya)
     * @param string               $resetLink tautan halaman reset frontend yang sudah memuat token
     * @param string               $expiresAt batas berlaku token (Y-m-d H:i:s, zona waktu aplikasi)
     */
    public function send(array $user, string $token, string $resetLink, string $expiresAt): void;
}
