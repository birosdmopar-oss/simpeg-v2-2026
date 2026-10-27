<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Tb\TbService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-07 lewat HTTP (PRD Fase 4 §5.2, §6.7.7 AC-07.4; DoD 070-4): role 7 → 403 di SETIAP endpoint TB (per route, dengan
 * token sah); role 4/5/8 → 403; alur multipart nyata (tambah 201 → detail → surat PDF); unduh lampiran baris lain → 404;
 * envelope error per field.
 *
 * @internal
 */
final class TbRoleTest extends DatabaseTestCase
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
        service('superglobals')->setFilesArray([]);
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routeTb(): array
    {
        return [
            'GET tb'                      => ['get', 'api/v1/tb'],
            'GET tb/opsi'                 => ['get', 'api/v1/tb/opsi'],
            'POST tb'                     => ['post', 'api/v1/tb'],
            'GET tb/{id}'                 => ['get', 'api/v1/tb/1'],
            'PUT tb/{id}'                 => ['put', 'api/v1/tb/1'],
            'DELETE tb/{id}'              => ['delete', 'api/v1/tb/1'],
            'POST tb/{id}/process'        => ['post', 'api/v1/tb/1/process'],
            'POST tb/{id}/perpanjangan'   => ['post', 'api/v1/tb/1/perpanjangan'],
            'PUT tb/perpanjangan/{id}'    => ['put', 'api/v1/tb/perpanjangan/1'],
            'DELETE tb/perpanjangan/{id}' => ['delete', 'api/v1/tb/perpanjangan/1'],
            'GET tb/{id}/lampiran/{id}'   => ['get', 'api/v1/tb/1/lampiran/1'],
            'GET tb/{id}/surat'           => ['get', 'api/v1/tb/1/surat'],
        ];
    }

    #[DataProvider('routeTb')]
    public function testRole7Ditolak403DiSetiapEndpoint(string $method, string $path): void
    {
        foreach ([Role::PPPK, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            [$nip] = $this->aktorStudi($role);
            $res   = $this->sebagai($nip, $role)->call($method, $path);

            $res->assertStatus(403);
            $this->assertSame('error', $this->json($res)['status'], "role {$role} {$method} {$path}");
        }
    }

    public function testAlurMultipartTambahDetailDanSurat(): void
    {
        [$nip]  = $this->aktorStudi(Role::PEGAWAI);
        [$su]   = $this->aktorStudi(Role::SUPER_ADMIN);
        $berkas = $this->berkasSemuaSlot(AturanStudi::JENIS_TB);

        $res = $this->unggah($nip, Role::PEGAWAI, 'api/v1/tb', $this->isianStudi(), $berkas);
        $res->assertStatus(201);
        $data = $this->json($res)['data'];
        $this->assertSame([$nip, 0], [$data['nip'], (int) $data['status']]);

        $id = (int) $data['id_riwayat_tb'];
        $this->sebagai($nip, Role::PEGAWAI)->get("api/v1/tb/{$id}")->assertStatus(200);
        $this->sebagai($nip, Role::PEGAWAI)->get("api/v1/tb/{$id}/surat")->assertStatus(404);

        $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->update(['status' => 1] + $this->dataSkTb());
        $surat = $this->sebagai($su, Role::SUPER_ADMIN)->get("api/v1/tb/{$id}/surat");
        $surat->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $surat->response()->getBody());
        $this->assertSame('nosniff', $surat->response()->getHeaderLine('X-Content-Type-Options'));
    }

    public function testValidasiMultipartMenjawab422PerField(): void
    {
        [$nip] = $this->aktorStudi(Role::PEGAWAI);

        $res = $this->unggah($nip, Role::PEGAWAI, 'api/v1/tb', $this->isianStudi(['institusi_pendidikan' => '']), []);

        $res->assertStatus(422);
        $errors = $this->json($res)['errors'];
        $this->assertArrayHasKey('institusi_pendidikan', $errors);
        $this->assertArrayHasKey('file_sp', $errors);
    }

    public function testUnduhLampiranBarisLain404(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $a            = (int) (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_TB))['id_riwayat_tb'];
        $b            = (int) (new TbService())->tambah($auth, $this->isianStudi(['tgl_mulai' => '2030-01-01', 'tgl_akhir' => '2031-01-01']), $this->berkasSemuaSlot(AturanStudi::JENIS_TB))['id_riwayat_tb'];
        $milikB       = (int) $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_TB)->where('id_entri', (string) $b)->get()->getRow()->id_attachment;

        $this->sebagai($nip, Role::PEGAWAI)->get("api/v1/tb/{$b}/lampiran/{$milikB}")->assertStatus(200);
        $this->sebagai($nip, Role::PEGAWAI)->get("api/v1/tb/{$a}/lampiran/{$milikB}")->assertStatus(404);
    }

    private function sebagai(string $nip, int $role): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }

    /**
     * @param array<string, mixed>        $isian
     * @param array<string, UploadedFile> $berkas
     */
    private function unggah(string $nip, int $role, string $path, array $isian, array $berkas): TestResponse
    {
        $files = [];

        foreach ($berkas as $nama => $file) {
            $files[$nama] = ['name' => $file->getClientName(), 'type' => $file->getClientMimeType(), 'tmp_name' => $file->getTempName(), 'error' => $file->getError(), 'size' => (int) filesize($file->getTempName())];
        }

        service('superglobals')->setFilesArray($files);

        try {
            $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

            return $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token'],
                'Content-Type'  => 'multipart/form-data; boundary=x',
            ])->post($path, $isian);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
        }
    }
}
