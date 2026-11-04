<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.5.1, AC-05.4), diselaraskan F6-18 K1 (keputusan Horii 03-11-2026) — `POST cuti/{id}/esign`
 * lewat HTTP: semua role login lolos filter (baris tidak ada → 404); hak per baris = penanda tangan pemilik tugas (403
 * selainnya) diuji di {@see EsignCutiTest}; tanpa token → 401.
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

    public function testSemuaRoleLolosFilterBarisTidakAdaMengembalikan404(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $this->asRole($role)->withBodyFormat('json')->post('api/v1/cuti/999999/esign', ['passphrase' => 'x'])->assertStatus(404);
        }
    }

    public function testTanpaTokenDitolak401(): void
    {
        $this->withHeaders([])->post('api/v1/cuti/1/esign', [])->assertStatus(401);
    }
}
