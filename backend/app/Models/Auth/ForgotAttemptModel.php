<?php

declare(strict_types=1);

namespace App\Models\Auth;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

/**
 * Tabel forgot_attempts (A-07): permintaan lupa password + token reset (hash, single-use).
 *
 * Status token (tanpa kolom tambahan):
 * - belum dipakai : used_at NULL (berlaku selama expires_at > sekarang);
 * - dipakai reset : used_at terisi dan used_at < expires_at (reset menolak token kedaluwarsa sebelum klaim);
 * - dibatalkan    : used_at terisi dan expires_at <= used_at — ditulis invalidateOtherTokens() setelah reset lain
 *                   milik user yang sama sukses (lihat isInvalidated()).
 *
 * Di dalam transaksi CI4 4.7 query yang gagal tidak melempar exception (DBDebug true maupun false): hanya
 * mengembalikan false + menandai transStatus. Karena itu markUsed()/invalidateOtherTokens() melempar
 * DatabaseException sendiri saat query gagal, supaya error DB tidak terbaca sebagai "token sudah dipakai".
 */
class ForgotAttemptModel extends Model
{
    protected $table         = 'forgot_attempts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['username', 'ip_address', 'token_hash', 'expires_at', 'used_at', 'requested_at'];

    public function countRecent(string $username, int $windowMinutes, ?int $now = null): int
    {
        $since = date('Y-m-d H:i:s', ($now ?? time()) - $windowMinutes * 60);

        return $this->where('username', $username)->where('requested_at >=', $since)->countAllResults();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTokenHash(string $hash): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('token_hash', $hash)->first();

        return $row;
    }

    /**
     * Klaim token secara atomik: `UPDATE ... SET used_at = ? WHERE id = ? AND used_at IS NULL`.
     * True hanya jika baris ini yang benar-benar menandai token terpakai (affected rows = 1); false berarti token
     * sudah diklaim/dibatalkan lebih dulu — mis. oleh request paralel (DEV-002 Bagian 8 #2).
     *
     * @throws DatabaseException query gagal (lock wait timeout, deadlock, dll.) — bukan kasus "sudah dipakai"
     */
    public function markUsed(int $id, int $now): bool
    {
        $ok = $this->where('id', $id)->where('used_at', null)->set([
            'used_at' => date('Y-m-d H:i:s', $now),
        ])->update();

        return $this->affectedOrFail($ok, 'Gagal mengklaim token reset.') === 1;
    }

    /**
     * Batalkan seluruh token reset lain milik username yang belum dipakai, dipanggil setelah reset sukses agar token
     * yang pernah diminta sebelumnya tidak bisa dipakai lagi. used_at diisi (penjaga klaim atomik markUsed() tetap
     * satu kolom) dan expires_at dipangkas ke waktu pembatalan, sehingga token ini terbaca "dibatalkan", bukan
     * "sudah dipakai" (isInvalidated()).
     *
     * @return int jumlah token yang dibatalkan
     *
     * @throws DatabaseException query gagal
     */
    public function invalidateOtherTokens(string $username, int $exceptId, int $now): int
    {
        $at = date('Y-m-d H:i:s', $now);

        $ok = $this->where('username', $username)
            ->where('id !=', $exceptId)
            ->where('token_hash IS NOT NULL')
            ->where('used_at', null)
            ->set('used_at', $at)
            ->set('expires_at', 'LEAST(expires_at, ' . $this->db->escape($at) . ')', false)
            ->update();

        return $this->affectedOrFail($ok, 'Gagal membatalkan token reset lain.');
    }

    /**
     * True bila token tidak berlaku karena dibatalkan invalidateOtherTokens(), bukan karena dipakai reset.
     *
     * @param array<string, mixed> $row
     */
    public static function isInvalidated(array $row): bool
    {
        if ($row['used_at'] === null) {
            return false;
        }

        return $row['expires_at'] === null || strtotime((string) $row['expires_at']) <= strtotime((string) $row['used_at']);
    }

    private function affectedOrFail(bool $ok, string $message): int
    {
        // mysqli: affected_rows = -1 setelah query error.
        $affected = $this->db->affectedRows();

        if (! $ok || $affected < 0) {
            throw new DatabaseException($message);
        }

        return $affected;
    }
}
