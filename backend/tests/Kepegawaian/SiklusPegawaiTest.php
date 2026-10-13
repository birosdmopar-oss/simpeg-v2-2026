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
use App\Libraries\Kepegawaian\Pegawai\PegawaiService;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * B-05 Tambah & Hapus pegawai (MAKE-011) + regresi A-09: tambah = pegawai + foto + akun AccountProvisioner dalam satu
 * transaksi (role akun dari jenis pegawai, password awal acak yang bisa dipakai login), rollback penuh bila akun gagal;
 * hapus = status 10 + akun nonaktif + sesi dicabut; status 1 → 2 lewat biodata menonaktifkan akun PNS (trigger legacy).
 *
 * @internal
 */
final class SiklusPegawaiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private const NIP_BARU = '198505052010012001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAuthState();
        Services::injectMock('pegawaiScope', new PegawaiScope());
        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
        $this->db->table('provinsi')->insert(['id_provinsi' => '31', 'provinsi' => 'Provinsi Uji', 'status' => 1]);
        $this->db->table('kabupaten_kota')->insert(['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Kota Uji', 'status' => 1]);
        $this->db->table('agama')->insert(['id_agama' => 1, 'agama' => 'Agama Uji', 'status' => 1]);

        foreach ([1 => 'PNS', 2 => 'PTT', 6 => 'PPPK'] as $id => $nama) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
        }

        $this->db->table('jenis_status')->insertBatch([
            ['id_jenis_status' => 1, 'jenis_status' => 'Aktif Bekerja', 'status_pegawai' => 1, 'status' => 1],
            ['id_jenis_status' => 2, 'jenis_status' => 'Pensiun', 'status_pegawai' => 2, 'status' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        $this->clearAuthState();
        Services::resetSingle('storageAdapter');
        Services::resetSingle('pegawaiScope');

        parent::tearDown();
    }

    public function testTambahPegawaiFotoDanAkunA09(): void
    {
        $hasil = $this->service()->tambah($this->super(), $this->isian(['id_jenis_pegawai' => '6', 'email' => 'baru@contoh.test']), $this->berkasPng());

        $pegawai = $this->db->table('pegawai')->where('nip', self::NIP_BARU)->get()->getRowArray();
        $this->assertIsArray($pegawai);
        $this->assertSame(['Pegawai Baru', 1, 0, 'PPPK'], [$pegawai['nama'], (int) $pegawai['status'], (int) $pegawai['flag_update'], $pegawai['jenis_pegawai']]);
        $this->assertSame(1, $this->db->table('pegawai_foto')->where('nip', self::NIP_BARU)->where('foto', $pegawai['foto'])->countAllResults());
        $this->assertContains((string) $pegawai['foto'], $this->berkasDiAkarUji());

        $akun = $this->db->table('pengguna')->where('nip', self::NIP_BARU)->get()->getRowArray();
        $this->assertSame([self::NIP_BARU, Role::PPPK, 'baru@contoh.test', '1'], [$akun['username'], (int) $akun['user_level'], $akun['email'], (string) $akun['status']]);
        $this->assertTrue($hasil['akun']['created']);
        $this->assertSame(self::NIP_BARU, $hasil['akun']['initial_password'], 'password awal = NIP [K] (keputusan user 13-10-2026)');
        $this->assertStringStartsWith('$argon2id$', (string) $akun['password']);
        $this->assertArrayNotHasKey('foto', $hasil['pegawai']);

        // Regresi A-09: akun baru bisa login dengan password awal = NIP.
        $this->login(self::NIP_BARU, self::NIP_BARU)->assertStatus(200);
    }

    public function testRoleAkunDariJenisPegawai(): void
    {
        foreach (['1' => Role::PEGAWAI, '2' => Role::PTT, '6' => Role::PPPK] as $jenis => $role) {
            $nip = '19850505201001' . $jenis . '00' . $jenis;
            $this->service()->tambah($this->super(), $this->isian(['nip' => $nip, 'id_jenis_pegawai' => (string) $jenis, 'email' => "j{$jenis}@contoh.test"]), $this->berkasPng());
            $this->assertSame($role, (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRow()->user_level, "jenis {$jenis}");
        }
    }

    public function testValidasiTambah(): void
    {
        $super = $this->super();
        $ada   = $this->buatPegawai();

        $this->assertErrors(['nip'], fn () => $this->service()->tambah($super, $this->isian(['nip' => $ada]), $this->berkasPng()));
        $this->assertErrors(['nip'], fn () => $this->service()->tambah($super, $this->isian(['nip' => '12AB']), $this->berkasPng()));
        $this->assertErrors(['nama', 'tgl_lahir'], fn () => $this->service()->tambah($super, $this->isian(['nama' => '', 'tgl_lahir' => '']), $this->berkasPng()));
        $this->assertErrors(['id_jenis_status'], fn () => $this->service()->tambah($super, $this->isian(['id_jenis_status' => '2']), $this->berkasPng()));
        $this->assertErrors(['foto'], fn () => $this->service()->tambah($super, $this->isian(), null));
        $this->assertSame(0, $this->db->table('pegawai')->where('nip', self::NIP_BARU)->countAllResults());

        foreach ([Role::ADMIN_SATKER, Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1] as $role) {
            $this->assertForbidden(fn () => $this->service()->tambah($this->auth($role), $this->isian(), $this->berkasPng()));
        }
    }

    public function testAkunGagalDibuatMembatalkanSemuaTermasukFoto(): void
    {
        // NIP baru sudah dipakai sebagai username akun lain → AccountProvisioner 422 errors.nip di dalam transaksi.
        $this->buatAkunTanpaNip(self::NIP_BARU, Role::ADMIN_VIEW_ESELON1);

        $this->assertErrors(['nip'], fn () => $this->service()->tambah($this->super(), $this->isian(), $this->berkasPng()));

        $this->assertSame(0, $this->db->table('pegawai')->where('nip', self::NIP_BARU)->countAllResults());
        $this->assertSame(0, $this->db->table('pegawai_foto')->where('nip', self::NIP_BARU)->countAllResults());
        $this->assertSame([], $this->berkasDiAkarUji(), 'foto dikompensasi');
    }

    public function testHapusFisikPegawaiTanpaDataTerkait(): void
    {
        // [K] legacy delete (L_employee.php:663-705): DELETE baris pegawai + foto dibuang; audit delete.
        $nip     = $this->pegawaiPolos();
        $storage = new LocalStorageAdapter($this->akarLampiranUji());
        $storage->simpan('kolom/2026/10/polos.png', 'x');
        $this->db->table('pegawai')->where('nip', $nip)->update(['foto' => 'kolom/2026/10/polos.png']);
        $this->db->table('pegawai_foto')->insert(['nip' => $nip, 'foto' => 'kolom/2026/10/polos.png']);

        $hasil = $this->service()->hapus($this->super(), $nip);

        $this->assertSame($nip, $hasil['nip']);
        $this->assertSame(0, $this->db->table('pegawai')->where('nip', $nip)->countAllResults());
        $this->assertSame(0, $this->db->table('pegawai_foto')->where('nip', $nip)->countAllResults());
        $this->assertFalse($storage->ada('kolom/2026/10/polos.png'), 'foto dibuang sesudah commit');

        $audit = $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', $nip)->where('event', 'delete')->get()->getRowArray();
        $this->assertIsArray($audit);
        $this->assertSame('Pegawai Polos', json_decode((string) $audit['before_json'], true)['nama']);

        $this->expectException(NotFoundException::class);
        $this->service()->hapus($this->super(), $nip);
    }

    public function testHapusDitolakBilaMasihPunyaDataTerkait(): void
    {
        // Snapshot jabatan (fixture buatPegawai) → FK RESTRICT 1451 → 422, tidak ada yang berubah.
        $denganPmj = $this->buatPegawai();
        $this->assertErrors(['nip'], fn () => $this->service()->hapus($this->super(), $denganPmj));
        $this->assertSame(1, $this->db->table('pegawai')->where('nip', $denganPmj)->countAllResults());
        $this->assertSame(1, $this->db->table('pegawai_mutasi_jabatan')->where('nip', $denganPmj)->countAllResults());

        try {
            $this->service()->hapus($this->super(), $denganPmj);
        } catch (ValidationException $e) {
            $this->assertStringContainsString('masih memiliki data terkait', $e->getErrors()['nip'][0]);
        }

        // Akun ber-NIP pegawai berdata turunan TIDAK ikut dilepas (seluruh transaksi di-rollback).
        $akun = $this->buatAkunUntuk($denganPmj, Role::PEGAWAI);
        $this->assertErrors(['nip'], fn () => $this->service()->hapus($this->super(), $denganPmj));
        $this->assertSame([$denganPmj, '1'], [(string) $this->db->table('pengguna')->where('id_pengguna', $akun)->get()->getRow()->nip, (string) $this->db->table('pengguna')->where('id_pengguna', $akun)->get()->getRow()->status]);

        $this->assertForbidden(fn () => $this->service()->hapus($this->auth(Role::ADMIN_SATKER), $denganPmj));
    }

    public function testHapusMelepasAkunBerNipSetNull(): void
    {
        // [K] D1:3756 pengguna.id_pegawai ON DELETE SET NULL (keputusan user 13-10-2026): akun dilepas, nonaktif, sesi dicabut.
        $nip   = $this->pegawaiPolos('199901012020011777');
        $aktif = $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $this->login($nip, AuthSeeder::PASSWORD)->assertStatus(200);
        $this->assertGreaterThan(0, $this->db->table('token')->where('id_pengguna', $aktif)->countAllResults());

        $this->service()->hapus($this->super(), $nip);

        $akun = $this->db->table('pengguna')->where('id_pengguna', $aktif)->get()->getRowArray();
        $this->assertSame([null, '0'], [$akun['nip'], (string) $akun['status']]);
        $this->assertSame(0, $this->db->table('token')->where('id_pengguna', $aktif)->countAllResults());
        $this->assertSame(0, $this->db->table('pegawai')->where('nip', $nip)->countAllResults());

        $audit = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('entity_id', (string) $aktif)->orderBy('id_log', 'DESC')->get()->getRowArray();
        $this->assertSame(['nip' => $nip, 'status' => '1'], json_decode((string) $audit['before_json'], true));

        // Akun soft-deleted juga dilepas.
        $nip2 = $this->pegawaiPolos('199901012020011776');
        $this->db->table('pengguna')->insert(['nip' => $nip2, 'username' => 'akun.lama.' . $nip2, 'user_level' => Role::PEGAWAI, 'status' => '0', 'deleted_at' => '2026-01-01 00:00:00']);
        $this->service()->hapus($this->super(), $nip2);
        $this->assertSame(0, $this->db->table('pengguna')->where('nip', $nip2)->countAllResults());
    }

    public function testTambahMenolakNipYangTertautAkunDanMemaksaStatusAktif(): void
    {
        // Akun yatim (mis. bekas pegawai yang dilepas manual) masih memegang NIP ini → 422 sebelum provisioning.
        $this->db->table('pengguna')->insert(['nip' => self::NIP_BARU, 'username' => 'yatim.' . self::NIP_BARU, 'user_level' => Role::PEGAWAI, 'status' => '0', 'deleted_at' => '2026-01-01 00:00:00']);
        $this->assertErrors(['nip'], fn () => $this->service()->tambah($this->super(), $this->isian(), $this->berkasPng()));
        $this->assertSame(0, $this->db->table('pegawai')->where('nip', self::NIP_BARU)->countAllResults());

        $nip = '198505052010012002';
        $this->service()->tambah($this->super(), $this->isian(['nip' => $nip, 'status' => '2', 'id_jenis_status' => '1', 'email' => 'x2@contoh.test']), $this->berkasPng());
        $this->assertSame(1, (int) $this->db->table('pegawai')->where('nip', $nip)->get()->getRow()->status, 'status awal selalu Aktif');
    }

    public function testStatusSatuKeDuaMenonaktifkanAkunPnsSaja(): void
    {
        // [K] trigger pegawai_afUpd (D1:7975): hanya akun UserLevel 2 yang dinonaktifkan.
        $pns      = $this->pegawaiLengkap();
        $pppk     = $this->pegawaiLengkap();
        $akunPns  = $this->buatAkunUntuk($pns, Role::PEGAWAI);
        $akunPppk = $this->buatAkunUntuk($pppk, Role::PPPK);
        $biodata  = new BiodataService(null, null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));

        foreach ([$pns, $pppk] as $nip) {
            $biodata->ubah($this->super(), $nip, $this->isianUbah(['status' => '2', 'id_jenis_status' => '2']));
            $this->assertSame(2, (int) $this->db->table('pegawai')->where('nip', $nip)->get()->getRow()->status);
        }

        $this->assertSame('0', (string) $this->db->table('pengguna')->where('id_pengguna', $akunPns)->get()->getRow()->status);
        $this->assertSame('1', (string) $this->db->table('pengguna')->where('id_pengguna', $akunPppk)->get()->getRow()->status);

        // Status 10 tidak bisa dipilih lewat biodata; jenis status harus milik status baru.
        $this->assertErrors(['status'], fn () => $biodata->ubah($this->super(), $pppk, $this->isianUbah(['status' => '10'])));
        $this->assertErrors(['id_jenis_status'], fn () => $biodata->ubah($this->super(), $pppk, $this->isianUbah(['status' => '1', 'id_jenis_status' => '2'])));
    }

    public function testEndpointTambahDanHapus(): void
    {
        [, , $super]  = $this->aktorBaris(Role::SUPER_ADMIN);
        [, , $admin3] = $this->aktorBaris(Role::ADMIN_SATKER);

        $this->asUser($admin3)->withBodyFormat('json')->post('api/v1/pegawai', $this->isian())->assertStatus(403);

        $result = $this->multipart($super, 'api/v1/pegawai', $this->isian(), $this->berkasPng());
        $result->assertStatus(201);
        $this->assertSame(self::NIP_BARU, $this->json($result)['data']['pegawai']['nip']);

        $this->asUser($admin3)->delete('api/v1/pegawai/' . self::NIP_BARU)->assertStatus(403);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));

        // Pegawai baru (akun + foto, tanpa data turunan) bisa dihapus: akunnya dilepas.
        $hapus = $this->asUser($super)->delete('api/v1/pegawai/' . self::NIP_BARU);
        $hapus->assertStatus(200);
        $this->assertSame(['deleted' => true, 'soft_delete' => false], array_intersect_key($this->json($hapus)['data'], ['deleted' => 1, 'soft_delete' => 1]));
    }

    private function service(): PegawaiService
    {
        return new PegawaiService(null, null, null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));
    }

    /**
     * @param array<string, mixed> $ubah
     *
     * @return array<string, mixed>
     */
    private function isian(array $ubah = []): array
    {
        return array_merge([
            'nip'                     => self::NIP_BARU, 'nama' => 'Pegawai Baru', 'tgl_lahir' => '1985-05-05', 'id_provinsi_lahir' => '31',
            'id_kabupaten_kota_lahir' => '3171', 'id_agama' => '1', 'jenis_kelamin' => '2', 'npwp' => '11', 'nik' => '22',
            'bpjs_kes'                => '33', 'no_taspen' => '44', 'no_hp' => '55', 'jenis_kerabat' => 'Anak', 'no_telp_kerabat' => '66',
            'status_pernikahan'       => '1', 'email' => 'pegawai.baru@contoh.test', 'id_jenis_pegawai' => '1', 'id_jenis_status' => '1',
        ], $ubah);
    }

    /**
     * @param array<string, mixed> $ubah
     *
     * @return array<string, mixed>
     */
    private function isianUbah(array $ubah = []): array
    {
        $isian = $this->isian($ubah);
        unset($isian['nip'], $isian['email']);

        return $isian + ['email' => 'ubah@contoh.test'];
    }

    /**
     * Pegawai tanpa data turunan apa pun (tanpa snapshot jabatan/akun) — satu-satunya yang bisa dihapus fisik.
     */
    private function pegawaiPolos(string $nip = '199901012020011888'): string
    {
        $this->db->table('pegawai')->insert(['nip' => $nip, 'nama' => 'Pegawai Polos', 'tgl_lahir' => '1999-01-01', 'jenis_kelamin' => 1, 'status' => 1]);

        return $nip;
    }

    private function pegawaiLengkap(): string
    {
        return $this->buatPegawai([
            'id_provinsi_lahir' => '31', 'id_kabupaten_kota_lahir' => '3171', 'id_agama' => 1, 'id_jenis_pegawai' => 1,
            'id_jenis_status'   => 1, 'npwp' => '1', 'nik' => '2', 'bpjs_kes' => '3', 'no_taspen' => '4', 'no_hp' => '5',
            'jenis_kerabat'     => 'Anak', 'no_telp_kerabat' => '6', 'status_pernikahan' => 1, 'foto' => 'kolom/2026/10/ada.png',
        ]);
    }

    private function super(): AuthContext
    {
        return $this->auth(Role::SUPER_ADMIN);
    }

    private function auth(int $role): AuthContext
    {
        return $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), $role));
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    private function aktorBaris(int $role): array
    {
        $nip = $this->buatPegawai();
        $id  = $this->buatAkunUntuk($nip, $role);

        return [$nip, $id, (array) $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray()];
    }

    /**
     * @param array<string, mixed> $akun
     * @param array<string, mixed> $data
     */
    private function multipart(array $akun, string $path, array $data, UploadedFile $foto): \CodeIgniter\Test\TestResponse
    {
        service('superglobals')->setFilesArray(['foto' => [
            'name'     => $foto->getClientName(),
            'type'     => $foto->getClientMimeType(),
            'tmp_name' => $foto->getTempName(),
            'error'    => $foto->getError(),
            'size'     => (int) filesize($foto->getTempName()),
        ]]);

        try {
            Services::resetSingle('routes');
            Services::resetSingle('router');

            return $this->withBodyFormat('')->asUser($akun)->post($path, $data);
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
