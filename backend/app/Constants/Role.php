<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Role constants SIMPEG v2 — 8 role, mapping 1:1 dari UserLevel legacy.
 *
 * Sumber kebenaran: Matriks_Role_x_Endpoint_SIMPEG_v2.docx Bagian 3.
 * Nama dan angka TIDAK BOLEH diubah tanpa revisi dokumen tersebut.
 */
final class Role
{
    public const SUPER_ADMIN        = 1;
    public const PEGAWAI            = 2;
    public const ADMIN_SATKER       = 3;
    public const ADMIN_VIEW_ESELON1 = 4;
    public const MENTERI            = 5;
    public const PTT                = 6;
    public const PPPK               = 7;
    public const PIMPINAN           = 8;

    /**
     * @var array<int, string>
     */
    private const LABELS = [
        self::SUPER_ADMIN        => 'Super Admin',
        self::PEGAWAI            => 'Pegawai / PNS',
        self::ADMIN_SATKER       => 'Admin Satker',
        self::ADMIN_VIEW_ESELON1 => 'Admin View / Eselon 1',
        self::MENTERI            => 'Menteri',
        self::PTT                => 'PTT',
        self::PPPK               => 'PPPK',
        self::PIMPINAN           => 'Pimpinan',
    ];

    private function __construct()
    {
    }

    /**
     * Seluruh kode role yang valid (UL_ALL).
     *
     * @return list<int>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(int $role): bool
    {
        return isset(self::LABELS[$role]);
    }

    public static function label(int $role): string
    {
        return self::LABELS[$role] ?? 'Unknown';
    }

    /**
     * Helper deklaratif untuk routing: Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER) => 'role:1,3'.
     */
    public static function filter(int ...$roles): string
    {
        return 'role:' . implode(',', $roles);
    }
}
