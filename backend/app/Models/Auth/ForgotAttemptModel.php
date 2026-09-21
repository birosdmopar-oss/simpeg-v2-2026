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
}
