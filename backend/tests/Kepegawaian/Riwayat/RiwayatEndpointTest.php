<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * WS-1 M1 (MAKE-004) — lapisan HTTP endpoint riwayat (RoutesRiwayat + RiwayatController): token, slug, envelope,
 * kode status, payload JSON/form, berkas multipart, dan bentuk respons hapus/proses.
 *
 * @internal
 */
final class RiwayatEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private string $nip;

    /**
     * @var array<string, mixed>
     */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngine();
        $this->nip         = $this->buatPegawai();
        [, , $this->admin] = $this->aktor(Role::SUPER_ADMIN);
        $_FILES            = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        $this->lepasEngine();

        parent::tearDown();
    }

    /**
     * Isi $_FILES untuk request berikutnya. CI 4.7 membaca berkas dari service `superglobals` (snapshot bersama), jadi
     * keduanya diisi.
     *
     * @param array<string, array<string, array<int|string, int|string>>> $files
     */
    private function unggah(array $files): void
    {
        $_FILES = $files;
        service('superglobals')->setFilesArray($files);
    }

    public function testTanpaToken401(): void
    {
        $this->get("api/v1/pegawai/{$this->nip}/riwayat/pendidikan")->assertStatus(401);
    }

    public function testSlugDiLuarDaftar404DanSlugTanpaDefinisi404(): void
    {
        try {
            $status = $this->asUser($this->admin)->get("api/v1/pegawai/{$this->nip}/riwayat/konket")->response()->getStatusCode();
        } catch (PageNotFoundException $e) {
            [$status] = ApiExceptionHandler::toEnvelope($e);
        }

        $this->assertSame(404, $status);

        $result = $this->asUser($this->admin)->get("api/v1/pegawai/{$this->nip}/riwayat/kgb");
        $result->assertStatus(404);
        $this->assertSame(['status' => 'error', 'message' => 'Jenis riwayat tidak ditemukan.'], $this->json($result));
    }

    public function testSiklusCrudLewatHttp(): void
    {
        $base = "api/v1/pegawai/{$this->nip}/riwayat/pendidikan";

        $buat = $this->asUser($this->admin)->withBodyFormat('json')->post($base, ['tgl_lulus' => '2012-08-30', 'glr_akhir' => 'S.T.']);
        $buat->assertStatus(201);
        $id = (int) $this->json($buat)['data']['id_riwayat_pendidikan'];

        $daftar = $this->asUser($this->admin)->get($base);
        $daftar->assertStatus(200);
        $this->assertSame([$id], array_map('intval', array_column($this->json($daftar)['data'], 'id_riwayat_pendidikan')));
        $this->assertSame('S.T.', $this->json($this->asUser($this->admin)->get("{$base}/{$id}"))['data']['glr_akhir']);

        $ubah = $this->asUser($this->admin)->withBodyFormat('json')->put("{$base}/{$id}", ['glr_akhir' => 'M.T.']);
        $ubah->assertStatus(200);
        $this->assertSame('M.T.', $this->json($ubah)['data']['glr_akhir']);

        $hapus = $this->asUser($this->admin)->delete("{$base}/{$id}");
        $hapus->assertStatus(200);
        $this->assertSame(['deleted' => true, 'soft_delete' => true], $this->json($hapus)['data']);

        $this->asUser($this->admin)->get("{$base}/{$id}")->assertStatus(404);
    }

    public function testValidasi422BerenvelopeErrorsPerField(): void
    {
        $result = $this->asUser($this->admin)->withBodyFormat('json')->post("api/v1/pegawai/{$this->nip}/riwayat/pendidikan", ['tgl_lulus' => 'kemarin']);

        $result->assertStatus(422);
        $body = $this->json($result);
        $this->assertSame('error', $body['status']);
        $this->assertArrayHasKey('tgl_lulus', $body['errors']);
    }

    public function testProsesAksiDanCatatanHarusTeks(): void
    {
        $id = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2012-08-30', 'status' => 0]);

        $result = $this->asUser($this->admin)->withBodyFormat('json')->post("api/v1/pegawai/{$this->nip}/riwayat/pendidikan/{$id}/process", ['aksi' => ['setujui'], 'reason_note' => 5]);

        $result->assertStatus(422);
        $this->assertSame(['aksi', 'reason_note'], array_keys($this->json($result)['errors']));
    }

    public function testPayloadFormUrlencoded(): void
    {
        $result = $this->asUser($this->admin)->post("api/v1/pegawai/{$this->nip}/riwayat/pendidikan", ['tgl_lulus' => '2012-08-30', 'institusi_pendidikan' => '']);

        $result->assertStatus(201);
        $this->assertNull($this->json($result)['data']['institusi_pendidikan'], "'' pada form = NULL");
    }

    public function testBerkasMultipartKodeTidakValidDanUnggahanGagal(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rwy');
        file_put_contents($path, '%PDF-1.4');

        $this->unggah(['berkas' => [
            'name'     => ['abc' => 'a.pdf', '14' => 'b.pdf'],
            'type'     => ['abc' => 'application/pdf', '14' => 'application/pdf'],
            'tmp_name' => ['abc' => $path, '14' => $path],
            'error'    => ['abc' => UPLOAD_ERR_OK, '14' => UPLOAD_ERR_INI_SIZE],
            'size'     => ['abc' => 8, '14' => 0],
        ]]);

        $result = $this->asUser($this->admin)->post("api/v1/pegawai/{$this->nip}/riwayat/pendidikan", ['tgl_lulus' => '2012-08-30']);

        $result->assertStatus(422);
        $this->assertSame(['berkas.abc', 'berkas.14'], array_keys($this->json($result)['errors']));
        $this->assertSame(0, $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->countAllResults());
    }

    public function testUbahMultipartLewatPostMethodPut(): void
    {
        // Kontrak: ubah dengan berkas = POST multipart + _method=PUT (PHP tidak mem-parse multipart pada PUT).
        $id   = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2012-08-30', 'status' => 1]);
        $path = "api/v1/pegawai/{$this->nip}/riwayat/pendidikan/{$id}";

        $this->unggah(['berkas' => [
            'name'     => ['39' => ''],
            'type'     => ['39' => ''],
            'tmp_name' => ['39' => ''],
            'error'    => ['39' => UPLOAD_ERR_NO_FILE],
            'size'     => ['39' => 0],
        ]]);
        $ubah = $this->asUser($this->admin)->post($path, ['_method' => 'PUT', 'glr_akhir' => 'S.Ak.']);
        $ubah->assertStatus(200);
        $this->assertSame('S.Ak.', $this->json($ubah)['data']['glr_akhir']);

        // Berkas yang gagal diunggah pada jalur yang sama → 422 berkunci kode, baris tidak berubah. (Unggahan valid
        // tidak bisa dibuat di PHPUnit karena is_uploaded_file(); penyimpanan berkas diuji di RiwayatEngineTest.)
        $tmp = (string) tempnam(sys_get_temp_dir(), 'rwy');
        file_put_contents($tmp, '%PDF-1.4');
        $this->unggah(['berkas' => [
            'name'     => ['14' => 'ijazah.pdf'],
            'type'     => ['14' => 'application/pdf'],
            'tmp_name' => ['14' => $tmp],
            'error'    => ['14' => UPLOAD_ERR_PARTIAL],
            'size'     => ['14' => 8],
        ]]);
        // Router shared dari panggilan pertama menyimpan verb lama; FeatureTestTrait tidak membangunnya ulang untuk spoofing.
        Services::resetSingle('router');
        $gagal = $this->asUser($this->admin)->post($path, ['_method' => 'PUT', 'glr_akhir' => 'M.Ak.']);
        $gagal->assertStatus(422);
        $this->assertSame(['berkas.14'], array_keys($this->json($gagal)['errors']));
        $this->assertSame('S.Ak.', $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $id)->get()->getRow()->glr_akhir);
    }

    public function testBerkasKosongDilewati(): void
    {
        $this->unggah(['berkas' => [
            'name'     => ['39' => ''],
            'type'     => ['39' => ''],
            'tmp_name' => ['39' => ''],
            'error'    => ['39' => UPLOAD_ERR_NO_FILE],
            'size'     => ['39' => 0],
        ]]);

        $this->asUser($this->admin)->post("api/v1/pegawai/{$this->nip}/riwayat/pendidikan", ['tgl_lulus' => '2012-08-30'])->assertStatus(201);
        $this->assertSame([], $this->lampiranUji->baris);
    }
}
