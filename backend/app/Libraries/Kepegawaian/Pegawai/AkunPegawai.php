<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\JwtService;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Akun `pengguna` milik pegawai pada siklus B-05 (MAKE-011): role akun dari jenis pegawai dan penonaktifan akun saat
 * pegawai dinonaktifkan/dihapus. Dipanggil DI DALAM transaksi pemanggil (BiodataService, SiklusPegawai).
 */
final class AkunPegawai
{
    /**
     * `id_jenis_pegawai` → UserLevel akun baru [K] `libraries/hr/L_employee.php:158-161`: 2 → PTT (6), 6 → PPPK (7),
     * selain itu Pegawai/PNS (2).
     */
    public static function roleUntukJenisPegawai(?int $idJenisPegawai): int
    {
        return match ($idJenisPegawai) {
            2       => Role::PTT,
            6       => Role::PPPK,
            default => Role::PEGAWAI,
        };
    }

    /**
     * Nonaktifkan akun ber-NIP $nip (`status` '0') dan cabut seluruh sesinya; $roles null = semua akun NIP itu. Akun
     * yang sudah nonaktif tidak disentuh. Audit `pengguna` per akun. Mengembalikan jumlah akun yang dinonaktifkan.
     *
     * @param list<int>|null $roles
     */
    public static function nonaktifkan(BaseConnection $db, AuthContext $auth, string $nip, ?array $roles, string $alasan, ?JwtService $jwt = null): int
    {
        $builder = $db->table('pengguna')->select('id_pengguna, status, user_level')->where('nip', $nip)->where('status', '1');

        if ($roles !== null) {
            $builder->whereIn('user_level', $roles);
        }

        $jumlah = 0;

        foreach ($builder->get()->getResultArray() as $akun) {
            $id = (int) $akun['id_pengguna'];

            if ($db->table('pengguna')->where('id_pengguna', $id)->update(['status' => '0', 'updated_at' => gmdate('Y-m-d H:i:s')]) === false) {
                $error = $db->error();

                throw new DatabaseException('Nonaktifkan akun pegawai gagal: ' . $error['message'], (int) $error['code']);
            }

            ($jwt ?? service('jwt'))->revokeAllForUser($id);
            (new AuditLogModel($db))->record('pengguna', $id, AuditLogModel::EVENT_UPDATE, ['status' => '1'], ['status' => '0', 'alasan' => $alasan], $auth->idPengguna(), $auth->nip());
            $jumlah++;
        }

        return $jumlah;
    }
}
