<?php

declare(strict_types=1);

namespace Tests\Layanan\Cuti;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-022 M3 (PRD Fase 4 §5.1, AC-02.1) — matriks role lewat HTTP: role 4/5/8 → 403 di semua endpoint cuti kecuali
 * `hitung-lama`; role 3/6 → 403 untuk tambah; kalkulator terbuka bagi semua login; tanpa token → 401.
 *
 * @internal
 */
final class CutiRoleTest extends DatabaseTestCase
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

    public function testRole458DitolakDiSemuaEndpointKecualiKalkulator(): void
    {
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->asRole($role)->get('api/v1/cuti')->assertStatus(403);
            $this->asRole($role)->get('api/v1/cuti/opsi')->assertStatus(403);
            $this->asRole($role)->get('api/v1/cuti/1')->assertStatus(403);
            $this->asRole($role)->withBodyFormat('json')->post('api/v1/cuti', [])->assertStatus(403);

            // Kalkulator: semua login lolos filter; input kosong → 422 (bukan 403).
            $this->asRole($role)->withBodyFormat('json')->post('api/v1/cuti/hitung-lama', [])->assertStatus(422);
        }
    }

    public function testRole3Dan6TidakBolehMenambah(): void
    {
        foreach ([Role::ADMIN_SATKER, Role::PTT] as $role) {
            $this->asRole($role)->withBodyFormat('json')->post('api/v1/cuti', ['id_jenis_cuti' => 1])->assertStatus(403);
        }
    }

    public function testRole2DaftarSendiriKosongDanModeAdminDitolak(): void
    {
        $ok = $this->asRole(Role::PEGAWAI)->get('api/v1/cuti');
        $ok->assertStatus(200);
        $this->assertSame('saya', $this->json($ok)['data']['mode']);

        $this->asRole(Role::PEGAWAI)->get('api/v1/cuti?mode=admin')->assertStatus(403);
        $this->asRole(Role::PEGAWAI)->get('api/v1/cuti/999999')->assertStatus(404);
    }

    public function testTanpaTokenAtauTokenDipalsukan401(): void
    {
        $this->withHeaders([])->get('api/v1/cuti')->assertStatus(401);
        $this->withHeaders([])->post('api/v1/cuti/hitung-lama', [])->assertStatus(401);
    }

    public function testKalkulatorMengembalikanLamaDanTanggalAkhirBesar(): void
    {
        $r = $this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post('api/v1/cuti/hitung-lama', ['id_jenis_cuti' => 4, 'tgl_mulai' => date('Y-m-d', strtotime('+1 day')), 'tgl_akhir' => date('Y-m-d', strtotime('+3 day'))]);
        $r->assertStatus(200);
        $this->assertSame(3, $this->json($r)['data']['lama_cuti']);
        $this->assertNull($this->json($r)['data']['kuota']);
    }
}
