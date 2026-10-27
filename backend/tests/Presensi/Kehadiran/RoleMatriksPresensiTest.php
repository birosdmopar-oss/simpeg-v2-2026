<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §6.4, §7.5.1 (AC-D02.8): role 1/3/4/5/8 → 403 untuk `masuk`, `pulang`, `cek-lokasi`, `saya*` (route); role
 * 2/6/7 lolos route; akun pegawai tanpa NIP → 403 di service; pegawai yang sedang cuti/konket tetap bisa presensi (tidak
 * ada pemeriksaan cuti/libur di 5A, F5-13); riwayat pribadi bulan berjalan & format bulan tidak sah → 422.
 *
 * @internal
 */
final class RoleMatriksPresensiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRoleAdmin403DiRoutePegawai(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $akun = $this->buatAkunTanpaNip('rm' . $role . bin2hex(random_bytes(3)), $role, ['must_change_password' => 0]);

            $this->asUser($akun)->get('api/v1/presensi/saya/hari-ini')->assertStatus(403);
            $this->asUser($akun)->get('api/v1/presensi/saya')->assertStatus(403);
            $this->asUser($akun)->withBodyFormat('json')->post('api/v1/presensi/cek-lokasi', [])->assertStatus(403);
            $this->asUser($akun)->withBodyFormat('json')->post('api/v1/presensi/masuk', [])->assertStatus(403);
            $this->asUser($akun)->withBodyFormat('json')->post('api/v1/presensi/pulang', [])->assertStatus(403);
        }
    }

    public function testRolePegawaiLolosRouteDanTanpaNip403(): void
    {
        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $nip  = $this->pegawaiPresensi()['nip'];
            $akun = $this->buatAkunTanpaNip('pg' . $role . bin2hex(random_bytes(3)), $role, ['nip' => $nip, 'must_change_password' => 0]);
            $res  = $this->asUser($akun)->withBodyFormat('json')->post('api/v1/presensi/cek-lokasi', []);
            $res->assertStatus(200);
            $this->assertFalse($this->json($res)['data']['wajib_geofence']);
        }

        $this->expectException(ForbiddenException::class);
        $this->online('2026-10-27 07:00:00')->masuk($this->authPegawai(''), []);
    }

    public function testCutiTidakMenolakDanRiwayatSaya(): void
    {
        $nip = $this->pegawaiPresensi()['nip'];
        $this->db->table('riwayat_cuti')->insert(['nip' => $nip, 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => '2026-10-26', 'tgl_akhir' => '2026-10-30', 'lama_cuti' => 5, 'status' => 1]);

        [$status] = $this->online('2026-10-27 07:00:00')->masuk($this->authPegawai($nip), []);
        $this->assertSame(201, $status, 'pegawai cuti tetap bisa presensi (F5-13)');
        $this->online('2026-10-28 07:00:00')->masuk($this->authPegawai($nip), []);

        $data = $this->online('2026-10-28 09:00:00')->riwayatSaya($this->authPegawai($nip), []);
        $this->assertSame(['2026-10', 2, ['2026-10-28', '2026-10-27']], [$data['bulan'], $data['total'], array_column($data['items'], 'tgl')]);
        $this->assertSame(0, $this->online('2026-10-28 09:00:00')->riwayatSaya($this->authPegawai($nip), ['bulan' => '2026-09'])['total']);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->online('2026-10-28 09:00:00')->riwayatSaya($this->authPegawai($nip), ['bulan' => '2026-13']);
    }
}
