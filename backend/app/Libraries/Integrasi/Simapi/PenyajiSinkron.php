<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;

/**
 * MAKE-059 (F6-07) — `a_sinkron/ambil_riwayat_mutasi` [K `A_sinkron.php:59-114`]: SELURUH baris `riwayat_mutasi_jabatan`
 * tanpa filter status (legacy tanpa filter), field `nip, rumpun_jabatan, subrumpun_jabatan, tmtsk`, urut NIP ASC lalu TMT DESC.
 * `a_sinkron/ambil_seluruh_pengguna` sengaja TIDAK dibuat (F6-07, Q6-03 = A).
 */
final class PenyajiSinkron
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function riwayatMutasi(): array
    {
        $rows = $this->db->table('riwayat_mutasi_jabatan')
            ->select('nip, rumpun_jabatan, subrumpun_jabatan, tmtsk')
            ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'DESC')
            ->orderBy('id_riwayat_mutasi_jabatan', 'ASC') // [V2] kunci sekunder agar urutan stabil
            ->get()->getResultArray();

        return array_map(Nilai::mentahSemua(...), $rows);
    }
}
