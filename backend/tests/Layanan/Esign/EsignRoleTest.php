<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.5.1, AC-05.4) — matriks role `POST cuti/{id}/esign` lewat HTTP: hanya role 1 dan 3;
 * role 2, 4, 5, 6, 7, 8 → 403 (sebelum baris dicari); tanpa token → 401; role 1 dan 3 lolos filter (baris tidak ada → 404).
 * Lingkup role 3 dan alur sukses diuji di {@see EsignCutiTest} (service).
 *
 * @internal
 */
final class EsignRoleTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;

    protected $seed = AuthSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRoleSelainSatuDanTigaDitolak403(): void
    {
        foreach ([Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $this->asRole($role)->withBodyFormat('json')->post('api/v1/cuti/1/esign', ['passphrase' => 'x'])->assertStatus(403);
        }
    }

    public function testRoleSatuDanTigaLolosFilterBarisTidakAdaMengembalikan404(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_SATKER] as $role) {
            $this->asRole($role)->withBodyFormat('json')->post('api/v1/cuti/999999/esign', ['passphrase' => 'x'])->assertStatus(404);
        }
    }

    public function testTanpaTokenDitolak401(): void
    {
        $this->withHeaders([])->post('api/v1/cuti/1/esign', [])->assertStatus(401);
    }
}
