<?php

declare(strict_types=1);

namespace Tests\Layanan\Dashboard;

use App\Constants\Role;
use App\Libraries\CacheService;
use App\Libraries\Layanan\PetugasLayananService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\DashboardUjiTrait;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\SisipMinimalTrait;

/**
 * CRUD PIC `layanan/petugas` + `layanan/jenis` (PRD Fase 4 §6.10.2, AC-10.2): tulis role 1 saja (role 2–7 → 403);
 * kandidat PNS aktif satker kepegawaian (Web Config); jenis layanan aktif; duplikat → 422 `errors.nip`; ubah status
 * mengubah hasil `adalahPic`; hapus fisik + audit; daftar role 1 semua status, role lain hanya PIC aktif.
 *
 * @internal
 */
final class PetugasLayananCrudTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DashboardUjiTrait;
    use FeatureTestTrait;
    use LayananStudiUjiTrait;
    use SisipMinimalTrait;

    protected $seed = MasterDataSeeder::class;

    private string $admin;

    private int $satkerKepeg;

    /** PNS aktif di satker kepegawaian. */
    private string $kandidat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        $this->resetWebConfig();

        $this->satkerKepeg = $this->buatSatker();
        service('webConfigService')->set('id_satker_kepegawaian', ['config_value' => (string) $this->satkerKepeg]);

        [$this->admin]    = $this->aktorStudi(Role::SUPER_ADMIN);
        [$this->kandidat] = $this->aktorStudi(Role::PEGAWAI, [], $this->satkerKepeg);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testRole1MenunjukPicDanTercatatAudit(): void
    {
        $res = $this->sebagai($this->admin)->withBodyFormat('json')->post('api/v1/layanan/petugas', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat]);

        $res->assertStatus(201);
        $item = $this->json($res);
        $this->assertSame(3, $item['id_jenis_layanan']);
        $this->assertSame('Tugas Belajar', $item['jenis_layanan']);
        $this->assertSame($this->kandidat, $item['nip']);
        $this->assertSame(1, $item['status']);
        $this->assertSame('Aktif', $item['label_status']);
        $this->assertNotNull($item['nama']);
        $this->assertSame(['ubah' => true, 'aktifkan' => false, 'nonaktifkan' => true, 'hapus' => true], $item['aksi']);
        $this->assertTrue((new PetugasLayananService())->adalahPic($this->kandidat, PetugasLayananService::JENIS_TB));

        $audit = $this->db->table('audit_logs')->where('entity', 'petugas_layanan')->where('entity_id', (string) $item['id_petugas_layanan'])->where('event', 'create')->countAllResults();
        $this->assertSame(1, $audit);
    }

    public function testRole2Sampai7Ditolak403PadaTulis(): void
    {
        $this->jadikanPic($this->kandidat, PetugasLayananService::JENIS_IB);
        $id = (int) $this->db->insertID();

        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK] as $role) {
            [$nip] = $this->aktorStudi($role);

            $this->sebagai($nip)->withBodyFormat('json')->post('api/v1/layanan/petugas', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat])->assertStatus(403);
            $this->sebagai($nip)->withBodyFormat('json')->put("api/v1/layanan/petugas/{$id}", ['status' => 0])->assertStatus(403);
            $this->sebagai($nip)->delete("api/v1/layanan/petugas/{$id}")->assertStatus(403);
        }

        $this->assertSame(1, $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->where('status', 1)->countAllResults());
    }

    public function testValidasiKandidatJenisStatusDanDuplikat(): void
    {
        [$luarSatker] = $this->aktorStudi(Role::PEGAWAI);
        [$pppk]       = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 2], $this->satkerKepeg);
        [$nonaktif]   = $this->aktorStudi(Role::PEGAWAI, ['status' => 2], $this->satkerKepeg);

        foreach ([$luarSatker, $pppk, $nonaktif, '000'] as $nip) {
            $this->harap($this->tulis('post', '', ['id_jenis_layanan' => 3, 'nip' => $nip]), 422, ['errors' => ['nip' => ['Petugas harus PNS aktif di satker kepegawaian.']]]);
        }

        $this->db->table('jenis_layanan')->where('id_jenis_layanan', 1)->update(['status' => 2]);
        $this->harap($this->tulis('post', '', ['id_jenis_layanan' => 1, 'nip' => $this->kandidat]), 422, ['errors' => ['id_jenis_layanan' => ['Layanan tidak ditemukan atau tidak aktif.']]]);
        $this->tulis('post', '', ['id_jenis_layanan' => 99, 'nip' => $this->kandidat])->assertStatus(422);
        $this->tulis('post', '', ['nip' => $this->kandidat])->assertStatus(422);
        $this->harap($this->tulis('post', '', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat, 'status' => 10]), 422, ['errors' => ['status' => ['Status harus 1 (Aktif) atau 0 (Tidak Aktif).']]]);

        $this->tulis('post', '', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat])->assertStatus(201);
        $this->harap($this->tulis('post', '', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat]), 422, ['errors' => ['nip' => [PetugasLayananService::PESAN_DUPLIKAT]]]);
        $this->tulis('post', '', ['id_jenis_layanan' => 4, 'nip' => $this->kandidat])->assertStatus(201);
    }

    public function testSatkerKepegawaianBelumDiatur422(): void
    {
        service('webConfigService')->delete('id_satker_kepegawaian');

        $this->harap($this->tulis('post', '', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat]), 422, ['errors' => ['nip' => ['Satker kepegawaian belum diatur di Web Config; PIC belum dapat ditunjuk.']]]);
    }

    public function testUbahStatusMengubahPicAktifDanDuplikatSaatPindahJenis(): void
    {
        $id  = $this->json($this->tulis('post', '', ['id_jenis_layanan' => 3, 'nip' => $this->kandidat]))['id_petugas_layanan'];
        $pic = new PetugasLayananService();

        $res = $this->tulis('put', "/{$id}", ['status' => 0]);
        $res->assertStatus(200);
        $this->assertSame(['ubah' => true, 'aktifkan' => true, 'nonaktifkan' => false, 'hapus' => true], $this->json($res)['aksi']);
        $this->assertFalse($pic->adalahPic($this->kandidat, PetugasLayananService::JENIS_TB));

        // Nonaktifkan PIC yang kini bukan kandidat tetap boleh (nip tidak berubah → kandidat tidak diperiksa ulang).
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->kandidat)->update(['id_satker' => $this->buatSatker()]);
        $this->tulis('put', "/{$id}", ['status' => '1'])->assertStatus(200);
        $this->assertTrue($pic->adalahPic($this->kandidat, PetugasLayananService::JENIS_TB));

        [$lain] = $this->aktorStudi(Role::PEGAWAI, [], $this->satkerKepeg);
        $this->tulis('post', '', ['id_jenis_layanan' => 4, 'nip' => $lain])->assertStatus(201);
        $this->harap($this->tulis('put', "/{$id}", ['id_jenis_layanan' => 4, 'nip' => $lain]), 422, ['errors' => ['nip' => [PetugasLayananService::PESAN_DUPLIKAT]]]);

        $this->tulis('put', '/999999', ['status' => 0])->assertStatus(404);
    }

    public function testHapusFisikDenganAudit(): void
    {
        $id = $this->json($this->tulis('post', '', ['id_jenis_layanan' => 5, 'nip' => $this->kandidat]))['id_petugas_layanan'];

        $this->harap($this->sebagai($this->admin)->delete("api/v1/layanan/petugas/{$id}"), 200, ['data' => ['deleted' => true, 'soft_delete' => false]]);

        $this->assertSame(0, $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->countAllResults());
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'petugas_layanan')->where('entity_id', (string) $id)->where('event', 'delete')->countAllResults());
        $this->sebagai($this->admin)->delete("api/v1/layanan/petugas/{$id}")->assertStatus(404);
    }

    public function testDaftarRole1SemuaStatusRoleLainHanyaAktif(): void
    {
        [$nonPic] = $this->aktorStudi(Role::PEGAWAI, [], $this->satkerKepeg);
        $this->jadikanPic($this->kandidat, PetugasLayananService::JENIS_TB);
        $this->jadikanPic($nonPic, PetugasLayananService::JENIS_IB, 0);

        $admin = $this->daftar($this->admin);
        $this->assertSame(2, $admin['total']);
        $this->assertTrue($admin['can_create']);
        $this->assertSame([1, 0], array_column($admin['items'], 'status'), 'urut status DESC');
        $this->assertSame(1, $this->daftar($this->admin, '?status=0')['total']);
        $this->assertSame([$nonPic], array_column($this->daftar($this->admin, '?id_jenis_layanan=4')['items'], 'nip'));

        [$pegawai] = $this->aktorStudi(Role::PEGAWAI);
        $lihat     = $this->daftar($pegawai);
        $this->assertSame([$this->kandidat], array_column($lihat['items'], 'nip'));
        $this->assertFalse($lihat['can_create']);
        $this->assertSame(['ubah' => false, 'aktifkan' => false, 'nonaktifkan' => false, 'hapus' => false], $lihat['items'][0]['aksi']);
        $this->assertSame(1, $this->daftar($pegawai, '?status=0')['total'], 'role non-1 tidak bisa melihat PIC nonaktif');
    }

    public function testJenisLayananAktif(): void
    {
        $this->db->table('jenis_layanan')->where('id_jenis_layanan', 2)->update(['status' => 2]);
        [$pegawai] = $this->aktorStudi(Role::PTT);

        $res = $this->sebagai($pegawai)->get('api/v1/layanan/jenis');
        $res->assertStatus(200);
        $this->assertSame([1, 3, 4, 5], array_column($this->json($res), 'id_jenis_layanan'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function tulis(string $verb, string $path, array $body): \CodeIgniter\Test\TestResponse
    {
        return $this->sebagai($this->admin)->withBodyFormat('json')->{$verb}('api/v1/layanan/petugas' . $path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function daftar(string $nip, string $query = ''): array
    {
        $res = $this->sebagai($nip)->get('api/v1/layanan/petugas' . $query);
        $res->assertStatus(200);

        return $this->json($res);
    }

    /**
     * @return array<mixed>
     */
    private function json(\CodeIgniter\Test\TestResponse $res): array
    {
        return json_decode((string) $res->getJSON(), true)['data'];
    }

    /**
     * @param array<string, mixed> $fragmen
     */
    private function harap(\CodeIgniter\Test\TestResponse $res, int $status, array $fragmen): void
    {
        $res->assertStatus($status);
        $res->assertJSONFragment($fragmen);
    }

    private function resetWebConfig(): void
    {
        foreach (['webConfigService', 'cacheService', 'htmlSanitizer'] as $name) {
            Services::resetSingle($name);
        }

        Services::injectMock('cacheService', new CacheService(service('cache'), ''));
        service('cache')->delete('web_config_values');
    }

    private function sebagai(string $nip): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }
}
