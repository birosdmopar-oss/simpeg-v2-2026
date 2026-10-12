<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Kepegawaian\Lampiran\AttachmentService;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-009 — endpoint `pegawai/{nip}/lampiran*` dengan PegawaiScope + AttachmentService nyata dan Definisi contoh
 * pendidikan (kode 14 wajib, 39/40 tidak wajib; izin 1,2,3,6,7; hapus 1,3). Urutan otorisasi: daftar/unggah = kode →
 * izin Definisi → lingkup → keterikatan id_entri → kunci baris Disetujui; unduh/hapus = lingkup lihat → baris lampiran →
 * izin Definisi (+ lingkup ubah, keterikatan & kunci baris, lampiran wajib terakhir untuk hapus).
 *
 * @internal
 */
final class LampiranEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    /**
     * Baris riwayat pendidikan status 1 Disetujui milik $nip (dibuat admin).
     */
    private int $idEntri;

    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('riwayatRegistry', RiwayatRegistry::dariFolder(
            static fn (): PegawaiScopeInterface => service('pegawaiScope'),
            SUPPORTPATH . 'Kepegawaian/Definisi',
            'Tests\Support\Kepegawaian\Definisi',
        ));
        Services::injectMock('attachmentService', new AttachmentService(new LocalStorageAdapter($this->akarLampiranUji())));

        $this->satker  = $this->buatSatker();
        $this->nip     = $this->buatPegawaiDiSatker($this->satker);
        $this->idEntri = $this->buatRiwayatPendidikanUji($this->nip, 1);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::reset(true);

        parent::tearDown();
    }

    public function testAlurUnggahDaftarUnduhHapusOlehPegawaiSendiri(): void
    {
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        // Pegawai hanya mengelola lampiran baris yang belum disetujui (baris Disetujui terkunci, kunciBarisDisetujui()).
        $idEntri = (string) $this->buatRiwayatPendidikanUji($this->nip, 0);

        $unggah = $this->unggah($pegawai, $this->nip, 14, $idEntri, $this->berkasPdf('Ijazah S1 ✓.pdf'));
        $this->assertSame(201, $unggah->response()->getStatusCode(), (string) $unggah->response()->getBody());
        $row = $this->json($unggah)['data'];
        $this->assertArrayNotHasKey('path', $row);
        $this->assertSame($this->nip, $row['NIP']);
        $this->assertSame('Ijazah S1 ✓.pdf', $row['display_name']);
        $id = (int) $row['id_attachment'];

        $daftar = $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$idEntri}");
        $daftar->assertStatus(200);
        $this->assertSame([$id], array_map('intval', array_column($this->json($daftar)['data'], 'id_attachment')));
        $this->assertArrayNotHasKey('path', $this->json($daftar)['data'][0]);

        $unduh = $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran/{$id}/unduh");
        $unduh->assertStatus(200);
        $this->assertStringStartsWith('%PDF-1.4', $unduh->response()->getBody());
        $this->assertStringStartsWith('application/pdf', $unduh->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString("filename*=UTF-8''Ijazah%20S1%20%E2%9C%93.pdf", $unduh->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $unduh->response()->getHeaderLine('X-Content-Type-Options'));

        // "Ganti" = unggah baru dulu, lalu hapus yang lama (kode 14 wajib: lampiran terakhir tidak boleh dihapus).
        $baru = (int) $this->json($this->unggah($pegawai, $this->nip, 14, $idEntri, $this->berkasPdf('Ijazah baru.pdf')))['data']['id_attachment'];

        // Izin hapus Definisi pendidikan hanya role 1, 3 — tetapi `ubah` mencakup role 2, jadi hapus lampiran boleh.
        $hapus = $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}");
        $hapus->assertStatus(200);
        $this->assertTrue($this->json($hapus)['data']['deleted']);
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());
        $this->assertCount(1, $this->berkasDiAkarUji());

        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$baru}")->assertStatus(422);
    }

    public function testAdminSatkerHanyaDiSatkernya(): void
    {
        $adminSendiri = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::ADMIN_SATKER);
        $adminLain    = $this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER);

        $this->unggah($adminLain, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf())->assertStatus(403);
        $this->assertSame([], $this->berkasDiAkarUji());

        // Admin berizin tetap boleh mengelola lampiran baris Disetujui (kunci hanya untuk UL_PEGAWAI).
        $row = $this->json($this->unggah($adminSendiri, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf()))['data'];
        $id  = (int) $row['id_attachment'];

        $this->asUser($adminLain)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=39&id_entri={$this->idEntri}")->assertStatus(403);
        $this->asUser($adminLain)->get("api/v1/pegawai/{$this->nip}/lampiran/{$id}/unduh")->assertStatus(403);
        $this->asUser($adminLain)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(403);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());

        $this->asUser($adminSendiri)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(200);
    }

    public function testUnduhHapusDiLuarLingkup403UntukIdAdaMaupunTidak(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $id    = (int) $this->json($this->unggah($admin, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf()))['data']['id_attachment'];

        $adminLain   = $this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER);
        $pegawaiLain = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::PEGAWAI);

        foreach (['admin satker lain' => $adminLain, 'pegawai lain' => $pegawaiLain] as $label => $akun) {
            foreach ([$id, 999999999] as $idUji) {
                $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/lampiran/{$idUji}/unduh")->assertStatus(403);
                $this->asUser($akun)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$idUji}")->assertStatus(403);
            }

            $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults(), $label);
        }

        // Pemanggil yang lingkupnya mencakup {nip}: id tidak ada → 404.
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran/999999999/unduh")->assertStatus(404);
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/999999999")->assertStatus(404);
    }

    public function testLampiranWajibTerakhirTidakBolehDihapus(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $entri = (string) $this->idEntri;

        $pertama = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];

        $tolak = $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$pertama}");
        $tolak->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($tolak)['errors']);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $pertama)->countAllResults());
        $this->assertCount(1, $this->berkasDiAkarUji());

        // Ganti: unggah baru dulu, lalu hapus yang lama; yang tersisa kembali terlindungi.
        $kedua = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$pertama}")->assertStatus(200);
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$kedua}")->assertStatus(422);

        // Kode tidak wajib (39): lampiran terakhir boleh dihapus.
        $gelar = (int) $this->json($this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$gelar}")->assertStatus(200);
    }

    public function testBarisDisetujuiTerkunciUntukPegawai(): void
    {
        $admin   = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        $entri   = (string) $this->idEntri; // status 1 Disetujui

        $ada = (int) $this->json($this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf())->assertStatus(201);

        $this->unggah($pegawai, $this->nip, 39, $entri, $this->berkasPdf())->assertStatus(403);
        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$ada}")->assertStatus(403);
        $this->assertSame(2, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
        $this->assertCount(2, $this->berkasDiAkarUji());

        // Baca tetap boleh.
        $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=39&id_entri={$entri}")->assertStatus(200);
        $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran/{$ada}/unduh")->assertStatus(200);
    }

    public function testBarisRiwayatDihapus404(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $entri = (string) $this->idEntri;
        $id    = (int) $this->json($this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf()))['data']['id_attachment'];

        $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $this->idEntri)->update(['status' => 10]);

        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=39&id_entri={$entri}")->assertStatus(404);
        $this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf())->assertStatus(404);
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(404);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());
    }

    public function testPegawaiLainDanRoleTanpaIzinDitolak(): void
    {
        $pegawaiLain = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::PEGAWAI);
        $this->unggah($pegawaiLain, $this->nip, 14, (string) $this->idEntri, $this->berkasPdf())->assertStatus(403);

        // Role 4 melihat semua pegawai (lingkup), tetapi Definisi pendidikan tidak memberinya izin apa pun.
        $adminView = $this->akun($this->buatPegawai(), Role::ADMIN_VIEW_ESELON1);
        $this->asUser($adminView)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$this->idEntri}")->assertStatus(403);
    }

    public function testKodeEntriDanLampiranTidakDikenal404(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        // Kode jenis_rwy yang tidak dimiliki Definisi terdaftar (9 = jabatan, Definisi WS-2 M4 belum ada).
        $this->unggah($admin, $this->nip, 9, (string) $this->idEntri, $this->berkasPdf())->assertStatus(404);

        // id_entri milik pegawai lain / tidak ada.
        $nipLain = $this->buatPegawai();
        $this->unggah($admin, $this->nip, 14, (string) $this->buatRiwayatPendidikanUji($nipLain, 1), $this->berkasPdf())->assertStatus(404);
        $this->unggah($admin, $this->nip, 14, '999999999', $this->berkasPdf())->assertStatus(404);

        // Lampiran milik NIP lain lewat path NIP ini → 404 (tanpa IDOR).
        $row = $this->json($this->unggah($admin, $this->nip, 14, (string) $this->idEntri, $this->berkasPdf()))['data'];
        $this->asUser($admin)->get("api/v1/pegawai/{$nipLain}/lampiran/{$row['id_attachment']}/unduh")->assertStatus(404);
        $this->asUser($admin)->delete("api/v1/pegawai/{$nipLain}/lampiran/{$row['id_attachment']}")->assertStatus(404);

        $this->assertCount(1, $this->berkasDiAkarUji());
    }

    public function testValidasi422(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        $tanpaKode = $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_entri={$this->idEntri}");
        $tanpaKode->assertStatus(422);
        $this->assertArrayHasKey('id_riwayat', $this->json($tanpaKode)['errors']);

        $tanpaBerkas = $this->sebagaiForm($admin)->post("api/v1/pegawai/{$this->nip}/lampiran", ['id_riwayat' => '14', 'id_entri' => (string) $this->idEntri]);
        $tanpaBerkas->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($tanpaBerkas)['errors']);

        $png = $this->unggah($admin, $this->nip, 14, (string) $this->idEntri, $this->berkasPng('ijazah.pdf'));
        $png->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($png)['errors']);

        $this->assertSame([], $this->berkasDiAkarUji());
        $this->assertSame(0, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
    }

    public function testTanpaToken401(): void
    {
        $this->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$this->idEntri}")->assertStatus(401);
    }

    /**
     * @return array<string, mixed> baris pengguna
     */
    private function akun(string $nip, int $role): array
    {
        $id = $this->buatAkunUntuk($nip, $role);

        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return $row;
    }

    /**
     * Header Authorization + Content-Type multipart (withHeaders() menimpa header sebelumnya, jadi digabung di sini).
     *
     * @param array<string, mixed> $akun
     *
     * @return $this
     */
    private function sebagaiForm(array $akun): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token'],
            'Content-Type'  => 'multipart/form-data; boundary=x',
        ]);
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function unggah(array $akun, string $nip, int $kode, string $idEntri, UploadedFile $berkas): TestResponse
    {
        // FileCollection membaca $_FILES lewat service superglobals (shared): set lewat service itu.
        service('superglobals')->setFilesArray(['berkas' => [
            'name'     => $berkas->getClientName(),
            'type'     => $berkas->getClientMimeType(),
            'tmp_name' => $berkas->getTempName(),
            'error'    => $berkas->getError(),
            'size'     => (int) filesize($berkas->getTempName()),
        ]]);

        try {
            return $this->sebagaiForm($akun)->post("api/v1/pegawai/{$nip}/lampiran", ['id_riwayat' => (string) $kode, 'id_entri' => $idEntri]);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
        }
    }
}
