<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\MasterDataTestTrait;

/**
 * MAKE-038 — regresi "E2E ringan" alur inti di level API, tanpa dependency baru: login lewat endpoint (bukan token
 * buatan test) → master data → kepegawaian, memakai token hasil login di setiap langkah berikutnya. Satu test per
 * aktor, langkah berantai: kegagalan di tengah menunjukkan titik alur yang putus.
 *
 * Bukan pengganti E2E browser: tampilan, interaksi, dan cookie browser diuji Playwright nanti (kartu Trello MT-009).
 *
 * @internal
 */
final class AlurIntiApiTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;
    use PegawaiFixtureTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testSuperAdminLoginLaluKelolaMasterLaluBukaPegawai(): void
    {
        $nipAdmin = $this->buatPegawai(['nama' => 'Admin Alur Inti']);
        $this->buatAkunUntuk($nipAdmin, Role::SUPER_ADMIN);
        $nipPegawai = $this->buatPegawai(['nama' => 'Rani Alur Inti']);

        // 1. Login (captcha mock) → token akses dari respons.
        $token = $this->loginToken($nipAdmin);

        // 2. Sesi terbaca.
        $me = $this->sebagai($token)->get('api/v1/auth/me');
        $me->assertStatus(200);
        $this->assertSame($nipAdmin, $this->json($me)['data']['user']['nip']);
        $this->assertSame(Role::SUPER_ADMIN, (int) $this->json($me)['data']['user']['user_level']);

        // 3. Master data: metadata form, tambah entri, entri baru muncul di dropdown modul lain.
        $this->sebagai($token)->get('api/v1/master/meta')->assertStatus(200);

        $baru = $this->sebagai($token)->withBodyFormat('json')->post('api/v1/master/agama', self::masterFixtures()['agama']['new']);
        $this->assertSame(201, $baru->response()->getStatusCode(), (string) $baru->getJSON());
        $agama = $this->json($baru)['data'];
        $this->assertSame('Kepercayaan', $agama['agama']);

        $this->assertContains((string) $agama['id_agama'], $this->idOptions($this->sebagai($token)->get('api/v1/master/agama/options')));

        // 4. Kepegawaian: daftar → detail → tab riwayat pegawai yang sama.
        $daftar = $this->sebagai($token)->get('api/v1/pegawai?search=Rani%20Alur');
        $daftar->assertStatus(200);
        $this->assertContains($nipPegawai, array_column($this->json($daftar)['data']['items'], 'nip'));

        $detail = $this->sebagai($token)->get("api/v1/pegawai/{$nipPegawai}");
        $detail->assertStatus(200);
        $this->assertSame('Rani Alur Inti', $this->json($detail)['data']['nama']);

        $this->sebagai($token)->get("api/v1/pegawai/{$nipPegawai}/riwayat")->assertStatus(200);

        // 5. Logout menutup sesi refresh.
        $this->sebagai($token)->post('api/v1/auth/logout')->assertStatus(200);
    }

    public function testPegawaiLoginHanyaMelihatDataSendiriDanDropdownMaster(): void
    {
        $nip = $this->buatPegawai(['nama' => 'Budi Alur Inti']);
        $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $lain = $this->buatPegawai(['nama' => 'Pegawai Lain Alur']);

        $token = $this->loginToken($nip);

        $this->sebagai($token)->get('api/v1/auth/me')->assertStatus(200);

        // Master: dropdown terbuka (UL_ALL), kelola master hanya role 1.
        $this->assertContains('1', $this->idOptions($this->sebagai($token)->get('api/v1/master/agama/options')));
        $this->sebagai($token)->get('api/v1/master/agama')->assertStatus(403);
        $this->sebagai($token)->get('api/v1/faq')->assertStatus(200);

        // Kepegawaian: daftar pegawai bukan untuk role 2; detail diri sendiri boleh, pegawai lain tidak.
        $this->sebagai($token)->get('api/v1/pegawai')->assertStatus(403);

        $sendiri = $this->sebagai($token)->get("api/v1/pegawai/{$nip}");
        $sendiri->assertStatus(200);
        $this->assertSame('Budi Alur Inti', $this->json($sendiri)['data']['nama']);

        $this->sebagai($token)->get("api/v1/pegawai/{$lain}")->assertStatus(403);
    }

    // ------------------------------------------------------------------

    private function loginToken(string $nip): string
    {
        $login = $this->login($nip, AuthSeeder::PASSWORD);
        $login->assertStatus(200);

        $token = $this->json($login)['data']['access_token'] ?? null;
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        // Request berikutnya hanya membawa token ini (bukan state login sisa request sebelumnya).
        $this->clearAuthState();
        $this->resetMasterState();

        return $token;
    }

    /**
     * @return $this
     */
    private function sebagai(string $token): static
    {
        service('authContext')->clear();

        return $this->withBodyFormat('')->withBearer($token);
    }

    /**
     * @return list<string>
     */
    private function idOptions(TestResponse $result): array
    {
        $result->assertStatus(200);

        return array_map(static fn (array $o): string => (string) $o['id'], $this->json($result)['data']);
    }
}
