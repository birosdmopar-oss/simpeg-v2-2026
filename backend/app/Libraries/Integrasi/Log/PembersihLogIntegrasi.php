<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Log;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Retensi `integrasi_log` (Q6-16, F6-24) untuk `php spark integrasi:bersihkan-log`: hapus baris `created_at` lebih tua
 * dari N hari (UTC) per batch PK agar tidak mengunci tabel lama. Idempoten (BP-6); `dryRun` hanya menghitung.
 */
final class PembersihLogIntegrasi
{
    public const BATCH = 5000;

    public function __construct(private readonly BaseConnection $db, private readonly int $batch = self::BATCH)
    {
    }

    /**
     * @return array{batas: string, jumlah: int}
     */
    public function bersihkan(int $hari, bool $dryRun = false): array
    {
        if ($hari < 1) {
            throw new InvalidArgumentException('Retensi minimal 1 hari.');
        }

        $batas = gmdate('Y-m-d H:i:s', time() - $hari * 86400);

        if ($dryRun) {
            return ['batas' => $batas, 'jumlah' => $this->db->table('integrasi_log')->where('created_at <', $batas)->countAllResults()];
        }

        $total = 0;

        do {
            $id = array_map(
                static fn (array $r): int => (int) $r['id_integrasi_log'],
                $this->db->table('integrasi_log')->select('id_integrasi_log')->where('created_at <', $batas)
                    ->orderBy('id_integrasi_log', 'ASC')->get($this->batch)->getResultArray(),
            );

            if ($id !== []) {
                $this->db->table('integrasi_log')->whereIn('id_integrasi_log', $id)->delete();
                $total += count($id);
            }
        } while (count($id) === $this->batch);

        return ['batas' => $batas, 'jumlah' => $total];
    }
}
