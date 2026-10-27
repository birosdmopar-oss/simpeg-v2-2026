<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Libraries\Presensi\Kehadiran\KodeGalatPresensi;
use App\Libraries\Presensi\Kehadiran\PresensiManualService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Presensi;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.6.1 (AC-D03.1): akun role 1 dengan `id_pengguna` ∉ `Config\Presensi::$akunPresensiManual` → 403
 * `bukan_akun_presensi_manual` untuk tambah/ubah/hapus, dicek sebelum 404/422; akun khusus berhasil; role 2–8 → 403
 * route; override `.env` `presensi.akunPresensiManual` sah dipakai, tidak sah diabaikan seluruhnya (bawaan 4675, 4676).
 *
 * @internal
 */
final class PresensiManualAksesTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    protected function tearDown(): void
    {
        unset($_ENV['presensi.akunPresensiManual']);
        config(Presensi::class)->akunPresensiManual = [4675, 4676];
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testBukanAkunKhusus403SebelumValidasi(): void
    {
        config(Presensi::class)->akunPresensiManual = [4675];
        $auth                                       = $this->authAdmin(1234);

        foreach ([
            fn () => $this->manual()->tambah($auth, []),
            fn () => $this->manual()->ubah($auth, '1', 'bukan-tanggal', []),
            fn () => $this->manual()->hapus($auth, '199001012020011001', '2026-10-01'),
        ] as $aksi) {
            try {
                $aksi();
                $this->fail('harus 403');
            } catch (ApiException $e) {
                $this->assertSame([403, KodeGalatPresensi::BUKAN_AKUN_PRESENSI_MANUAL, PresensiManualService::PESAN_BUKAN_AKUN], [$e->getStatusCode(), $e->getKode(), $e->getMessage()]);
            }
        }
    }

    public function testAkunKhususBerhasilLewatHttp(): void
    {
        $nip                                        = $this->buatPegawai();
        $akun                                       = $this->buatAkunTanpaNip('manual' . bin2hex(random_bytes(3)), Role::SUPER_ADMIN, ['must_change_password' => 0]);
        config(Presensi::class)->akunPresensiManual = [(int) $akun['id_pengguna']];

        $this->asUser($akun)->withBodyFormat('json')->post('api/v1/presensi/manual', ['nip' => $nip, 'tgl' => '2026-10-01', 'jam_masuk' => '07:30'])->assertStatus(201);
        $this->asUser($akun)->withBodyFormat('json')->put("api/v1/presensi/manual/{$nip}/2026-10-01", ['jam_masuk' => '07:45', 'jam_pulang' => '16:00'])->assertStatus(200);
        $this->asUser($akun)->delete("api/v1/presensi/manual/{$nip}/2026-10-01")->assertStatus(200);
        $this->assertNull($this->barisDh($nip, '2026-10-01'));

        $lain = $this->buatAkunTanpaNip('admin' . bin2hex(random_bytes(3)), Role::SUPER_ADMIN, ['must_change_password' => 0]);
        $res  = $this->asUser($lain)->withBodyFormat('json')->post('api/v1/presensi/manual', ['nip' => $nip, 'tgl' => '2026-10-01', 'jam_masuk' => '07:30']);
        $res->assertStatus(403);
        $this->assertSame(KodeGalatPresensi::BUKAN_AKUN_PRESENSI_MANUAL, $this->json($res)['code']);
    }

    public function testRoleLain403Route(): void
    {
        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $akun = $this->buatAkunTanpaNip('r' . $role . bin2hex(random_bytes(3)), $role, ['must_change_password' => 0]);
            $this->asUser($akun)->withBodyFormat('json')->post('api/v1/presensi/manual', [])->assertStatus(403);
            $this->asUser($akun)->delete('api/v1/presensi/manual/1/2026-10-01')->assertStatus(403);
        }
    }

    public function testOverrideEnv(): void
    {
        $_ENV['presensi.akunPresensiManual'] = '11, 12';
        $this->assertSame([11, 12], (new Presensi())->akunPresensiManual);

        foreach (['', '11,abc', '0', '11,-2'] as $buruk) {
            $_ENV['presensi.akunPresensiManual'] = $buruk;
            $this->assertSame([4675, 4676], (new Presensi())->akunPresensiManual, "'{$buruk}' diabaikan seluruhnya");
        }
    }
}
