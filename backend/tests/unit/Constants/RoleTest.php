<?php

declare(strict_types=1);

namespace Tests\Unit\Constants;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F0-07 — 8 role, nama & angka persis Matriks Role x Endpoint Bagian 3.
 *
 * @internal
 */
final class RoleTest extends CIUnitTestCase
{
    public function testEightRoleConstantsMatchMatrix(): void
    {
        $this->assertSame(1, Role::SUPER_ADMIN);
        $this->assertSame(2, Role::PEGAWAI);
        $this->assertSame(3, Role::ADMIN_SATKER);
        $this->assertSame(4, Role::ADMIN_VIEW_ESELON1);
        $this->assertSame(5, Role::MENTERI);
        $this->assertSame(6, Role::PTT);
        $this->assertSame(7, Role::PPPK);
        $this->assertSame(8, Role::PIMPINAN);
    }

    public function testAllReturnsExactlyEightRoles(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], Role::all());

        // Konstanta kode role = konstanta publik bernilai int (UL_PEGAWAI adalah daftar, bukan role).
        $codes = array_filter(
            (new \ReflectionClass(Role::class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC),
            static fn (\ReflectionClassConstant $c): bool => is_int($c->getValue()),
        );
        $this->assertCount(8, $codes);
    }

    /**
     * DBV-010 (K2): role pegawai (UL_PEGAWAI legacy 2/6/7) wajib ber-NIP; role 1/3/4/5/8 boleh tanpa NIP.
     */
    public function testUlPegawaiRolesRequireNip(): void
    {
        $this->assertSame([Role::PEGAWAI, Role::PTT, Role::PPPK], Role::UL_PEGAWAI);

        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role, [2, 6, 7], true), Role::wajibNip($role), "role {$role}");
        }
    }

    public function testIsValidAndLabel(): void
    {
        $this->assertTrue(Role::isValid(Role::PPPK));
        $this->assertFalse(Role::isValid(0));
        $this->assertFalse(Role::isValid(9));
        $this->assertSame('Admin Satker', Role::label(Role::ADMIN_SATKER));
        $this->assertSame('Unknown', Role::label(99));
    }

    public function testFilterHelperBuildsRouteFilterString(): void
    {
        $this->assertSame('role:1,3', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER));
    }
}
