<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §6.1–6.2 (AC-D11.2): route Slip Gaji × role 1–8 — jalur pegawai `slip-gaji/saya*` hanya 2/6/7 (1/3/4/5/8 → 403,
 * admin melihat slip lewat jalur admin); jalur admin `slip-gaji`, `slip-gaji/{id}*` hanya 1/3 (2/4/5/6/7/8 → 403);
 * tanpa token → 401.
 *
 * @internal
 */
final class RoleMatriksSlipTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    private const PEGAWAI = [
        ['get', 'api/v1/slip-gaji/saya'],
        ['get', 'api/v1/slip-gaji/saya/2026-09'],
        ['post', 'api/v1/slip-gaji/saya/2026-09/buka'],
        ['get', 'api/v1/slip-gaji/saya/2026-09/pdf'],
    ];

    private const ADMIN = [
        ['get', 'api/v1/slip-gaji?periode=2026-09'],
        ['get', 'api/v1/slip-gaji/1'],
        ['put', 'api/v1/slip-gaji/1'],
        ['post', 'api/v1/slip-gaji/1/buka-ulang'],
        ['get', 'api/v1/slip-gaji/1/pdf'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testMatriksRoute(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $akun = $this->buatAkunTanpaNip('rs' . $role . bin2hex(random_bytes(3)), $role, ['nip' => in_array($role, Role::UL_PEGAWAI, true) ? $this->pegawaiSlip()['nip'] : null, 'id_satker' => 'S01', 'must_change_password' => 0]);

            foreach (self::PEGAWAI as [$verb, $url]) {
                $status = $this->asUser($akun)->withBodyFormat('json')->{$verb}($url)->response()->getStatusCode();
                in_array($role, Role::UL_PEGAWAI, true)
                    ? $this->assertNotSame(403, $status, "role {$role} {$url}")
                    : $this->assertSame(403, $status, "role {$role} {$url}");
            }

            foreach (self::ADMIN as [$verb, $url]) {
                $status = $this->asUser($akun)->withBodyFormat('json')->{$verb}($url)->response()->getStatusCode();
                $role === Role::SUPER_ADMIN || $role === Role::ADMIN_SATKER
                    ? $this->assertNotSame(403, $status, "role {$role} {$url}")
                    : $this->assertSame(403, $status, "role {$role} {$url}");
            }
        }

        $this->clearAuthState();

        foreach ([...self::PEGAWAI, ...self::ADMIN] as [$verb, $url]) {
            $this->assertSame(401, $this->withHeaders([])->{$verb}($url)->response()->getStatusCode(), $url);
        }
    }
}
