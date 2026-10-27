<?php

declare(strict_types=1);

namespace Tests\Layanan\Ib;

use App\Constants\Role;
use App\Libraries\Layanan\Ib\IbApprovalService;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-08 lewat HTTP (PRD Fase 4 §5.3, §6.8.3 AC-08.3; DoD 071-3): role 6 → 403 di SETIAP endpoint IB (token sah), juga
 * role 4/5/8; surat IB tersimpan diunduh sebagai PDF lewat `GET ib/{id}/surat`; sebelum disetujui 404.
 *
 * @internal
 */
final class IbRoleTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routeIb(): array
    {
        return [
            'GET ib'                    => ['get', 'api/v1/ib'],
            'GET ib/opsi'               => ['get', 'api/v1/ib/opsi'],
            'POST ib'                   => ['post', 'api/v1/ib'],
            'GET ib/{id}'               => ['get', 'api/v1/ib/1'],
            'PUT ib/{id}'               => ['put', 'api/v1/ib/1'],
            'DELETE ib/{id}'            => ['delete', 'api/v1/ib/1'],
            'POST ib/{id}/process'      => ['post', 'api/v1/ib/1/process'],
            'GET ib/{id}/lampiran/{id}' => ['get', 'api/v1/ib/1/lampiran/1'],
            'GET ib/{id}/surat'         => ['get', 'api/v1/ib/1/surat'],
        ];
    }

    #[DataProvider('routeIb')]
    public function testRole6Ditolak403DiSetiapEndpoint(string $method, string $path): void
    {
        foreach ([Role::PTT, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            [$nip] = $this->aktorStudi($role);
            $this->sebagai($nip)->call($method, $path)->assertStatus(403);
        }
    }

    public function testUnduhSuratIbSesudahDisetujui(): void
    {
        [$nip, $auth]   = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6]);
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_IB);
        $id = (int) (new IbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_IB))['id_riwayat_ib'];

        $this->sebagai($nip)->get("api/v1/ib/{$id}/surat")->assertStatus(404);

        (new IbApprovalService())->proses($pic, $id, ['aksi' => 'setujui', 'no_sk' => 'SK/IB/1', 'tgl_sk' => '2026-10-21', 'ds_ib' => 'Surat Kampus', 'no_ds_ib' => 'K/1']);

        $res = $this->sebagai($nip)->get("api/v1/ib/{$id}/surat");
        $res->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $res->response()->getBody());
    }

    private function sebagai(string $nip): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }
}
