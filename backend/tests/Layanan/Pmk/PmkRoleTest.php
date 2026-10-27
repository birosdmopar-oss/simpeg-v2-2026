<?php

declare(strict_types=1);

namespace Tests\Layanan\Pmk;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-09 lewat HTTP (PRD Fase 4 §5.4, §6.9.3 AC-09.4; DoD 072-4): role 4, 5, 6, 7, 8 → 403 di SETIAP endpoint PMK (token
 * sah); role 3 tidak bisa menulis (403); kalkulator `hitung-mker` menjawab JSON.
 *
 * @internal
 */
final class PmkRoleTest extends DatabaseTestCase
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
    public static function routePmk(): array
    {
        return [
            'GET pmk/opsi'                    => ['get', 'api/v1/pmk/opsi'],
            'POST pmk/hitung-mker'            => ['post', 'api/v1/pmk/hitung-mker'],
            'GET pmk/pengajuan'               => ['get', 'api/v1/pmk/pengajuan'],
            'POST pmk/pengajuan'              => ['post', 'api/v1/pmk/pengajuan'],
            'GET pmk/pengajuan/{id}'          => ['get', 'api/v1/pmk/pengajuan/1'],
            'PUT pmk/pengajuan/{id}'          => ['put', 'api/v1/pmk/pengajuan/1'],
            'DELETE pmk/pengajuan/{id}'       => ['delete', 'api/v1/pmk/pengajuan/1'],
            'POST pmk/pengajuan/{id}/process' => ['post', 'api/v1/pmk/pengajuan/1/process'],
            'GET pmk'                         => ['get', 'api/v1/pmk'],
            'PUT pmk/{id}'                    => ['put', 'api/v1/pmk/1'],
            'DELETE pmk/{id}/arsip/{id}'      => ['delete', 'api/v1/pmk/1/arsip/1'],
            'DELETE pmk/{id}'                 => ['delete', 'api/v1/pmk/1'],
        ];
    }

    #[DataProvider('routePmk')]
    public function testRole4Sampai8Ditolak403DiSetiapEndpoint(string $method, string $path): void
    {
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            [$nip] = $this->aktorStudi($role);
            $this->sebagai($nip)->call($method, $path)->assertStatus(403);
        }
    }

    public function testRole3TidakBisaMengajukan(): void
    {
        [$nip] = $this->aktorStudi(Role::ADMIN_SATKER);
        [$pns] = $this->aktorStudi(Role::PEGAWAI);

        $this->sebagai($nip)->post('api/v1/pmk/pengajuan', ['nip' => $pns, 'jenis_pmk' => 'Swasta'])->assertStatus(403);
        $this->sebagai($nip)->get('api/v1/pmk/pengajuan')->assertStatus(200);
    }

    public function testHitungMker(): void
    {
        [$nip] = $this->aktorStudi(Role::PEGAWAI);

        $res = $this->sebagai($nip)->withBodyFormat('json')->post('api/v1/pmk/hitung-mker', ['tgl_awal' => '2010-01-01', 'tgl_akhir' => '2012-06-30']);

        $res->assertStatus(200);
        $this->assertSame(['mker_th' => 2, 'mker_bl' => 5, 'peringatan' => null], $this->json($res)['data']);
    }

    private function sebagai(string $nip): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }
}
