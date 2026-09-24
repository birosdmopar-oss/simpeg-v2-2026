<?php

declare(strict_types=1);

namespace App\Models\Auth;

use CodeIgniter\Model;

/**
 * Tabel forgot_attempts (A-07): permintaan lupa password + token reset (hash, single-use).
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
     * sudah diklaim lebih dulu — mis. oleh request paralel dengan token yang sama (DEV-002 Bagian 8 #2).
     */
    public function markUsed(int $id, int $now): bool
    {
        $this->where('id', $id)->where('used_at', null)->set([
            'used_at' => date('Y-m-d H:i:s', $now),
        ])->update();

        return $this->db->affectedRows() === 1;
    }

    /**
     * Batalkan seluruh token reset lain milik username yang belum dipakai (ditandai used_at), dipanggil setelah
     * reset sukses agar token yang pernah diminta sebelumnya tidak bisa dipakai lagi.
     */
    public function invalidateOtherTokens(string $username, int $exceptId, int $now): int
    {
        $this->where('username', $username)
            ->where('id !=', $exceptId)
            ->where('token_hash IS NOT NULL')
            ->where('used_at', null)
            ->set(['used_at' => date('Y-m-d H:i:s', $now)])
            ->update();

        return $this->db->affectedRows();
    }
}
