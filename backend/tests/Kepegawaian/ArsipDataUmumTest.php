<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Lampiran\ArsipDataUmum;
use App\Libraries\Kepegawaian\Lampiran\AttachmentService;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Arsip Kepegawaian = arsip Data Umum (MAKE-014, keputusan user 13-10-2026 Q-M6-6) lewat endpoint lampiran B-18 dengan
 * `id_entri` = NIP: kode 38 arsip_du (unggah/hapus 1, 2/6/7 milik sendiri, 3 lingkup; `tag` jenis arsip legacy) dan
 * kode 37 status_du (unggah & hapus role 1); lihat/unduh semua yang boleh melihat pegawai; baris legacy id_entri NULL
 * ikut terbaca. PegawaiScope NYATA.
 *
 * @internal
 */
final class ArsipDataUmumTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;
    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('attachmentService', new AttachmentService(new LocalStorageAdapter($this->akarLampiranUji())));
        $this->satker = $this->buatSatker();
        $this->nip    = $this->buatPegawaiDiSatker($this->satker);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::reset(true);

        parent::tearDown();
    }

    public function testPegawaiMengelolaArsipDuMiliknyaDenganJenis(): void
    {
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);

        $this->assertArrayHasKey('jenis_arsip', $this->galat($this->unggah($pegawai, $this->nip, 38, ['jenis_arsip' => 'SIM'])));
        $this->assertArrayHasKey('nama_arsip_lain', $this->galat($this->unggah($pegawai, $this->nip, 38, ['jenis_arsip' => 'Lain-lain'])));

        $res = $this->unggah($pegawai, $this->nip, 38, ['jenis_arsip' => 'KTP']);
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->response()->getBody());
        $this->assertSame(['KTP', $this->nip], [$this->json($res)['data']['tag'], $this->json($res)['data']['id_entri']]);
        $lain = (int) $this->json($this->unggah($pegawai, $this->nip, 38, ['jenis_arsip' => 'Lain-lain', 'nama_arsip_lain' => 'Surat Nikah']))['data']['id_attachment'];

        // Baris legacy ber-id_entri NULL ikut terbaca.
        $this->db->table('document_attachment')->insert(['NIP' => $this->nip, 'id_riwayat' => 38, 'nama_riwayat' => 'Arsip Data Umum', 'id_entri' => null, 'tag' => 'NPWP', 'display_name' => 'npwp.pdf']);
        $daftar = $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=38&id_entri={$this->nip}");
        $daftar->assertStatus(200);
        $this->assertSame(['KTP', 'Surat Nikah', 'NPWP'], array_column($this->json($daftar)['data'], 'tag'));

        $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran/{$lain}/unduh")->assertStatus(200);
        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$lain}")->assertStatus(200);

        // id_entri harus NIP pegawai itu.
        $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=38&id_entri=1")->assertStatus(404);
    }

    public function testHakTulisPerKodeDanRole(): void
    {
        $lain    = $this->buatPegawaiDiSatker($this->buatSatker());
        $super   = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        $admin3  = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::ADMIN_SATKER);
        $menteri = $this->akun($this->buatPegawai(), Role::MENTERI);

        // Status DU (37): hanya role 1.
        $this->assertSame(403, $this->unggah($pegawai, $this->nip, 37, [])->response()->getStatusCode());
        $this->assertSame(403, $this->unggah($admin3, $this->nip, 37, [])->response()->getStatusCode());
        $status = $this->unggah($super, $this->nip, 37, ['nama_arsip' => 'SK Pensiun']);
        $status->assertStatus(201);
        $this->assertSame('SK Pensiun', $this->json($status)['data']['tag']);
        $idStatus = (int) $this->json($status)['data']['id_attachment'];
        $this->assertSame('Arsip Status', $this->json($this->unggah($super, $this->nip, 37, []))['data']['tag']);

        // Arsip DU (38): pegawai bukan miliknya 403; role 3 dalam lingkup boleh, di luar lingkup 403; role 4 tidak.
        $this->assertSame(403, $this->unggah($pegawai, $lain, 38, ['jenis_arsip' => 'KTP'])->response()->getStatusCode());
        $this->unggah($admin3, $this->nip, 38, ['jenis_arsip' => 'BPJS'])->assertStatus(201);
        $this->assertSame(403, $this->unggah($admin3, $lain, 38, ['jenis_arsip' => 'BPJS'])->response()->getStatusCode());
        $this->assertSame(403, $this->unggah($menteri, $this->nip, 38, ['jenis_arsip' => 'KTP'])->response()->getStatusCode());

        // Lihat & unduh: siapa pun yang boleh melihat pegawai (role 4 juga); hapus status hanya role 1.
        $this->asUser($menteri)->get("api/v1/pegawai/{$this->nip}/lampiran/{$idStatus}/unduh")->assertStatus(200);
        $this->asUser($menteri)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=37&id_entri={$this->nip}")->assertStatus(200);
        $this->asUser($admin3)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$idStatus}")->assertStatus(403);
        $this->asUser($menteri)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$idStatus}")->assertStatus(403);
        $this->asUser($super)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$idStatus}")->assertStatus(200);

        // Ekstensi per kode: 38 tanpa docx (legacy jpg/jpeg/png/pdf).
        $this->assertSame(['jpg', 'jpeg', 'png', 'pdf'], ArsipDataUmum::aturan(38)->ekstensi);
        $this->assertContains('docx', ArsipDataUmum::aturan(37)->ekstensi);
    }

    public function testPegawaiLainTidakMelihatArsip(): void
    {
        $this->db->table('document_attachment')->insert(['NIP' => $this->nip, 'id_riwayat' => 38, 'nama_riwayat' => 'Arsip Data Umum', 'id_entri' => $this->nip, 'tag' => 'KTP', 'display_name' => 'ktp.pdf']);
        $id   = (int) $this->db->insertID();
        $lain = $this->akun($this->buatPegawai(), Role::PEGAWAI);

        $this->asUser($lain)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=38&id_entri={$this->nip}")->assertStatus(403);
        $this->asUser($lain)->get("api/v1/pegawai/{$this->nip}/lampiran/{$id}/unduh")->assertStatus(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function akun(string $nip, int $role): array
    {
        $id = $this->buatAkunUntuk($nip, $role);

        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function galat(TestResponse $res): array
    {
        $res->assertStatus(422);

        return $this->json($res)['errors'];
    }

    /**
     * @param array<string, mixed> $akun
     * @param array<string, string> $isian
     */
    private function unggah(array $akun, string $nip, int $kode, array $isian, ?UploadedFile $berkas = null): TestResponse
    {
        $berkas ??= $this->berkasPdf('arsip.pdf');
        service('superglobals')->setFilesArray(['berkas' => [
            'name'     => $berkas->getClientName(),
            'type'     => $berkas->getClientMimeType(),
            'tmp_name' => $berkas->getTempName(),
            'error'    => $berkas->getError(),
            'size'     => (int) filesize($berkas->getTempName()),
        ]]);

        try {
            return $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token'],
                'Content-Type'  => 'multipart/form-data; boundary=x',
            ])->post("api/v1/pegawai/{$nip}/lampiran", ['id_riwayat' => (string) $kode, 'id_entri' => $nip] + $isian);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
        }
    }
}
