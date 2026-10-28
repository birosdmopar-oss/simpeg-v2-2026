<?php

declare(strict_types=1);

namespace Tests\Presensi\Rekap;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;

/**
 * PRD 5B §6.2 AC-D09b.6 (MAKE-042): route rekap × role 1–8 — rekap kehadiran 1, 3, 4 (F5B-10); rekap uang makan 1, 3;
 * varian IL hanya 1 (F5B-6); role lain 403, tanpa token 401. Unduhan XLSX lewat HTTP: content type, nama berkas.
 *
 * @internal
 */
final class RoleMatriksRekapTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;

    private const ROUTE = [
        'api/v1/presensi/rekap-kehadiran?bulan=2026-09'             => [1, 3, 4],
        'api/v1/presensi/rekap-kehadiran/xlsx?bulan=2026-09'        => [1, 3, 4],
        'api/v1/presensi/rekap-kehadiran/xlsx-harian?bulan=2026-09' => [1, 3, 4],
        'api/v1/presensi/uang-makan/rekap?bulan=2026-09'            => [1, 3],
        'api/v1/presensi/uang-makan/rekap/xlsx?bulan=2026-09'       => [1, 3],
        'api/v1/presensi/uang-makan/rekap-il?bulan=2026-09'         => [1],
        'api/v1/presensi/uang-makan/rekap-il/xlsx?bulan=2026-09'    => [1],
    ];

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testMatriksRoute(): void
    {
        $this->siapkanJenisPegawai();
        $satker = $this->buatSatker($this->buatUnit());

        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $akun = $this->buatAkunTanpaNip('rk' . $role . bin2hex(random_bytes(3)), $role, [
                'nip'                  => in_array($role, Role::UL_PEGAWAI, true) ? $this->buatPegawai() : null,
                'id_satker'            => $role === Role::ADMIN_SATKER ? (string) $satker : null,
                'must_change_password' => 0,
            ]);

            foreach (self::ROUTE as $url => $boleh) {
                $status = $this->asUser($akun)->get($url)->response()->getStatusCode();
                in_array($role, $boleh, true)
                    ? $this->assertSame(200, $status, "role {$role} {$url}")
                    : $this->assertSame(403, $status, "role {$role} {$url}");
            }
        }

        $this->clearAuthState();

        foreach (array_keys(self::ROUTE) as $url) {
            $this->assertSame(401, $this->withHeaders([])->get($url)->response()->getStatusCode(), $url);
        }
    }

    public function testUnduhanXlsxLewatHttp(): void
    {
        $this->siapkanJenisPegawai();
        $admin = $this->buatAkunTanpaNip('rkx' . bin2hex(random_bytes(3)), Role::SUPER_ADMIN, ['must_change_password' => 0]);
        $res   = $this->asUser($admin)->get('api/v1/presensi/rekap-kehadiran/xlsx-harian?bulan=2026-09')->response();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="rekap_kehadiran_harian_0_2026-09.xlsx"', $res->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('PK', (string) $res->getBody());

        $this->asUser($admin)->get('api/v1/presensi/uang-makan/rekap?bulan=2026-13')->assertStatus(422);
    }
}
