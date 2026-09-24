<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Auth as AuthConfig;
use Throwable;

/**
 * Lupa / reset password (A-07).
 *
 * request(): rate limit via forgot_attempts (forgotMaxPerWindow per forgotWindowMinutes), response selalu
 *            generik (tidak membocorkan apakah username terdaftar). Token acak 256-bit, di DB hanya hash.
 * reset()  : token expired → ditolak; token sudah dipakai / dibatalkan → ditolak; sukses → password Argon2id baru,
 *            token ditandai used_at, token reset lain milik user dibatalkan, SELURUH refresh token dicabut.
 *            Semua tulisan dalam satu transaksi; klaim token lewat UPDATE bersyarat used_at IS NULL sehingga
 *            dua request paralel dengan token yang sama hanya satu yang berhasil (DEV-002 Bagian 8 #2).
 *
 * Transaksi reset (all-or-nothing):
 * - Urutan lock tetap: baris pengguna (FOR UPDATE) → token yang dipakai → token lain → refresh token. Reset paralel
 *   untuk akun yang sama (token sama maupun berbeda) berjalan berurutan tanpa deadlock; yang kalah mendapat 422.
 * - Di CI4 4.7 query yang gagal di dalam transaksi TIDAK melempar exception (DBDebug true maupun false): hanya
 *   mengembalikan false + transStatus false, dan transCommit() tidak memeriksa transStatus. Karena itu setiap langkah
 *   diperiksa hasilnya dan transStatus-nya; kegagalan pertama menghentikan transaksi (InnoDB bisa sudah me-rollback
 *   seluruh transaksi, mis. deadlock, sehingga statement berikutnya akan ter-commit sendiri), lalu rollback dan
 *   DatabaseException (500). Token reset tetap belum terpakai sehingga user bisa mencoba lagi.
 * - Termasuk tulisan audit_logs yang gagal (transStatus false): untuk jalur ini audit TIDAK fail-open (pengecualian
 *   F0-04) karena kegagalannya tidak bisa dibedakan dari transaksi yang sudah di-rollback server.
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
            throw self::consumedToken($row);
        }

        if ($row['expires_at'] === null || strtotime((string) $row['expires_at']) <= $now) {
            throw ValidationException::forField('token', 'Token reset sudah kedaluwarsa.');
        }

        $user = $this->pengguna->findByUsername((string) $row['username']);

        if ($user === null || (string) $user['status'] !== PenggunaModel::STATUS_ACTIVE) {
            throw ValidationException::forField('token', 'Token reset tidak valid.');
        }

        $this->passwordService->assertNewPassword($newPassword, $confirmation);

        // Hash Argon2id (lambat) dihitung di luar transaksi agar lock baris tidak lama.
        $hash    = $this->passwords->hash($newPassword);
        $nip     = (string) $user['nip'];
        $userId  = (int) $user['id_pengguna'];
        $tokenId = (int) $row['id'];

        if (! $this->db->transBegin()) {
            throw new DatabaseException('Reset password gagal: transaksi tidak dapat dimulai.');
        }

        try {
            // Lock pertama selalu baris pengguna: reset paralel untuk akun yang sama (token sama atau berbeda)
            // menunggu di sini, bukan saling mengunci baris forgot_attempts dengan urutan berlawanan (deadlock).
            if (! $this->pengguna->lockForUpdate($userId)) {
                throw ValidationException::forField('token', 'Token reset tidak valid.');
            }

            // Single-use: cek used_at di atas hanya jalur cepat. UPDATE bersyarat used_at IS NULL yang menentukan
            // pemenang; request paralel yang kalah mendapat affected rows 0 → ditolak, transaksi di-rollback.
            if (! $this->attempts->markUsed($tokenId, $now)) {
                throw self::consumedToken($this->attempts->find($tokenId) ?? $row);
            }

            // Tidak ada sesi login pada jalur reset → actor audit = pemilik akun (ISSUE-005).
            $updated = $this->pengguna->withActor($nip, fn (): bool => $this->pengguna->update($userId, [
                'password'            => $hash,
                'password_legacy'     => null,
                'password_changed_at' => date('Y-m-d H:i:s', $now),
            ]));
            $this->assertStep($updated, 'password baru tidak tersimpan');

            $invalidated = $this->attempts->invalidateOtherTokens((string) $row['username'], $tokenId, $now);
            $this->assertStep($invalidated >= 0, 'token reset lain tidak dapat dibatalkan');

            $this->assertStep($this->jwt->revokeAllForNip($nip) >= 0, 'refresh token tidak dapat dicabut');

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new DatabaseException('Reset password gagal disimpan.');
            }
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->db->resetTransStatus();
    }

    /**
     * Hentikan transaksi pada langkah pertama yang gagal: hasil tulis false ATAU transStatus false (query lain di
     * langkah itu gagal, termasuk INSERT audit_logs dari event model).
     */
    private function assertStep(bool $ok, string $reason): void
    {
        if (! $ok || ! $this->db->transStatus()) {
            throw new DatabaseException('Reset password gagal: ' . $reason . '.');
        }
    }

    private function rollBack(): void
    {
        try {
            $this->db->transRollback();
        } catch (Throwable $e) {
            // Koneksi putus dsb.: server me-rollback transaksi yang tidak di-commit; exception asal tetap diteruskan.
            log_message('error', '[ResetPassword] rollback gagal: {msg}', ['msg' => $e->getMessage()]);
        } finally {
            $this->db->resetTransStatus();
        }
    }

    /**
     * 422 untuk token yang used_at-nya sudah terisi: dibedakan antara benar-benar dipakai reset dan dibatalkan oleh
     * reset lain milik user yang sama (ForgotAttemptModel::isInvalidated()).
     *
     * @param array<string, mixed> $row
     */
    private static function consumedToken(array $row): ValidationException
    {
        return ValidationException::forField('token', ForgotAttemptModel::isInvalidated($row)
            ? 'Token reset sudah tidak berlaku lagi.'
            : 'Token reset sudah pernah dipakai.');
    }
}
