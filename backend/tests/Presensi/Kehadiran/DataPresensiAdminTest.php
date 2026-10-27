<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §6.4, §7.6.4 (AC-D03.8, F5-15): data presensi admin — role 1/3/4/5; role 3 hanya pegawai satkernya (daftar
 * tersaring, detail di luar lingkup → 403); `aksi {ubah, hapus}` dan `can_create`/`can_kelola_manual` true hanya untuk
 * akun khusus; koordinat tidak pernah dikirim (hanya `sumber` dan lokasi); urut `tgl` turun; role 2/6/7/8 → 403.
 *
 * @internal
 */
final class DataPresensiAdminTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    /** @var array{nip: string, id_satker: int, id_unit: int} */
    private array $dalam;

    private string $luar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dalam = $this->pegawaiPresensi(0, 1, ['nama' => 'Hana Dalam']);
        $this->luar  = $this->pegawaiPresensi(0, 1, ['nama' => 'Ivan Luar'])['nip'];
        $lokasi      = $this->lokasi('-6.2', '106.8', 100, nama: 'Kantor Pusat');

        foreach (['2026-10-01' => null, '2026-10-02' => $lokasi] as $tgl => $idLokasi) {
            $this->db->table('dh_online')->insert([
                'nip'       => $this->dalam['nip'], 'tgl' => $tgl, 'jam_masuk' => '07:00:00', 'sumber' => $idLokasi === null ? 2 : 1,
                'lat_masuk' => '-6.2000000', 'lng_masuk' => '106.8000000', 'id_lokasi_masuk' => $idLokasi, 'created_at' => '2026-10-02 00:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->akunPresensiManual = [4675, 4676];
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testDetailAksiHanyaAkunKhususTanpaKoordinat(): void
    {
        $biasa                                      = $this->akun(Role::SUPER_ADMIN);
        $khusus                                     = $this->akun(Role::SUPER_ADMIN);
        config(Presensi::class)->akunPresensiManual = [(int) $khusus['id_pengguna']];

        $data = $this->ambil($biasa, "api/v1/presensi/pegawai/{$this->dalam['nip']}");
        $this->assertFalse($data['can_create']);
        $this->assertSame(['2026-10-02', '2026-10-01'], array_column($data['items'], 'tgl'), 'urut tgl turun');
        $this->assertSame(['ubah' => false, 'hapus' => false], $data['items'][0]['aksi']);
        $this->assertSame(['Kantor Pusat', 1], [$data['items'][0]['lokasi_masuk'], $data['items'][0]['sumber']]);

        foreach ($data['items'] as $item) {
            $this->assertEmpty(array_intersect(['lat_masuk', 'lng_masuk', 'lat_pulang', 'lng_pulang', 'akurasi_masuk', 'akurasi_pulang'], array_keys($item)), 'tanpa koordinat');
        }

        $data = $this->ambil($khusus, "api/v1/presensi/pegawai/{$this->dalam['nip']}");
        $this->assertTrue($data['can_create']);
        $this->assertSame(['ubah' => true, 'hapus' => true], $data['items'][0]['aksi']);
        $this->assertTrue($this->ambil($khusus, 'api/v1/presensi/pegawai')['can_kelola_manual']);
        $this->assertFalse($this->ambil($biasa, 'api/v1/presensi/pegawai')['can_kelola_manual']);

        $this->asUser($biasa)->get('api/v1/presensi/pegawai/199912312099121001')->assertStatus(404);
    }

    public function testRole3LingkupSatker(): void
    {
        $admin = $this->akun(Role::ADMIN_SATKER, (string) $this->dalam['id_satker']);

        $nip = array_column($this->ambil($admin, 'api/v1/presensi/pegawai')['items'], 'nip');
        $this->assertSame([$this->dalam['nip']], $nip);
        $this->ambil($admin, "api/v1/presensi/pegawai/{$this->dalam['nip']}");
        $this->asUser($admin)->get("api/v1/presensi/pegawai/{$this->luar}")->assertStatus(403);
    }

    public function testRole4Dan5LihatSemuaRoleLain403(): void
    {
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            $data = $this->ambil($this->akun($role), 'api/v1/presensi/pegawai?search=' . urlencode('Ivan'));
            $this->assertSame([$this->luar], array_column($data['items'], 'nip'), "role {$role}");
            $this->ambil($this->akun($role), "api/v1/presensi/pegawai/{$this->luar}");
        }

        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $this->asUser($this->akun($role))->get('api/v1/presensi/pegawai')->assertStatus(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function akun(int $role, ?string $satker = 'S01'): array
    {
        return $this->buatAkunTanpaNip('dp' . $role . bin2hex(random_bytes(3)), $role, ['id_satker' => $satker, 'must_change_password' => 0]);
    }

    /**
     * @param array<string, mixed> $akun
     *
     * @return array<string, mixed>
     */
    private function ambil(array $akun, string $url): array
    {
        $res = $this->asUser($akun)->get($url);
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());

        return $this->json($res)['data'];
    }
}
