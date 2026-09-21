<?php

declare(strict_types=1);

namespace App\Models\Auth;

use CodeIgniter\Model;

/**
 * Tabel login_attempts (A-04). Model biasa (bukan auditable) — tabel log volume tinggi.
 */
class LoginAttemptModel extends Model
{
    protected $table         = 'login_attempts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['username', 'ip_address', 'success', 'attempted_at'];

    public function record(string $username, ?string $ip, bool $success, ?int $now = null): int
    {
        $this->insert([
            'username'     => $username,
            'ip_address'   => $ip,
            'success'      => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s', $now ?? time()),
        ]);

        return (int) $this->getInsertID();
    }

    /**
     * Kegagalan berturut-turut sejak sukses terakhir, dalam jendela $windowMinutes.
     *
     * @return array{count: int, last_failed_at: string|null}
     */
    public function consecutiveFailures(string $username, int $windowMinutes, ?int $now = null): array
    {
        $since = date('Y-m-d H:i:s', ($now ?? time()) - $windowMinutes * 60);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('username', $username)
            ->where('attempted_at >=', $since)
            ->orderBy('attempted_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        $count = 0;
        $last  = null;

        foreach ($rows as $row) {
            if ((int) $row['success'] === 1) {
                break; // sukses terakhir mereset hitungan
            }
            $count++;
            $last ??= (string) $row['attempted_at'];
        }

        return ['count' => $count, 'last_failed_at' => $last];
    }
}
