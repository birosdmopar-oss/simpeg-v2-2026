<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §6.1 AC-D08.1 (MAKE-041): route laporan Tukin × role 1–8 — daftar & rekap 1, 3; IL 1; per pegawai 1–7 (route), role
 * 8 403; role pegawai NIP lain 403 di service; tanpa token 401; unduhan XLSX lewat HTTP.
 *
 * @internal
 */
final class RoleMatriksLaporanTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testMatriksRoute(): void
    {
        $satker = $this->buatSatker($this->buatUnit());
        $target = $this->pegawaiRekap($satker);
        $route  = [
            'api/v1/presensi/tukin/pegawai'                         => [1, 3],
            'api/v1/presensi/tukin/pegawai-il'                      => [1],
            'api/v1/presensi/tukin/rekap?bulan=2026-09'             => [1, 3],
            'api/v1/presensi/tukin/rekap/xlsx?bulan=2026-09'        => [1, 3],
            'api/v1/presensi/tukin/rekap-il?bulan=2026-09'          => [1],
            'api/v1/presensi/tukin/rekap-il/xlsx?bulan=2026-09'     => [1],
            "api/v1/presensi/tukin/laporan/{$target}?bulan=2026-09" => [1, 3, 4, 5],
        ];

        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $akun = $this->buatAkunTanpaNip('lt' . $role . bin2hex(random_bytes(3)), $role, [
                'nip'                  => in_array($role, Role::UL_PEGAWAI, true) ? $this->pegawaiRekap($satker) : null,
                'id_satker'            => $role === Role::ADMIN_SATKER ? (string) $satker : null,
                'must_change_password' => 0,
            ]);

            foreach ($route as $url => $boleh) {
                $status = $this->asUser($akun)->get($url)->response()->getStatusCode();
                in_array($role, $boleh, true)
                    ? $this->assertSame(200, $status, "role {$role} {$url}")
                    : $this->assertSame(403, $status, "role {$role} {$url}");
            }
        }

        $pegawai = $this->buatAkunTanpaNip('ltp' . bin2hex(random_bytes(3)), Role::PEGAWAI, ['nip' => $target, 'must_change_password' => 0]);
        $this->assertSame(200, $this->asUser($pegawai)->get("api/v1/presensi/tukin/laporan/{$target}?bulan=2026-09")->response()->getStatusCode(), 'NIP sendiri');

        $res = $this->asUser($pegawai)->get("api/v1/presensi/tukin/laporan/{$target}/pdf?bulan=2026-09")->response();
        $this->assertSame(['application/pdf', 'attachment; filename="tukin_' . $target . '_2026-09.pdf"'], [$res->getHeaderLine('Content-Type'), $res->getHeaderLine('Content-Disposition')]);

        $this->clearAuthState();

        foreach (array_keys($route) as $url) {
            $this->assertSame(401, $this->withHeaders([])->get($url)->response()->getStatusCode(), $url);
        }
    }
}
