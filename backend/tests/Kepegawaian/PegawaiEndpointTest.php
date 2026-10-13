<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Hukdis;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Pendidikan;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-20 dasar (MAKE-010): `GET pegawai` (role 1, 3, 4, 5, 8; lingkup jalur daftar CR-045) dan `GET pegawai/{nip}`
 * (UL_ALL + `bolehLihat`, `tabs` = `RiwayatRegistryInterface::descriptorUntuk()`), dengan PegawaiScope NYATA.
 *
 * @internal
 */
final class PegawaiEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private const NIP_TIDAK_ADA = '999999999999999999';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngine(null, [new Pendidikan(), new Kp(), new Hukdis()]);
        Services::injectMock('pegawaiScope', new PegawaiScope());
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();
        Services::resetSingle('pegawaiScope');

        parent::tearDown();
    }

    public function testDaftarTanpaToken401DanRolePegawai403(): void
    {
        $this->get('api/v1/pegawai')->assertStatus(401);

        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            [, , $akun] = $this->aktor($role);
            $this->asUser($akun)->get('api/v1/pegawai')->assertStatus(403);
        }
    }

    public function testDaftarRole1StatusPencarianDanPaginasi(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $a           = $this->buatPegawai(['nama' => 'Zulkarnaen Uji']);
        $b           = $this->buatPegawai(['nama' => 'Ahmad Uji', 'status' => 2]);
        $hapus       = $this->buatPegawai(['nama' => 'Dihapus Uji', 'status' => 10]);
        $this->db->table('pegawai_kp')->insert(['nip' => $a, 'tmtsk' => '2020-04-01', 'tgl_sk' => '2020-03-01', 'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda']);

        $data = $this->json($this->asUser($super)->get('api/v1/pegawai?search=Uji&per_page=100'))['data'];
        $nips = array_column($data['items'], 'nip');

        $this->assertContains($a, $nips);
        $this->assertContains($b, $nips, 'status 2 (Tidak Aktif) ikut daftar legacy');
        $this->assertNotContains($hapus, $nips, 'status 10 tidak tampil');
        $this->assertSame(['Ahmad Uji', 'Zulkarnaen Uji'], array_values(array_intersect(array_column($data['items'], 'nama'), ['Ahmad Uji', 'Zulkarnaen Uji'])));

        $baris = $data['items'][array_search($a, $nips, true)];
        $this->assertSame('III/a', $baris['gol_ruang']);
        $this->assertNotNull($baris['jabatan']);
        $this->assertArrayNotHasKey('foto', $baris);
        $this->assertFalse($baris['ada_foto']);

        $aktif = $this->json($this->asUser($super)->get('api/v1/pegawai?search=Uji&status=1'))['data'];
        $this->assertNotContains($b, array_column($aktif['items'], 'nip'));

        $halaman = $this->json($this->asUser($super)->get('api/v1/pegawai?search=Uji&per_page=1&page=2'))['data'];
        $this->assertSame([1, 2, 2], [count($halaman['items']), $halaman['page'], $halaman['total']]);

        $this->asUser($super)->get('api/v1/pegawai?page=abc')->assertStatus(422);
    }

    public function testDaftarRole4Dan5TanpaBatasLingkup(): void
    {
        $a = $this->buatPegawaiDiSatker($this->buatSatker());
        $b = $this->buatPegawaiDiSatker($this->buatSatker());

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            [, , $akun] = $this->aktor($role);
            $nips       = array_column($this->json($this->asUser($akun)->get('api/v1/pegawai?per_page=100'))['data']['items'], 'nip');
            $this->assertContains($a, $nips, "role {$role}");
            $this->assertContains($b, $nips, "role {$role}");
        }
    }

    public function testDaftarAdminSatkerHanyaLingkup(): void
    {
        $idSatker        = $this->buatSatker();
        $dalam           = $this->buatPegawaiDiSatker($idSatker);
        $luar            = $this->buatPegawaiDiSatker($this->buatSatker());
        [$diri, , $akun] = $this->aktor(Role::ADMIN_SATKER, [], $idSatker);

        $nips = array_column($this->json($this->asUser($akun)->get('api/v1/pegawai?per_page=100'))['data']['items'], 'nip');

        $this->assertContains($dalam, $nips);
        $this->assertContains($diri, $nips);
        $this->assertNotContains($luar, $nips);

        $filter = array_column($this->json($this->asUser($akun)->get('api/v1/pegawai?per_page=100&id_satker=' . $idSatker))['data']['items'], 'nip');
        $this->assertContains($dalam, $filter);
    }

    public function testAdminUnitKelembagaanDaftarMemuatUptDetailTidak(): void
    {
        // CR-045 [K]: daftar admin unit 20 tanpa satker = unit 20 + UPT (unit 6); detail hanya unit 20.
        $satker20    = $this->buatSatker($this->buatUnit(['id_unit' => PegawaiScope::UNIT_KELEMBAGAAN]));
        $satkerUpt   = $this->buatSatker($this->buatUnit(['id_unit' => PegawaiScope::UNIT_UPT]));
        $kelembagaan = $this->buatPegawaiDiSatker($satker20);
        $upt         = $this->buatPegawaiDiSatker($satkerUpt);
        [, , $admin] = $this->aktor(Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_KELEMBAGAAN, 'id_satker' => null]);

        $nips = array_column($this->json($this->asUser($admin)->get('api/v1/pegawai?per_page=100'))['data']['items'], 'nip');

        $this->assertContains($kelembagaan, $nips);
        $this->assertContains($upt, $nips);
        $this->asUser($admin)->get("api/v1/pegawai/{$kelembagaan}")->assertStatus(200);
        $this->asUser($admin)->get("api/v1/pegawai/{$upt}")->assertStatus(403);
    }

    public function testDetailTabsSamaDenganDescriptorUntuk(): void
    {
        $nip = $this->buatPegawai(['foto' => 'kolom/2026/10/x.png']);
        $this->buatAkunUntuk($nip, Role::PEGAWAI, ['email' => 'pegawai@contoh.test']);
        [, $auth, $super] = $this->aktor(Role::SUPER_ADMIN);

        $data = $this->json($this->asUser($super)->get("api/v1/pegawai/{$nip}"))['data'];

        $this->assertSame(service('riwayatRegistry')->descriptorUntuk($auth, $nip), $data['tabs']);
        $this->assertSame(['kp', 'pendidikan', 'hukdis'], array_column($data['tabs'], 'jenis'));
        $this->assertSame('pegawai@contoh.test', $data['email']);
        $this->assertTrue($data['ada_foto']);
        $this->assertArrayNotHasKey('foto', $data);
        $this->assertSame(['ubah_biodata' => true, 'ubah_langsung' => true, 'koreksi_nip' => true, 'proses_biodata' => false], $data['aksi']);
        $this->assertNotNull($data['jabatan_aktif']);
        $this->assertNull($data['pengajuan']);
    }

    public function testDetailLingkupDanUrutanPemeriksaan(): void
    {
        [$diri, , $pegawai] = $this->aktor(Role::PEGAWAI);
        $lain               = $this->buatPegawai();

        $this->get("api/v1/pegawai/{$lain}")->assertStatus(401);

        $milik = $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$diri}"))['data'];
        $this->assertSame(['ubah_biodata' => true, 'ubah_langsung' => false, 'koreksi_nip' => false, 'proses_biodata' => false], $milik['aksi']);

        $this->asUser($pegawai)->get("api/v1/pegawai/{$lain}")->assertStatus(403);
        $this->asUser($pegawai)->get('api/v1/pegawai/' . self::NIP_TIDAK_ADA)->assertStatus(403);

        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $this->asUser($super)->get('api/v1/pegawai/' . self::NIP_TIDAK_ADA)->assertStatus(404);

        // Role 8: semua pegawai bisa dilihat, tanpa hak ubah biodata.
        [, , $pimpinan] = $this->aktor(Role::PIMPINAN);
        $lihat          = $this->json($this->asUser($pimpinan)->get("api/v1/pegawai/{$lain}"))['data'];
        $this->assertFalse($lihat['aksi']['ubah_biodata']);
    }

    public function testDetailMenampilkanStatusPengajuan(): void
    {
        [$nip, , $pegawai] = $this->aktor(Role::PEGAWAI);
        $this->db->table('pegawai')->where('nip', $nip)->update(['flag_update' => 2]);
        $this->db->table('pegawai_hist')->insert(['nip' => $nip, 'nama' => 'X', 'tgl_lahir' => '1990-01-01', 'flag_update' => 2, 'reason_note' => 'Lengkapi NPWP']);

        $data = $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$nip}"))['data'];

        $this->assertSame(['2', 'Lengkapi NPWP'], [(string) $data['pengajuan']['flag_update'], $data['pengajuan']['reason_note']]);
    }
}
