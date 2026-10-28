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

    /**
     * M8 MAKE-035 (PRD 5A §11.1 butir 4): seluruh route `presensi*` × role 1–8 dalam satu matriks — pegawai 2/6/7,
     * data presensi 1/3/4/5, manual role 1 di route (role 1 bukan akun khusus → 403 `bukan_akun_presensi_manual` dari
     * service, role lain → 403 dari filter route); tanpa token → 401.
     */
    public function testMatriksRouteLengkap(): void
    {
        $nip     = $this->pegawaiPresensi()['nip'];
        $pegawai = [
            ['get', 'api/v1/presensi/saya/hari-ini'],
            ['get', 'api/v1/presensi/saya'],
            ['post', 'api/v1/presensi/cek-lokasi'],
            ['post', 'api/v1/presensi/masuk'],
            ['post', 'api/v1/presensi/pulang'],
        ];
        $admin = [
            ['get', 'api/v1/presensi/pegawai'],
            ['get', "api/v1/presensi/pegawai/{$nip}"],
        ];
        $manual = [
            ['post', 'api/v1/presensi/manual'],
            ['put', "api/v1/presensi/manual/{$nip}/2026-10-01"],
            ['delete', "api/v1/presensi/manual/{$nip}/2026-10-01"],
        ];

        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $isPegawai = in_array($role, Role::UL_PEGAWAI, true);
            $akun      = $this->buatAkunTanpaNip('mx' . $role . bin2hex(random_bytes(3)), $role, ['nip' => $isPegawai ? $this->pegawaiPresensi()['nip'] : null, 'id_satker' => 'S01', 'must_change_password' => 0]);

            foreach ($pegawai as [$verb, $url]) {
                $status = $this->panggil($akun, $verb, $url)->getStatusCode();
                $isPegawai ? $this->assertNotSame(403, $status, "role {$role} {$verb} {$url}") : $this->assertSame(403, $status, "role {$role} {$verb} {$url}");
            }

            foreach ($admin as [$verb, $url]) {
                $status     = $this->panggil($akun, $verb, $url)->getStatusCode();
                $bolehRoute = in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI], true);

                if (! $bolehRoute) {
                    $this->assertSame(403, $status, "role {$role} {$verb} {$url}");
                } elseif ($role !== Role::ADMIN_SATKER || $url === 'api/v1/presensi/pegawai') {
                    // Role 3 pada detail pegawai di luar satkernya → 403 lingkup (diuji di DataPresensiAdminTest).
                    $this->assertNotSame(403, $status, "role {$role} {$verb} {$url}");
                }
            }

            foreach ($manual as [$verb, $url]) {
                $res = $this->panggil($akun, $verb, $url);
                $this->assertSame(403, $res->getStatusCode(), "role {$role} {$verb} {$url}");
                $kode = json_decode((string) $res->getBody(), true)['code'] ?? null;
                $role === Role::SUPER_ADMIN
                    ? $this->assertSame('bukan_akun_presensi_manual', $kode, "role 1 bukan akun khusus {$verb} {$url}")
                    : $this->assertNotSame('bukan_akun_presensi_manual', $kode, "role {$role} ditolak filter route {$verb} {$url}");
            }
        }

        $this->clearAuthState();

        foreach ([...$pegawai, ...$admin, ...$manual] as [$verb, $url]) {
            $this->assertSame(401, $this->withHeaders([])->{$verb}($url)->response()->getStatusCode(), "{$verb} {$url}");
        }
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function panggil(array $akun, string $verb, string $url): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->asUser($akun)->withBodyFormat('json')->{$verb}($url, $verb === 'get' || $verb === 'delete' ? [] : ['jam_masuk' => '07:00'])->response();
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
