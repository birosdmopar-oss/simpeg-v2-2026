<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Hukdis;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kgb;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Pendidikan;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * `GET api/v1/pegawai/{nip}/riwayat` → `{ tabs }` (WS-1 M3 MAKE-006): descriptor = RiwayatRegistry::descriptorUntuk()
 * dengan Definisi nyata B-08/B-09/B-10/B-14, urutan pemeriksaan 401 → 403 lingkup → 404 NIP, lingkup PegawaiScope nyata.
 *
 * @internal
 */
final class RiwayatTabEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private const NIP_TIDAK_ADA = '999999999999999999';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngine(null, [new Pendidikan(), new Kp(), new Kgb(), new Hukdis()]);
        Services::injectMock('pegawaiScope', new PegawaiScope());
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    public function testTanpaToken401(): void
    {
        $this->get('api/v1/pegawai/' . $this->buatPegawai() . '/riwayat')->assertStatus(401);
    }

    public function testRole1SemuaTabUrutUrutanTab(): void
    {
        $nip         = $this->buatPegawai();
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);

        $result = $this->asUser($super)->get("api/v1/pegawai/{$nip}/riwayat");

        $result->assertStatus(200);
        $tabs = $this->json($result)['data']['tabs'];
        $this->assertSame(['kp', 'kgb', 'pendidikan', 'hukdis'], array_column($tabs, 'jenis'));
        $this->assertSame(
            ['jenis' => 'hukdis', 'label' => 'Riwayat Hukuman Disiplin', 'can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true, 'can_process' => false],
            $tabs[3],
        );
    }

    public function testRole1NipTidakAda404(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);

        $this->asUser($super)->get('api/v1/pegawai/' . self::NIP_TIDAK_ADA . '/riwayat')->assertStatus(404);
    }

    public function testPegawaiMilikSendiriLihatSajaUntukKpKgb(): void
    {
        [$nip, $auth, $pegawai] = $this->aktor(Role::PEGAWAI);

        $tabs = $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$nip}/riwayat"))['data']['tabs'];

        // Hukdis tanpa lihat pegawai (Matriks v2); KP/KGB lihat saja; Pendidikan self-service.
        $this->assertSame(['kp', 'kgb', 'pendidikan'], array_column($tabs, 'jenis'));
        $this->assertFalse($tabs[0]['can_create']);
        $this->assertFalse($tabs[1]['can_edit']);
        // Pendidikan role 2: tambah hanya selama CPNS (RiwayatDefinisi::bolehAksiUntuk, MAKE-005) — descriptor ikut hook.
        $this->assertSame((new Pendidikan())->bolehAksiUntuk($auth, AksiRiwayat::Tambah, $nip), $tabs[2]['can_create']);
        $this->assertFalse($tabs[2]['can_process']);
    }

    public function testPegawaiLainDanNipTidakAda403Seragam(): void
    {
        [, , $pegawai] = $this->aktor(Role::PEGAWAI);
        $lain          = $this->buatPegawai();

        $this->asUser($pegawai)->get("api/v1/pegawai/{$lain}/riwayat")->assertStatus(403);
        $this->asUser($pegawai)->get('api/v1/pegawai/' . self::NIP_TIDAK_ADA . '/riwayat')->assertStatus(403);
    }

    public function testAdminSatkerDibatasiLingkup(): void
    {
        $idSatker          = $this->buatSatker();
        $dalam             = $this->buatPegawaiDiSatker($idSatker);
        $luar              = $this->buatPegawaiDiSatker($this->buatSatker());
        [, , $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $idSatker);

        $tabs = $this->json($this->asUser($adminSatker)->get("api/v1/pegawai/{$dalam}/riwayat"))['data']['tabs'];
        $this->assertSame(['kp', 'kgb', 'pendidikan', 'hukdis'], array_column($tabs, 'jenis'));
        $this->assertTrue($tabs[0]['can_process']);

        $this->asUser($adminSatker)->get("api/v1/pegawai/{$luar}/riwayat")->assertStatus(403);
        $this->asUser($adminSatker)->get('api/v1/pegawai/' . self::NIP_TIDAK_ADA . '/riwayat')->assertStatus(403);
    }

    public function testRoleTanpaIzinJenisApaPunTabsKosong(): void
    {
        // Role 8 (Pimpinan): tidak ada di izin lihat Definisi mana pun → tabs kosong, bukan galat.
        $nip         = $this->buatPegawai();
        [, , $role8] = $this->aktor(Role::PIMPINAN);

        $result = $this->asUser($role8)->get("api/v1/pegawai/{$nip}/riwayat");

        $result->assertStatus(200);
        $this->assertSame([], $this->json($result)['data']['tabs']);
    }

    public function testCanProcessMemakaiBolehProses(): void
    {
        // CR-045: can_process mengikuti jalur proses (bolehProses), terpisah dari bolehUbah.
        $nip         = $this->buatPegawai();
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $scope       = new class () implements PegawaiScopeInterface {
            public function bolehLihat(AuthContext $auth, string $nip): bool
            {
                return true;
            }

            public function bolehUbah(AuthContext $auth, string $nip): bool
            {
                return true;
            }

            public function bolehProses(AuthContext $auth, string $nip): bool
            {
                return false;
            }

            public function terapkanKeQuery(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void
            {
            }
        };
        Services::injectMock('pegawaiScope', $scope);

        $tabs = $this->json($this->asUser($super)->get("api/v1/pegawai/{$nip}/riwayat"))['data']['tabs'];

        $this->assertTrue($tabs[0]['can_edit']);
        $this->assertFalse($tabs[0]['can_process']);
    }
}
