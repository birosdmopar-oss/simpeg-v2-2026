<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Kepegawaian\Riwayat\UjiKp;
use Tests\Support\Kepegawaian\Riwayat\UjiPendidikan;

/**
 * WS-1 M1 (MAKE-004) — default perilaku status RiwayatDefinisi (aditif) ikut legacy `controllers/hr/rwy/*`.
 *
 * @internal
 */
final class DefinisiDefaultLegacyTest extends CIUnitTestCase
{
    public function testStatusAwalSelfServiceDanAdmin(): void
    {
        $selfService = new UjiPendidikan();
        $admin       = new UjiKp();

        foreach (Role::UL_PEGAWAI as $role) {
            $this->assertSame(StatusRiwayat::Menunggu, $selfService->statusAwal($role), "role {$role}");
            $this->assertSame(StatusRiwayat::Disetujui, $admin->statusAwal($role), "alur admin role {$role}");
        }

        foreach ([Role::SUPER_ADMIN, Role::ADMIN_SATKER] as $role) {
            $this->assertSame(StatusRiwayat::Disetujui, $selfService->statusAwal($role));
        }
    }

    public function testStatusSetelahUbah(): void
    {
        $d = new UjiPendidikan();

        $this->assertSame(StatusRiwayat::Menunggu, $d->statusSetelahUbah(Role::PEGAWAI, StatusRiwayat::Ditolak));
        $this->assertSame(StatusRiwayat::Menunggu, $d->statusSetelahUbah(Role::PPPK, StatusRiwayat::Menunggu));
        $this->assertSame(StatusRiwayat::Disetujui, $d->statusSetelahUbah(Role::ADMIN_SATKER, StatusRiwayat::Menunggu));
        $this->assertSame(StatusRiwayat::Ditolak, $d->statusSetelahUbah(Role::SUPER_ADMIN, StatusRiwayat::Ditolak));
        $this->assertSame(StatusRiwayat::Disetujui, (new UjiKp())->statusSetelahUbah(Role::PEGAWAI, StatusRiwayat::Disetujui));

        // Status NULL (data lama) = seperti 0 Menunggu; role 1 membiarkannya (null = kolom status tidak ditulis).
        $this->assertSame(StatusRiwayat::Menunggu, $d->statusSetelahUbah(Role::PEGAWAI, null));
        $this->assertSame(StatusRiwayat::Disetujui, $d->statusSetelahUbah(Role::ADMIN_SATKER, null));
        $this->assertNull($d->statusSetelahUbah(Role::SUPER_ADMIN, null));
    }

    public function testKunciDanUrutanBawaan(): void
    {
        $d = new UjiPendidikan();

        $this->assertTrue($d->kunciBarisDisetujui());
        $this->assertSame([Role::ADMIN_SATKER], $d->roleKunciHapusDisetujui());
        $this->assertSame(['id_riwayat_pendidikan' => 'DESC'], $d->urutanDaftar());
    }
}
