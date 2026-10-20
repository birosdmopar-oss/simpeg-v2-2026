<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\AuthService;
use App\Libraries\Auth\PasswordExpiry;
use App\Libraries\Auth\PasswordVerifier;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Auth as AuthConfig;
use Config\Jwt as JwtConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-028 [K] — umur password (`pengguna.expired_at`). Legacy `core/MY_Controller.php:223-230`: lewat `expired_at`
 * = pengguna hanya wajib mengganti password (bukan ditolak); NULL = tidak kedaluwarsa. Nilainya diisi trigger DB
 * legacy (`pengguna_beIns` +1 tahun, `pengguna_beUpd` +3 bulan saat password berubah, hanya status aktif) yang di v2
 * dipindah ke `PasswordExpiry`. Penegakan memakai mekanisme MAKE-020 (claim `must_change_password`), dihitung ulang
 * saat login DAN refresh.
 *
 * @internal
 */
final class PasswordExpiryTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $seed = AuthSeeder::class;

    private const NIP     = '199002152015022002';
    private const NEW     = 'PasswordBaru456';
    private const NIP_BRU = '200001012024011001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Aturan murni
    // ------------------------------------------------------------------

    public function testIsExpiredBatasStrictDanNull(): void
    {
        $batas = strtotime('2026-10-20 12:00:00');

        $this->assertFalse(PasswordExpiry::isExpired('2026-10-20 12:00:00', $batas), 'tepat di batas: belum (legacy now > exp)');
        $this->assertFalse(PasswordExpiry::isExpired('2026-10-20 12:00:00', $batas - 1), 'sebelum batas');
        $this->assertTrue(PasswordExpiry::isExpired('2026-10-20 12:00:00', $batas + 1), 'sesudah batas');

        foreach ([null, '', '   ', 'bukan tanggal', 0, false] as $nilai) {
            $this->assertFalse(PasswordExpiry::isExpired($nilai, $batas + 10 * 365 * 86400), var_export($nilai, true) . ' = tidak kedaluwarsa');
        }
    }

    public function testAddMonthsMengikutiMysqlHariDijepit(): void
    {
        $tambah = static fn (string $dari, int $bulan): string => date('Y-m-d H:i:s', PasswordExpiry::addMonths(strtotime($dari), $bulan));

        $this->assertSame('2026-04-30 10:20:30', $tambah('2026-01-31 10:20:30', 3), '31 Jan + 3 bulan = 30 Apr (MySQL), bukan 1 Mei');
        $this->assertSame('2027-01-20 00:00:00', $tambah('2026-10-20 00:00:00', 3));
        $this->assertSame('2027-10-20 23:59:59', $tambah('2026-10-20 23:59:59', 12));
        $this->assertSame('2025-02-28 08:00:00', $tambah('2024-02-29 08:00:00', 12), '29 Feb kabisat + 1 tahun = 28 Feb');
        $this->assertSame('2027-02-28 08:00:00', $tambah('2026-11-30 08:00:00', 3));
    }

    public function testIntervalDariConfigDanHanyaStatusAktif(): void
    {
        $config = config(AuthConfig::class);
        $this->assertSame(12, $config->newAccountValidityMonths, 'trigger pengguna_beIns: +1 YEAR');
        $this->assertSame(3, $config->passwordValidityMonths, 'trigger pengguna_beUpd: +3 MONTH');

        $now = strtotime('2026-10-20 08:00:00');
        $this->assertSame(['expired_at' => '2027-10-20 08:00:00'], PasswordExpiry::forNewAccount('1', $now));
        $this->assertSame(['expired_at' => '2027-01-20 08:00:00'], PasswordExpiry::forPasswordChange('1', $now));
        $this->assertSame([], PasswordExpiry::forNewAccount('0', $now), 'akun nonaktif tidak disentuh');
        $this->assertSame([], PasswordExpiry::forPasswordChange('0', $now));
        $this->assertSame([], PasswordExpiry::forPasswordChange('10', $now));
    }

    // ------------------------------------------------------------------
    // Penegakan: login dan refresh
    // ------------------------------------------------------------------

    public function testClaimBatasWaktuTepatSebelumDanSesudah(): void
    {
        $this->setExpiredAt(self::NIP, '2026-10-20 12:00:00');
        $user  = $this->akun(self::NIP);
        $batas = strtotime('2026-10-20 12:00:00');

        $this->assertArrayNotHasKey('must_change_password', AuthService::claimsFor($user, $batas - 1));
        $this->assertArrayNotHasKey('must_change_password', AuthService::claimsFor($user, $batas), 'tepat di batas belum kedaluwarsa');
        $this->assertTrue(AuthService::claimsFor($user, $batas + 1)['must_change_password']);
    }

    public function testLoginKedaluwarsaTetapBerhasilTetapiWajibGantiPassword(): void
    {
        $this->setExpiredAt(self::NIP, date('Y-m-d H:i:s', time() - 60));

        $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $data = $this->json($login)['data'];
        $this->assertTrue($data['user']['must_change_password']);

        // Penanda MAKE-020 di DB tidak dinyalakan — kedaluwarsa dihitung, bukan disimpan.
        $this->assertSame(0, (int) $this->akun(self::NIP)['must_change_password']);

        $this->clearAuthState();
        $this->assertDitolak($this->withBearer($data['access_token'])->get('api/v1/_rbac/any'));

        $this->clearAuthState();
        $me = $this->withBearer($data['access_token'])->get('api/v1/auth/me');
        $me->assertStatus(200);
        $this->assertTrue($this->json($me)['data']['user']['must_change_password']);
    }

    public function testExpiredAtNullDanMasihBerlakuTidakMemaksa(): void
    {
        $this->assertNull($this->akun(self::NIP)['expired_at'], 'fixture: NULL');

        foreach ([null, date('Y-m-d H:i:s', time() + 3600)] as $nilai) {
            $this->setExpiredAt(self::NIP, $nilai);
            $this->clearAuthState();
            $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
            $login->assertStatus(200);
            $data = $this->json($login)['data'];
            $this->assertFalse($data['user']['must_change_password'], var_export($nilai, true));

            $this->clearAuthState();
            $this->withBearer($data['access_token'])->get('api/v1/_rbac/any')->assertStatus(200);
        }
    }

    public function testRefreshMenghitungUlangDanSesiLamaTerkena(): void
    {
        $this->setExpiredAt(self::NIP, date('Y-m-d H:i:s', time() + 3600));
        $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $refresh = $this->responseCookie($login, config(JwtConfig::class)->refreshCookie);
        $this->assertNotNull($refresh);

        // Sesi sehat: refresh pertama tanpa penanda.
        $this->clearAuthState();
        $this->setRefreshCookie($refresh);
        $pertama = $this->post('api/v1/auth/refresh');
        $pertama->assertStatus(200);
        $this->clearAuthState();
        $this->withBearer($this->json($pertama)['data']['access_token'])->get('api/v1/_rbac/any')->assertStatus(200);

        // Waktu berjalan melewati expired_at tanpa peristiwa yang mencabut sesi.
        $this->setExpiredAt(self::NIP, date('Y-m-d H:i:s', time() - 1));
        $refreshBaru = $this->responseCookie($pertama, config(JwtConfig::class)->refreshCookie);
        $this->assertNotNull($refreshBaru);

        $this->clearAuthState();
        $this->setRefreshCookie($refreshBaru);
        $kedua = $this->post('api/v1/auth/refresh');
        $kedua->assertStatus(200);
        $akses = $this->json($kedua)['data']['access_token'];

        $this->clearAuthState();
        $this->assertDitolak($this->withBearer($akses)->get('api/v1/_rbac/any'));

        // Access token yang terbit sebelum kedaluwarsa tetap berlaku sampai TTL habis.
        $this->clearAuthState();
        $this->withBearer($this->json($pertama)['data']['access_token'])->get('api/v1/_rbac/any')->assertStatus(200);
    }

    public function testRefreshAkunNullTidakTerkena(): void
    {
        $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $refresh = $this->responseCookie($login, config(JwtConfig::class)->refreshCookie);

        $this->clearAuthState();
        $this->setRefreshCookie($refresh);
        $baru = $this->post('api/v1/auth/refresh');
        $baru->assertStatus(200);

        $this->clearAuthState();
        $this->withBearer($this->json($baru)['data']['access_token'])->get('api/v1/_rbac/any')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Penulisan expired_at di tiap jalur
    // ------------------------------------------------------------------

    public function testGantiPasswordSendiriMengisiTigaBulanDanMelepasKeduanya(): void
    {
        $this->setExpiredAt(self::NIP, date('Y-m-d H:i:s', time() - 60));
        $this->db->table('pengguna')->where('nip', self::NIP)->update(['must_change_password' => 1]);

        $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $token = (string) $this->json($login)['data']['access_token'];

        $this->clearAuthState();
        $this->withBearer($token)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $akun = $this->akun(self::NIP);
        $this->assertSame(0, (int) $akun['must_change_password']);
        $this->assertDekat(PasswordExpiry::addMonths(time(), 3), $akun['expired_at']);

        // Login ulang: tidak lagi wajib ganti.
        $this->clearAuthState();
        $ulang = $this->login(self::NIP, self::NEW);
        $ulang->assertStatus(200);
        $this->assertFalse($this->json($ulang)['data']['user']['must_change_password']);
    }

    public function testResetLewatTokenEmailMengisiTigaBulan(): void
    {
        config(AuthConfig::class)->exposeResetTokenInResponse = true;
        $forgot                                               = $this->forgotPassword(self::NIP);
        $forgot->assertStatus(200);

        $this->clearAuthState();
        config(AuthConfig::class)->exposeResetTokenInResponse = true;
        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token'                     => $this->json($forgot)['data']['token'],
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $this->assertDekat(PasswordExpiry::addMonths(time(), 3), $this->akun(self::NIP)['expired_at']);
    }

    public function testAdminMengaturPasswordAkunLainDanAkunSendiriMengisiTigaBulan(): void
    {
        $admin = AuthSeeder::nipForRole(Role::SUPER_ADMIN);

        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->akun(self::NIP)['id_pengguna'], ['password' => 'ResetAdmin2026'])
            ->assertStatus(200);
        $lain = $this->akun(self::NIP);
        $this->assertSame(1, (int) $lain['must_change_password'], 'MAKE-020 tetap menyala untuk password yang diatur admin');
        $this->assertDekat(PasswordExpiry::addMonths(time(), 3), $lain['expired_at']);

        $this->clearAuthState();
        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->akun($admin)['id_pengguna'], ['password' => 'AdminSendiri2026'])
            ->assertStatus(200);
        $sendiri = $this->akun($admin);
        $this->assertSame(0, (int) $sendiri['must_change_password']);
        $this->assertDekat(PasswordExpiry::addMonths(time(), 3), $sendiri['expired_at']);
    }

    public function testUbahFieldLainTanpaPasswordTidakMenyentuhExpiredAt(): void
    {
        $admin = AuthSeeder::nipForRole(Role::SUPER_ADMIN);
        $this->setExpiredAt(self::NIP, '2030-01-02 03:04:05');

        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->akun(self::NIP)['id_pengguna'], ['name' => 'Nama Baru'])
            ->assertStatus(200);

        $this->assertSame('2030-01-02 03:04:05', $this->akun(self::NIP)['expired_at']);
    }

    public function testAdminMembuatAkunMengisiSatuTahunHanyaBilaAktif(): void
    {
        $admin = AuthSeeder::nipForRole(Role::SUPER_ADMIN);
        $this->daftarkanPegawai(self::NIP_BRU, '200001012024011002');

        $this->asNip($admin)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => self::NIP_BRU, 'password' => 'AkunBaru2026', 'user_level' => Role::PEGAWAI,
        ])->assertStatus(201);
        $this->assertDekat(PasswordExpiry::addMonths(time(), 12), $this->akun(self::NIP_BRU)['expired_at']);

        $this->clearAuthState();
        $this->asNip($admin)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => '200001012024011002', 'password' => 'AkunBaru2026', 'user_level' => Role::PEGAWAI, 'status' => '0',
        ])->assertStatus(201);
        $this->assertNull($this->akun('200001012024011002')['expired_at'], 'akun dibuat nonaktif: tidak disentuh');
    }

    public function testAkunNonaktifTidakDiubahTetapDiisiBilaDiaktifkanBersamaPassword(): void
    {
        $admin = AuthSeeder::nipForRole(Role::SUPER_ADMIN);
        $id    = $this->akun(AuthSeeder::NIP_INACTIVE)['id_pengguna'];
        $this->setExpiredAt(AuthSeeder::NIP_INACTIVE, '2020-05-06 07:08:09');

        // Status akhir nonaktif → expired_at apa adanya walau password berubah.
        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['password' => 'ResetAdmin2026'])->assertStatus(200);
        $this->assertSame('2020-05-06 07:08:09', $this->akun(AuthSeeder::NIP_INACTIVE)['expired_at']);

        // Diaktifkan bersamaan dengan password baru → status akhir aktif → +3 bulan.
        $this->clearAuthState();
        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['password' => 'ResetAdmin2027', 'status' => '1'])->assertStatus(200);
        $this->assertDekat(PasswordExpiry::addMonths(time(), 3), $this->akun(AuthSeeder::NIP_INACTIVE)['expired_at']);
    }

    public function testProvisionerAkunBaruMengisiSatuTahun(): void
    {
        $this->daftarkanPegawai(self::NIP_BRU);
        $pengguna = new PenggunaModel($this->db);
        $hasil    = (new AccountProvisioner($pengguna, new PasswordVerifier($pengguna)))->provisionForPegawai(['nip' => self::NIP_BRU, 'id_unit' => 'U01', 'id_satker' => 'S03']);

        $this->assertTrue($hasil['created']);
        $this->assertDekat(PasswordExpiry::addMonths(time(), 12), $this->akun(self::NIP_BRU)['expired_at']);
    }

    public function testLazyRehashBukanPerubahanPassword(): void
    {
        // Akun seed masih md5 legacy; login pertama menukar hash ke Argon2id (rehash) — password pemilik tidak berubah.
        $sebelum = $this->akun(self::NIP);
        $this->assertSame(md5(AuthSeeder::PASSWORD), $sebelum['password_legacy']);

        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        $akun = $this->akun(self::NIP);
        $this->assertNull($akun['password_legacy'], 'rehash terjadi');
        $this->assertNull($akun['expired_at'], 'umur password tidak diperpanjang oleh rehash');
    }

    // ------------------------------------------------------------------

    private function setExpiredAt(string $nip, ?string $nilai): void
    {
        $this->db->table('pengguna')->where('nip', $nip)->update(['expired_at' => $nilai]);
    }

    /**
     * @return array<string, mixed>
     */
    private function akun(string $nip): array
    {
        $row = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();
        $this->assertIsArray($row, "akun {$nip}");

        return $row;
    }

    /**
     * expired_at (string DATETIME) berada dalam ±10 detik dari $diharapkan.
     */
    private function assertDekat(int $diharapkan, mixed $expiredAt): void
    {
        $this->assertIsString($expiredAt, 'expired_at terisi');
        $this->assertEqualsWithDelta($diharapkan, strtotime($expiredAt), 10);
    }

    private function assertDitolak(TestResponse $result): void
    {
        $result->assertStatus(403);
        $this->assertSame('password_change_required', $this->json($result)['code']);
    }
}
