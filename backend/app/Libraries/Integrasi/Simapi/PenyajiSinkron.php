<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;

/**
 * MAKE-059 (F6-07) — `a_sinkron/ambil_riwayat_mutasi` [K `A_sinkron.php:59-114`]: seluruh baris `riwayat_mutasi_jabatan`
 * KECUALI status 10 [V2, review P2]: legacy tanpa filter status tetapi menghapus keras (`L_jabatan.php:1712`), sedangkan v2
 * menghapus lunak (status 10) — baris terhapus tidak terkirim, sama dengan perilaku efektif legacy. Field `nip, rumpun_jabatan, subrumpun_jabatan, tmtsk`, urut NIP ASC lalu TMT DESC.
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
            ->where('status !=', 10)
            ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'DESC')
            ->orderBy('id_riwayat_mutasi_jabatan', 'ASC') // [V2] kunci sekunder agar urutan stabil
            ->get()->getResultArray(); // nilai mentah MySQLi = string/null, sama dengan legacy

        return $rows;
    }
}
