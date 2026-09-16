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
        $this->assertCount(8, (new \ReflectionClass(Role::class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC));
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
