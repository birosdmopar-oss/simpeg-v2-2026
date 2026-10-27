<?php

declare(strict_types=1);

namespace Tests\Layanan\Dashboard;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\DashboardUjiTrait;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\SisipMinimalTrait;

/**
 * Lingkup `GET layanan/status` per role (PRD Fase 4 §6.10.1, AC-10.1): role 1–7 200, role 8 403; role 2/6/7 hanya
 * pengajuan sendiri dari modul yang boleh dilihat (6: cuti + TB, 7: cuti + IB); role 3 hanya lingkup satker; role 4/5
 * semua tanpa aksi "Lihat detail".
 *
 * @internal
 */
final class LayananStatusRoleTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DashboardUjiTrait;
    use FeatureTestTrait;
    use LayananStudiUjiTrait;
    use SisipMinimalTrait;

    protected $seed = MasterDataSeeder::class;

    private int $satker;

    /** NIP pemilik data di satker admin. */
    private string $dalam;

    /** NIP pemilik data di satker lain. */
    private string $luar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();

        $this->satker  = $this->buatSatker();
        [$this->dalam] = $this->aktorStudi(Role::PEGAWAI, [], $this->satker);
        [$this->luar]  = $this->aktorStudi(Role::PEGAWAI);

        foreach ([$this->dalam, $this->luar] as $nip) {
            $this->sisipCuti($nip);
            $this->sisipStudi('tb', $nip);
            $this->sisipStudi('ib', $nip);
            $this->sisipPmk($nip);
        }
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testRole1Sampai7Boleh200DanRole8Ditolak403(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK] as $role) {
            [$nip] = $this->aktorStudi($role);
            $this->sebagai($nip)->get('api/v1/layanan/status')->assertStatus(200);
        }

        [$pimpinan] = $this->aktorStudi(Role::PIMPINAN);
        $this->sebagai($pimpinan)->get('api/v1/layanan/status')->assertStatus(403);
    }

    public function testPegawaiHanyaMelihatPengajuanSendiri(): void
    {
        $data = $this->data($this->dalam);

        $this->assertSame(4, $data['total']);
        $this->assertSame([$this->dalam], array_values(array_unique(array_column($data['items'], 'nip'))), 'data NIP lain tidak muncul');
    }

    public function testPttHanyaCutiDanTbPppkHanyaCutiDanIb(): void
    {
        [$ptt] = $this->aktorStudi(Role::PTT);
        $this->sisipCuti($ptt);
        $this->sisipStudi('tb', $ptt);
        $this->sisipStudi('ib', $ptt);
        $this->sisipPmk($ptt);

        $data = $this->data($ptt);
        $this->assertEqualsCanonicalizing(['cuti', 'tb'], array_column($data['items'], 'jenis'));
        $this->assertSame(['cuti', 'tb'], $data['jenis']);
        $this->assertSame(0, $this->data($ptt, '?jenis=ib')['total'], 'jenis di luar modul role → kosong');

        [$pppk] = $this->aktorStudi(Role::PPPK);
        $this->sisipCuti($pppk);
        $this->sisipStudi('tb', $pppk);
        $this->sisipStudi('ib', $pppk);

        $this->assertEqualsCanonicalizing(['cuti', 'ib'], array_column($this->data($pppk)['items'], 'jenis'));
    }

    public function testAdminSatkerHanyaLingkupDanBolehBukaDetail(): void
    {
        [$admin] = $this->aktorStudi(Role::ADMIN_SATKER, [], $this->satker);

        $data = $this->data($admin);

        $this->assertSame(4, $data['total']);
        $this->assertSame([$this->dalam], array_values(array_unique(array_column($data['items'], 'nip'))));

        foreach ($data['items'] as $item) {
            $this->assertTrue($item['aksi']['lihat_detail'], $item['jenis']);
        }
    }

    public function testSuperAdminSemuaDanRole4Role5TanpaLihatDetail(): void
    {
        [$admin] = $this->aktorStudi(Role::SUPER_ADMIN);
        $this->assertSame(8, $this->data($admin)['total']);

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            [$nip] = $this->aktorStudi($role);
            $data  = $this->data($nip);

            $this->assertSame(8, $data['total'], "role {$role} memantau semua (PegawaiScope lihat semua)");

            foreach ($data['items'] as $item) {
                $this->assertSame(['lihat_detail' => false], $item['aksi']);
                $this->assertNull($item['url_detail']);
            }
        }
    }

    public function testDetailModulTetapMenolakRole4Dan5(): void
    {
        $jalur = [
            'api/v1/cuti/' . $this->sisipCuti($this->luar),
            'api/v1/tb/' . $this->sisipStudi('tb', $this->luar),
            'api/v1/ib/' . $this->sisipStudi('ib', $this->luar),
            'api/v1/pmk/pengajuan/' . $this->sisipPmk($this->luar),
        ];

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            [$nip] = $this->aktorStudi($role);

            foreach ($jalur as $path) {
                $this->sebagai($nip)->get($path)->assertStatus(403);
            }
        }
    }

    public function testRole8DitolakPadaSemuaRouteLayanan(): void
    {
        [$pimpinan] = $this->aktorStudi(Role::PIMPINAN);

        $this->sebagai($pimpinan)->get('api/v1/layanan/jenis')->assertStatus(403);
        $this->sebagai($pimpinan)->get('api/v1/layanan/petugas')->assertStatus(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $nip, string $query = ''): array
    {
        $res = $this->sebagai($nip)->get('api/v1/layanan/status' . $query);
        $res->assertStatus(200);

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    private function sebagai(string $nip): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }
}
