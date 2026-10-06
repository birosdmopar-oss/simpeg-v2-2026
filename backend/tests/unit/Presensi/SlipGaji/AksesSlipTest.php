<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Constants\Role;
use App\Libraries\Presensi\SlipGaji\AksesSlip;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (H-5) — hak akses keputusan user 01-10-2026: pegawai 2/6/7 slip milik sendiri, admin 1 semua, admin 3 hanya
 * satker sendiri (fail-closed), role 4/5/8 tanpa akses.
 *
 * @internal
 */
final class AksesSlipTest extends CIUnitTestCase
{
    public function testBolehMelihatMilikSendiri(): void
    {
        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $this->assertTrue(AksesSlip::bolehMelihatMilikSendiri($role), (string) $role);
        }

        foreach ([Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN, 0, 9] as $role) {
            $this->assertFalse(AksesSlip::bolehMelihatMilikSendiri($role), (string) $role);
        }
    }

    public function testBolehMengelola(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role, [1, 3], true), AksesSlip::bolehMengelola($role), (string) $role);
        }

        $this->assertFalse(AksesSlip::bolehMengelola(0));
    }

    public function testDalamCakupan(): void
    {
        $this->assertTrue(AksesSlip::dalamCakupan(1, null, 'S2'));
        $this->assertTrue(AksesSlip::dalamCakupan(1, null, null));
        $this->assertTrue(AksesSlip::dalamCakupan(3, 'S1', 'S1'));

        $this->assertFalse(AksesSlip::dalamCakupan(3, 'S1', 'S2'));
        $this->assertFalse(AksesSlip::dalamCakupan(3, null, 'S1'));
        $this->assertFalse(AksesSlip::dalamCakupan(3, '', 'S1'));
        $this->assertFalse(AksesSlip::dalamCakupan(3, 'S1', null));
        $this->assertFalse(AksesSlip::dalamCakupan(3, 'S1', ''));
        $this->assertFalse(AksesSlip::dalamCakupan(3, 'S1', 's1'));
        $this->assertFalse(AksesSlip::dalamCakupan(3, '', ''));

        foreach ([2, 4, 5, 6, 7, 8] as $role) {
            $this->assertFalse(AksesSlip::dalamCakupan($role, 'S1', 'S1'), (string) $role);
        }
    }
}
