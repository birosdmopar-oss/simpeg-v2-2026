<?php

declare(strict_types=1);

namespace App\Models\Auth;

use CodeIgniter\Model;

/**
 * Tabel `token` — penyimpanan HASH refresh token (F0-06, ADR-004).
 * Plaintext token tidak pernah disimpan. Sengaja bukan BaseAuditableModel agar churn token tidak membanjiri audit_logs.
 */
class TokenModel extends Model
{
    protected $table         = 'token';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['nip', 'token_hash', 'claims_json', 'expires_at', 'revoked', 'revoked_at', 'created_at'];

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->where('token_hash', $hash)->first();

        return $row;
    }

    public function revoke(int $id, int $now): bool
    {
        return $this->update($id, [
            'revoked'    => 1,
            'revoked_at' => date('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * Hapus fisik satu refresh token (logout, A-05: "hapus dari DB, bukan cuma clear cookie").
     */
    public function deleteByHash(string $hash): bool
    {
        $this->where('token_hash', $hash)->delete();

        return $this->db->affectedRows() > 0;
    }

    public function revokeAllForNip(string $nip, int $now): int
    {
        $this->where('nip', $nip)->where('revoked', 0)->set([
            'revoked'    => 1,
            'revoked_at' => date('Y-m-d H:i:s', $now),
        ])->update();

        return $this->db->affectedRows();
    }
}
