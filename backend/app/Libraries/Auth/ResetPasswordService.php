<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Database\BaseConnection;
use Config\Auth as AuthConfig;
use Throwable;

/**
 * Lupa / reset password (A-07).
 *
 * request(): rate limit via forgot_attempts (forgotMaxPerWindow per forgotWindowMinutes), response selalu
 *            generik (tidak membocorkan apakah username terdaftar). Token acak 256-bit, di DB hanya hash.
 * reset()  : token expired → ditolak; token sudah dipakai → ditolak; sukses → password Argon2id baru,
 *            token ditandai used_at, token reset lain milik user dibatalkan, SELURUH refresh token dicabut.
 *            Semua tulisan dalam satu transaksi; klaim token lewat UPDATE bersyarat used_at IS NULL sehingga
 *            dua request paralel dengan token yang sama hanya satu yang berhasil (DEV-002 Bagian 8 #2).
 *
 * Kanal pengiriman token (email/WA) belum ditentukan di dokumen sumber → token di-log (info) dan,
 * hanya jika Config\Auth::$exposeResetTokenInResponse = true (development), dikembalikan ke pemanggil.
 */
class ResetPasswordService
{
    private ?int $now = null;

    public function __construct(
        private PenggunaModel $pengguna,
        private ForgotAttemptModel $attempts,
        private PasswordVerifier $passwords,
        private PasswordService $passwordService,
        private JwtService $jwt,
        private ?AuthConfig $config = null,
        private ?BaseConnection $db = null,
    ) {
        $this->config ??= config(AuthConfig::class);
        $this->db ??= db_connect();
    }

    public function setNow(?int $now): void
    {
        $this->now = $now;
    }

    /**
     * @return array{accepted: bool, token: string|null, expires_at: string|null}
     *                                                                            token hanya terisi kalau exposeResetTokenInResponse=true DAN username dikenal
     */
    public function request(string $username, ?string $ip): array
    {
        $now = $this->now ?? time();

        if ($this->attempts->countRecent($username, $this->config->forgotWindowMinutes, $now) >= $this->config->forgotMaxPerWindow) {
            throw new TooManyRequestsException(sprintf(
                'Terlalu banyak permintaan reset password. Coba lagi dalam %d menit.',
                $this->config->forgotWindowMinutes,
            ));
        }

        $user      = $this->pengguna->findByUsername($username);
        $token     = null;
        $tokenHash = null;
        $expiresAt = null;

        if ($user !== null && (string) $user['status'] === PenggunaModel::STATUS_ACTIVE) {
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', $now + $this->config->resetTokenTtl);
        }

        $this->attempts->insert([
            'username'     => $username,
            'ip_address'   => $ip,
            'token_hash'   => $tokenHash,
            'expires_at'   => $expiresAt,
            'used_at'      => null,
            'requested_at' => date('Y-m-d H:i:s', $now),
        ]);

        if ($token !== null) {
            // Pengiriman token ke pengguna: kanal belum ditentukan (TBD). Sementara dicatat di log lokal.
            log_message('info', '[ResetPassword] token reset diterbitkan untuk username={username}, berlaku s.d. {exp}', [
                'username' => $username,
                'exp'      => (string) $expiresAt,
            ]);
        }

        return [
            'accepted'   => true,
            'token'      => $this->config->exposeResetTokenInResponse ? $token : null,
            'expires_at' => $this->config->exposeResetTokenInResponse ? $expiresAt : null,
        ];
    }

    public function reset(string $token, string $newPassword, string $confirmation): void
    {
        $now = $this->now ?? time();
        $row = $token === '' ? null : $this->attempts->findByTokenHash(hash('sha256', $token));

        if ($row === null) {
            throw ValidationException::forField('token', 'Token reset tidak valid.');
        }

        if ($row['used_at'] !== null) {
            throw ValidationException::forField('token', 'Token reset sudah pernah dipakai.');
        }

        if ($row['expires_at'] === null || strtotime((string) $row['expires_at']) <= $now) {
            throw ValidationException::forField('token', 'Token reset sudah kedaluwarsa.');
        }

        $user = $this->pengguna->findByUsername((string) $row['username']);

        if ($user === null || (string) $user['status'] !== PenggunaModel::STATUS_ACTIVE) {
            throw ValidationException::forField('token', 'Token reset tidak valid.');
        }

        $this->passwordService->assertNewPassword($newPassword, $confirmation);

        // Hash Argon2id (lambat) dihitung di luar transaksi agar lock baris forgot_attempts tidak lama.
        $hash = $this->passwords->hash($newPassword);
        $nip  = (string) $user['nip'];

        $this->db->transBegin();

        try {
            // Single-use: cek used_at di atas hanya jalur cepat. UPDATE bersyarat used_at IS NULL yang menentukan
            // pemenang; request paralel yang kalah mendapat affected rows 0 → ditolak, transaksi di-rollback.
            if (! $this->attempts->markUsed((int) $row['id'], $now)) {
                throw ValidationException::forField('token', 'Token reset sudah pernah dipakai.');
            }

            // Tidak ada sesi login pada jalur reset → actor audit = pemilik akun (ISSUE-005).
            $this->pengguna->withActor($nip, fn (): bool => $this->pengguna->update((int) $user['id_pengguna'], [
                'password'            => $hash,
                'password_legacy'     => null,
                'password_changed_at' => date('Y-m-d H:i:s', $now),
            ]));

            $this->attempts->invalidateOtherTokens((string) $row['username'], (int) $row['id'], $now);

            $this->jwt->revokeAllForNip($nip);
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        $this->db->transCommit();
        $this->db->resetTransStatus();
    }
}
