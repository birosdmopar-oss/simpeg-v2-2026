<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use CodeIgniter\Database\BaseConnection;

/**
 * Keterjangkauan approver cuti (PRD Fase 4 v1.3 §6.4.10, Q-01/Q-06(b), 27-10). Dievaluasi saat dibaca, pada approver
 * tahap aktif saja; tidak disimpan di baris cuti.
 *
 * Tak terjangkau: NIP kosong/NULL (eselon I → Menteri), tidak ada akun `pengguna` ber-NIP itu, akun nonaktif, akun
 * terhapus (`deleted_at`), atau `user_level` di luar route cuti {1, 2, 3, 6, 7}.
 */
class ApproverTerjangkau
{
    public const ALASAN_ESELON_I = 'eselon I';

    public const ALASAN_TANPA_AKUN = 'tanpa akun';

    public const ALASAN_NONAKTIF = 'nonaktif';

    public const ALASAN_TERHAPUS = 'terhapus';

    public const ALASAN_ROLE = 'role';

    /** Role yang route cutinya terbuka (D5): 1, 2, 3, 6, 7. */
    public const ROLE_ROUTE_CUTI = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** true = terjangkau. */
    public function periksa(?string $nip): bool
    {
        return $this->alasanTakTerjangkau($nip) === null;
    }

    /**
     * Alasan tak terjangkau (`null` = terjangkau).
     */
    public function alasanTakTerjangkau(?string $nip): ?string
    {
        $nip = trim((string) $nip);

        if ($nip === '') {
            return self::ALASAN_ESELON_I;
        }

        $akun = $this->db->table('pengguna')
            ->select('status, user_level, deleted_at')
            ->where('nip', $nip)
            ->orderBy('id_pengguna', 'ASC')
            ->get()->getResultArray();

        if ($akun === []) {
            return self::ALASAN_TANPA_AKUN;
        }

        // Satu NIP bisa punya lebih dari satu akun historis: terjangkau bila ADA akun yang memenuhi semua syarat.
        $alasan = null;

        foreach ($akun as $a) {
            if ($a['deleted_at'] !== null) {
                $alasan ??= self::ALASAN_TERHAPUS;
            } elseif ((string) $a['status'] !== '1') {
                $alasan = self::ALASAN_NONAKTIF;
            } elseif (! in_array((int) $a['user_level'], self::ROLE_ROUTE_CUTI, true)) {
                $alasan = self::ALASAN_ROLE;
            } else {
                return null;
            }
        }

        return $alasan;
    }
}
