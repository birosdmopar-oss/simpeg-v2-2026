<?php

declare(strict_types=1);

namespace Tests\Support\Database\Seeds;

use App\Constants\Role;
use CodeIgniter\Database\Seeder;

/**
 * Akun test Modul A — mengikuti 12 akun simpeg_v2_local_seed.sql / README_local_seed.md:
 * semua password_legacy = MD5('Password123!'), password (Argon2id) NULL sampai login pertama (lazy rehash).
 * Ditambah: 1 akun yang sudah Argon2id, 1 akun nonaktif.
 */
class AuthSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    /** Akun yang sudah punya hash Argon2id (tanpa legacy). */
    public const NIP_ARGON = '199001012014011099';

    /** Akun nonaktif (status 0). */
    public const NIP_INACTIVE = '199001012014011098';

    /**
     * @return list<array{nip: string, role: int, unit: string, satker: string}>
     */
    public static function accounts(): array
    {
        return [
            ['nip' => '198501012010011001', 'role' => Role::SUPER_ADMIN, 'unit' => 'U01', 'satker' => 'S01'],
            ['nip' => '199002152015022002', 'role' => Role::PEGAWAI, 'unit' => 'U01', 'satker' => 'S01'],
            ['nip' => '198703102012031003', 'role' => Role::ADMIN_SATKER, 'unit' => 'U01', 'satker' => 'S01'],
            ['nip' => '197805202005011004', 'role' => Role::ADMIN_VIEW_ESELON1, 'unit' => 'U01', 'satker' => 'S01'],
            ['nip' => '196511101990011005', 'role' => Role::MENTERI, 'unit' => 'U01', 'satker' => 'S01'],
            ['nip' => '199505052018052006', 'role' => Role::PTT, 'unit' => 'U01', 'satker' => 'S02'],
            ['nip' => '199308082019082007', 'role' => Role::PPPK, 'unit' => 'U01', 'satker' => 'S03'],
            ['nip' => '197212122008121008', 'role' => Role::PIMPINAN, 'unit' => 'U01', 'satker' => 'S01'],
            ['nip' => '198809092013092010', 'role' => Role::PEGAWAI, 'unit' => 'U01', 'satker' => 'S02'],
            ['nip' => '197611112010111011', 'role' => Role::PEGAWAI, 'unit' => 'U01', 'satker' => 'S03'],
            ['nip' => '199112122016122009', 'role' => Role::PEGAWAI, 'unit' => 'U01', 'satker' => 'S02'],
            ['nip' => '199409092017092012', 'role' => Role::PEGAWAI, 'unit' => 'U01', 'satker' => 'S03'],
        ];
    }

    /**
     * NIP pertama dengan role tertentu (untuk test per-role).
     */
    public static function nipForRole(int $role): string
    {
        foreach (self::accounts() as $a) {
            if ($a['role'] === $role) {
                return $a['nip'];
            }
        }

        throw new \RuntimeException("Tidak ada akun seed untuk role {$role}");
    }

    public function run(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach (self::accounts() as $a) {
            $rows[] = [
                'nip'             => $a['nip'],
                'username'        => $a['nip'],
                'password'        => null,
                'password_legacy' => md5(self::PASSWORD),
                'user_level'      => $a['role'],
                'id_unit'         => $a['unit'],
                'id_satker'       => $a['satker'],
                'status'          => '1',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        $rows[] = [
            'nip'             => self::NIP_ARGON,
            'username'        => self::NIP_ARGON,
            'password'        => password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            'password_legacy' => null,
            'user_level'      => Role::PEGAWAI,
            'id_unit'         => 'U01',
            'id_satker'       => 'S01',
            'status'          => '1',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $rows[] = [
            'nip'             => self::NIP_INACTIVE,
            'username'        => self::NIP_INACTIVE,
            'password'        => password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            'password_legacy' => null,
            'user_level'      => Role::PEGAWAI,
            'id_unit'         => 'U01',
            'id_satker'       => 'S01',
            'status'          => '0',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $this->db->table('pengguna')->insertBatch($rows);
    }
}
