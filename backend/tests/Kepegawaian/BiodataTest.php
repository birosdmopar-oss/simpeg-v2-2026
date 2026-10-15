<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Pegawai\BiodataService;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Models\Kepegawaian\PegawaiHistModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * B-03 Biodata dua jalur (MAKE-010) dengan PegawaiScope + penyimpanan berkas NYATA: role 1 langsung ke `pegawai`
 * (pengajuan aktif → flag 2 tanpa alasan, keputusan user 13-10-2026), role 2/3/6/7 lewat `flag_update` + satu
 * `pegawai_hist` per NIP (`pegawai` tidak berubah), lingkup role 3 (`validateAccess_AP`), hak isian per role ikut form
 * legacy (isian tanpa hak yang berbeda → 422), foto profil + galeri `pegawai_foto`.
 *
 * @internal
 */
final class BiodataTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private string $prov      = '';
    private string $kab       = '';
    private int $agama        = 0;
    private int $jenisPegawai = 0;
    private int $jenisStatus  = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('pegawaiScope', new PegawaiScope());
        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
        $this->siapkanMaster();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::resetSingle('storageAdapter');
        Services::resetSingle('pegawaiScope');

        parent::tearDown();
    }

    public function testRole1LangsungKePegawaiDanSinkronEmail(): void
    {
        $nip   = $this->pegawaiLengkap();
        $akun  = $this->buatAkunUntuk($nip, Role::PEGAWAI, ['email' => 'lama@contoh.test']);
        $super = $this->auth(Role::SUPER_ADMIN);

        $hasil = $this->service()->ubah($super, $nip, $this->isian(['nama' => 'Nama Baru', 'npwp' => '12.345.678-9', 'email' => 'baru@contoh.test']));

        $this->assertSame('langsung', $hasil['jalur']);
        $row = $this->pegawai($nip);
        $this->assertSame(['Nama Baru', '123456789', 0], [$row['nama'], $row['npwp'], (int) $row['flag_update']]);
        $this->assertSame('Agama Uji', $row['agama']);
        $this->assertSame('baru@contoh.test', $this->db->table('pengguna')->where('id_pengguna', $akun)->get()->getRow()->email);
        $this->assertSame(0, $this->db->table('pegawai_hist')->where('nip', $nip)->countAllResults());
        $this->assertNotSame([], $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', $nip)->get()->getResultArray());
    }

    public function testRole2PengajuanTidakMengubahPegawaiDanSatuHistPerNip(): void
    {
        [$nip, $auth] = $this->aktor(Role::PEGAWAI);
        $sebelum      = $this->pegawai($nip);

        $hasil = $this->service()->ubah($auth, $nip, $this->isian(['no_hp' => '0812-1111', 'email' => 'saya@contoh.test']));

        $this->assertSame('pengajuan', $hasil['jalur']);
        $sesudah = $this->pegawai($nip);
        $this->assertSame(PegawaiHistModel::FLAG_DIAJUKAN, (int) $sesudah['flag_update']);
        $this->assertSame($sebelum['no_hp'], $sesudah['no_hp'], 'pegawai belum berubah');

        $hist = $this->db->table('pegawai_hist')->where('nip', $nip)->get()->getResultArray();
        $this->assertCount(1, $hist);
        $this->assertSame(['08121111', 'saya@contoh.test', 1, null], [$hist[0]['no_hp'], $hist[0]['email'], (int) $hist[0]['flag_update'], $hist[0]['reason_note']]);
        $this->assertSame($sebelum['nama'], $hist[0]['nama'], 'salinan pegawai + perubahan');

        // Pengajuan kedua memperbarui pengajuan aktif, bukan menambah baris.
        $this->service()->ubah($auth, $nip, $this->isian(['no_hp' => '0812-2222']));
        $hist = $this->db->table('pegawai_hist')->where('nip', $nip)->get()->getResultArray();
        $this->assertCount(1, $hist);
        $this->assertSame('08122222', $hist[0]['no_hp']);
    }

    public function testRole1MenimpaPengajuanAktifMenjadiDitolakTanpaAlasan(): void
    {
        [$nip, $auth] = $this->aktor(Role::PEGAWAI);
        $this->service()->ubah($auth, $nip, $this->isian(['no_hp' => '0812-1111']));

        $this->service()->ubah($this->auth(Role::SUPER_ADMIN), $nip, $this->isian(['no_hp' => '0813-0000']));

        $hist = $this->db->table('pegawai_hist')->where('nip', $nip)->get()->getRowArray();
        $this->assertSame([PegawaiHistModel::FLAG_DITOLAK, null, 1], [(int) $hist['flag_update'], $hist['reason_note'], (int) $hist['show_notif']]);
        $this->assertNotNull($hist['notif_date']);
        $this->assertSame(['08130000', 0], [$this->pegawai($nip)['no_hp'], (int) $this->pegawai($nip)['flag_update']]);
    }

    public function testRoleDanLingkup(): void
    {
        $idSatker = $this->buatSatker();
        $dalam    = $this->pegawaiLengkap(['id_satker' => $idSatker]);
        $luar     = $this->pegawaiLengkap();
        $admin3   = $this->auth(Role::ADMIN_SATKER, $idSatker);

        $this->assertSame('pengajuan', $this->service()->ubah($admin3, $dalam, $this->isian())['jalur']);
        $this->assertForbidden(fn () => $this->service()->ubah($admin3, $luar, $this->isian()));

        [, $pegawai] = $this->aktor(Role::PEGAWAI);
        $this->assertForbidden(fn () => $this->service()->ubah($pegawai, $luar, $this->isian()));

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->assertForbidden(fn () => $this->service()->ubah($this->auth($role), $luar, $this->isian()));
        }

        foreach ([Role::PTT, Role::PPPK] as $role) {
            [$nip, $auth] = $this->aktor($role);
            $this->assertSame('pengajuan', $this->service()->ubah($auth, $nip, $this->isian())['jalur'], "role {$role}");
        }

        $this->expectException(NotFoundException::class);
        $this->service()->ubah($this->auth(Role::SUPER_ADMIN), '999999999999999999', $this->isian());
    }

    public function testIsianTanpaHakBerbedaDitolakSamaDiterima(): void
    {
        [$nip, $auth] = $this->aktor(Role::PEGAWAI);
        $row          = $this->pegawai($nip);

        $jenisLain = (int) $this->master('jenis_pegawai', ['id_jenis_pegawai' => 2, 'jenis_pegawai' => 'PPPK']);

        $this->assertErrors(['nama', 'id_jenis_pegawai', 'status'], fn () => $this->service()->ubah($auth, $nip, $this->isian([
            'nama' => 'Ganti Nama', 'id_jenis_pegawai' => $jenisLain, 'status' => 2,
        ])));

        // Nilai sama (isian readonly yang dikirim apa adanya, form legacy) diterima.
        $hasil = $this->service()->ubah($auth, $nip, $this->isian(['nama' => $row['nama'], 'tgl_lahir' => $row['tgl_lahir'], 'nip' => $nip]));
        $this->assertSame('pengajuan', $hasil['jalur']);

        // Role 3 boleh jenis pegawai, tidak boleh nama.
        $idSatker = $this->buatSatker();
        $dalam    = $this->pegawaiLengkap(['id_satker' => $idSatker]);
        $admin3   = $this->auth(Role::ADMIN_SATKER, $idSatker);
        $this->assertErrors(['nama'], fn () => $this->service()->ubah($admin3, $dalam, $this->isian(['nama' => 'X'])));
        $this->assertSame('pengajuan', $this->service()->ubah($admin3, $dalam, $this->isian(['id_jenis_pegawai' => $jenisLain]))['jalur']);
        $this->assertSame((string) $jenisLain, (string) $this->db->table('pegawai_hist')->where('nip', $dalam)->get()->getRow()->id_jenis_pegawai);
    }

    public function testValidasiLegacyDanV2(): void
    {
        $nip   = $this->pegawaiLengkap();
        $super = $this->auth(Role::SUPER_ADMIN);
        $prov2 = $this->master('provinsi', ['id_provinsi' => '32', 'provinsi' => 'Provinsi Dua']);

        $this->assertErrors(['id_kabupaten_kota_lahir'], fn () => $this->service()->ubah($super, $nip, $this->isian(['id_provinsi_lahir' => $prov2])));
        $this->assertErrors(['email'], fn () => $this->service()->ubah($super, $nip, $this->isian(['email' => 'bukan-email'])));
        $this->assertErrors(['npwp', 'jenis_kelamin'], fn () => $this->service()->ubah($super, $nip, $this->isian(['npwp' => '--', 'jenis_kelamin' => 3])));
        $this->assertErrors(['jenis_kerabat_lain'], fn () => $this->service()->ubah($super, $nip, $this->isian(['jenis_kerabat' => 'Kerabat Lain'])));
        $this->assertErrors(['provinsi_lahir_lain'], fn () => $this->service()->ubah($super, $nip, $this->isian(['id_provinsi_lahir' => '99', 'id_kabupaten_kota_lahir' => '9999'])));
        $this->assertErrors(['tgl_lahir'], fn () => $this->service()->ubah($super, $nip, $this->isian(['tgl_lahir' => '2026-02-30'])));
        $this->assertErrors(['nama'], fn () => $this->service()->ubah($super, $nip, $this->isian(['nama' => ['x']])));

        $hasil = $this->service()->ubah($super, $nip, $this->isian([
            'id_provinsi_lahir'         => '99', 'id_kabupaten_kota_lahir' => '9999', 'provinsi_lahir_lain' => 'Luar Negeri',
            'kabupaten_kota_lahir_lain' => 'Kota Luar', 'jenis_kerabat' => 'Kerabat Lain', 'jenis_kerabat_lain' => 'Sepupu',
        ]));
        $row = $this->pegawai($nip);
        $this->assertSame('langsung', $hasil['jalur']);
        $this->assertSame(['Luar Negeri', 'Kota Luar', 'Sepupu', 'LAIN-LAIN'], [$row['provinsi_lahir_lain'], $row['kabupaten_kota_lahir_lain'], $row['jenis_kerabat'], $row['provinsi_lahir']]);
    }

    public function testFotoWajibBilaBelumAdaDanGaleri(): void
    {
        $nip   = $this->pegawaiLengkap(['foto' => null]);
        $super = $this->auth(Role::SUPER_ADMIN);

        $this->assertErrors(['foto'], fn () => $this->service()->ubah($super, $nip, $this->isian()));
        $this->assertErrors(['foto'], fn () => $this->service()->ubah($super, $nip, $this->isian(), $this->berkasPdf('foto.pdf')));

        $this->service()->ubah($super, $nip, $this->isian(), $this->berkasPng());
        $path = (string) $this->pegawai($nip)['foto'];
        $this->assertNotSame('', $path);
        $this->assertSame(1, $this->db->table('pegawai_foto')->where('nip', $nip)->where('foto', $path)->countAllResults());

        $foto = $this->service()->foto($super, $nip);
        $this->assertSame('image/png', $foto['mime']);

        // Pengajuan pegawai: foto masuk pegawai_hist, bukan pegawai.
        [$nip2, $auth2] = $this->aktor(Role::PEGAWAI);
        $this->db->table('pegawai')->where('nip', $nip2)->update(['foto' => $path]);
        $this->service()->ubah($auth2, $nip2, $this->isian(), $this->berkasPng());
        $hist = $this->db->table('pegawai_hist')->where('nip', $nip2)->get()->getRowArray();
        $this->assertNotSame($path, $hist['foto']);
        $this->assertSame($path, $this->pegawai($nip2)['foto']);
    }

    public function testFotoLegacyRemoteAtauTanpaFoto404(): void
    {
        $nip = $this->pegawaiLengkap(['foto' => 'arsip/199001012015011001/umum/foto lama.jpg']);

        $this->expectException(NotFoundException::class);
        $this->service()->foto($this->auth(Role::SUPER_ADMIN), $nip);
    }

    #[Group('db-isolasi-penuh')]
    public function testRollbackSesudahFotoDisimpanMengompensasiBerkas(): void
    {
        // Gagal SESUDAH KolomBerkas::simpan() menulis berkas: trigger menolak INSERT pegawai_foto (langkah terakhir jalur
        // langsung). Transaksi di-rollback (pegawai.foto tetap NULL) dan berkas yang sempat ditulis dibuang.
        $nip     = $this->pegawaiLengkap(['foto' => null]);
        $super   = $this->auth(Role::SUPER_ADMIN);
        $trigger = 'trg_uji_make011_pegawai_foto';
        $tabel   = $this->db->escapeIdentifiers($this->db->prefixTable('pegawai_foto'));

        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$tabel} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulasi gagal (test MAKE-011)'");

        try {
            $this->service()->ubah($super, $nip, $this->isian(), $this->berkasPng());
            $this->fail('Ubah biodata seharusnya gagal.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->assertNull($this->pegawai($nip)['foto']);
        $this->assertSame([], $this->berkasDiAkarUji(), 'berkas foto dikompensasi');
    }

    public function testPengajuanUlangDenganFotoBaruMembuangFotoPengajuanLama(): void
    {
        [$nip, $auth] = $this->aktor(Role::PEGAWAI);

        $this->service()->ubah($auth, $nip, $this->isian(), $this->berkasPng('foto1.png'));
        $fotoLama = (string) $this->db->table('pegawai_hist')->where('nip', $nip)->get()->getRow()->foto;
        $this->assertContains($fotoLama, $this->berkasDiAkarUji());

        $this->service()->ubah($auth, $nip, $this->isian(), $this->berkasPng('foto2.png'));
        $fotoBaru = (string) $this->db->table('pegawai_hist')->where('nip', $nip)->get()->getRow()->foto;

        $this->assertNotSame($fotoLama, $fotoBaru);
        $this->assertSame([$fotoBaru], $this->berkasDiAkarUji(), 'foto pengajuan lama dibuang, foto pegawai tidak tersentuh');
    }

    public function testEmailDipakaiAkunLain(): void
    {
        // A-01 D-6 (MAKE-011): pengajuan → 422 errors.email; jalur langsung → biodata tersimpan, email akun dilewati.
        $this->buatAkunUntuk($this->pegawaiLengkap(), Role::PEGAWAI, ['email' => 'dipakai@contoh.test']);
        [$nip, $auth] = $this->aktor(Role::PEGAWAI);

        $this->assertErrors(['email'], fn () => $this->service()->ubah($auth, $nip, $this->isian(['email' => 'dipakai@contoh.test'])));
        $this->assertSame(0, $this->db->table('pegawai_hist')->where('nip', $nip)->countAllResults());

        $hasil = $this->service()->ubah($this->auth(Role::SUPER_ADMIN), $nip, $this->isian(['email' => 'dipakai@contoh.test', 'no_hp' => '0899']));
        $this->assertTrue($hasil['email_skipped']);
        $this->assertSame('08' . '99', $this->pegawai($nip)['no_hp']);
        $this->assertNotSame('dipakai@contoh.test', (string) $this->db->table('pengguna')->where('nip', $nip)->get()->getRow()->email);
    }

    public function testGelarTerlaluPanjangUntukPengajuan(): void
    {
        // Sementara sampai DBV-020: pegawai_hist.glr_awal VARCHAR(12), glr_akhir VARCHAR(24) [K].
        $nip  = $this->pegawaiLengkap([], ['glr_awal' => 'Prof. Dr. Ir. H.', 'glr_akhir' => 'S.E., M.M., M.B.A., Ph.D., CPA']);
        $auth = $this->authUntukAkun($this->buatAkunUntuk($nip, Role::PEGAWAI));

        try {
            $this->service()->ubah($auth, $nip, $this->isian());
            $this->fail('Seharusnya 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('hubungi admin', $e->getErrors()['glr_awal'][0]);
            $this->assertStringContainsString('maks. 24', $e->getErrors()['glr_akhir'][0]);
        }

        // Super Admin (jalur langsung ke pegawai, VARCHAR 50) tidak dibatasi.
        $this->assertSame('langsung', $this->service()->ubah($this->auth(Role::SUPER_ADMIN), $nip, $this->isian())['jalur']);
    }

    public function testEndpointJsonDanMultipart(): void
    {
        [$nip, , $akun] = $this->aktorBaris(Role::PEGAWAI);

        $this->withBodyFormat('json')->put("api/v1/pegawai/{$nip}/biodata", $this->isian())->assertStatus(401);

        $result = $this->asUser($akun)->withBodyFormat('json')->put("api/v1/pegawai/{$nip}/biodata", $this->isian(['no_hp' => '0811']));
        $result->assertStatus(200);
        $this->assertSame('pengajuan', $this->json($result)['data']['jalur']);
        $this->assertArrayNotHasKey('foto', $this->json($result)['data']['pegawai']);

        $lain = $this->pegawaiLengkap();
        $this->asUser($akun)->withBodyFormat('json')->put("api/v1/pegawai/{$lain}/biodata", $this->isian())->assertStatus(403);
        $this->asUser($akun)->withBodyFormat('json')->put("api/v1/pegawai/{$nip}/biodata", $this->isian(['email' => 'x']))->assertStatus(422);

    }

    public function testEndpointMultipartFotoDanUnduh(): void
    {
        [, , $akun]  = $this->aktorBaris(Role::PEGAWAI);
        $lain        = $this->pegawaiLengkap();
        [, , $super] = $this->aktorBaris(Role::SUPER_ADMIN);
        $multipart   = $this->multipart($super, "api/v1/pegawai/{$lain}/biodata", $this->isian(['_method' => 'PUT']), $this->berkasPng());
        $multipart->assertStatus(200);
        $this->assertTrue($this->json($multipart)['data']['pegawai']['ada_foto']);

        $foto = $this->asUser($super)->get("api/v1/pegawai/{$lain}/foto");
        $foto->assertStatus(200);
        $this->assertStringStartsWith('image/png', $foto->response()->getHeaderLine('Content-Type'));
        $this->asUser($akun)->get("api/v1/pegawai/{$lain}/foto")->assertStatus(403);
    }

    private function service(): BiodataService
    {
        return new BiodataService(null, null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));
    }

    private function siapkanMaster(): void
    {
        $this->prov         = $this->master('provinsi', ['id_provinsi' => '31', 'provinsi' => 'Provinsi Uji']);
        $this->kab          = $this->master('kabupaten_kota', ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Kota Uji']);
        $this->agama        = (int) $this->master('agama', ['id_agama' => 1, 'agama' => 'Agama Uji']);
        $this->jenisPegawai = (int) $this->master('jenis_pegawai', ['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS']);
        $this->jenisStatus  = (int) $this->master('jenis_status', ['id_jenis_status' => 1, 'jenis_status' => 'Aktif Bekerja', 'status_pegawai' => 1]);
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function master(string $tabel, array $baris): string
    {
        $pk = array_key_first($baris);
        $this->db->table($tabel)->insert($baris + ['status' => 1]);

        return (string) $baris[$pk];
    }

    /**
     * Pegawai dengan biodata lengkap (memenuhi kewajiban legacy) + foto; $pmj['id_satker'] untuk lingkup role 3.
     *
     * @param array<string, mixed> $pmj
     */
    private function pegawaiLengkap(array $pmj = [], array $override = []): string
    {
        $override = array_merge(['foto' => 'kolom/2026/10/ada.png'], $override);

        if (array_key_exists('foto', $pmj)) {
            $override['foto'] = $pmj['foto'];
            unset($pmj['foto']);
        }

        return $this->buatPegawai(array_merge([
            'id_provinsi_lahir' => $this->prov, 'id_kabupaten_kota_lahir' => $this->kab, 'id_agama' => $this->agama,
            'id_jenis_pegawai'  => $this->jenisPegawai, 'id_jenis_status' => $this->jenisStatus, 'npwp' => '1', 'nik' => '2',
            'bpjs_kes'          => '3', 'no_taspen' => '4', 'no_hp' => '5', 'jenis_kerabat' => 'Orang Tua', 'no_telp_kerabat' => '6',
            'status_pernikahan' => 1,
        ], $override), $pmj);
    }

    /**
     * @param array<string, mixed> $ubah
     *
     * @return array<string, mixed>
     */
    private function isian(array $ubah = []): array
    {
        return array_merge([
            'id_provinsi_lahir' => $this->prov, 'id_kabupaten_kota_lahir' => $this->kab, 'id_agama' => (string) $this->agama,
            'jenis_kelamin'     => '1', 'npwp' => '11', 'nik' => '22', 'bpjs_kes' => '33', 'no_taspen' => '44', 'no_hp' => '55',
            'jenis_kerabat'     => 'Orang Tua', 'no_telp_kerabat' => '66', 'status_pernikahan' => '1', 'email' => 'pegawai@contoh.test',
        ], $ubah);
    }

    /**
     * @return array{0: string, 1: AuthContext}
     */
    private function aktor(int $role): array
    {
        $nip = $this->pegawaiLengkap();

        return [$nip, $this->authUntukAkun($this->buatAkunUntuk($nip, $role))];
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    private function aktorBaris(int $role): array
    {
        $nip = $this->pegawaiLengkap();
        $id  = $this->buatAkunUntuk($nip, $role);

        return [$nip, $id, (array) $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray()];
    }

    private function auth(int $role, ?int $idSatker = null): AuthContext
    {
        $nip = $idSatker === null ? $this->buatPegawai() : $this->buatPegawaiDiSatker($idSatker);

        return $this->authUntukAkun($this->buatAkunUntuk($nip, $role));
    }

    /**
     * @return array<string, mixed>
     */
    private function pegawai(string $nip): array
    {
        return (array) $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $akun
     * @param array<string, mixed> $data
     */
    private function multipart(array $akun, string $path, array $data, UploadedFile $foto): TestResponse
    {
        service('superglobals')->setFilesArray(['foto' => [
            'name'     => $foto->getClientName(),
            'type'     => $foto->getClientMimeType(),
            'tmp_name' => $foto->getTempName(),
            'error'    => $foto->getError(),
            'size'     => (int) filesize($foto->getTempName()),
        ]]);

        try {
            // RouteCollection shared menyimpan verb request sebelumnya; tanpa reset, POST + _method=PUT berikutnya di
            // proses test yang sama tidak menemukan route PUT.
            Services::resetSingle('routes');
            Services::resetSingle('router');

            return $this->asUser($akun)->post($path, $data);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
            Services::resetSingle('routes');
            Services::resetSingle('router');
        }
    }

    private function assertForbidden(callable $panggil): void
    {
        try {
            $panggil();
            $this->fail('Seharusnya 403');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param list<string> $kolom
     */
    private function assertErrors(array $kolom, callable $panggil): void
    {
        try {
            $panggil();
            $this->fail('Seharusnya 422: ' . implode(', ', $kolom));
        } catch (ValidationException $e) {
            foreach ($kolom as $k) {
                $this->assertArrayHasKey($k, $e->getErrors(), "errors.{$k}");
            }
        }
    }
}
