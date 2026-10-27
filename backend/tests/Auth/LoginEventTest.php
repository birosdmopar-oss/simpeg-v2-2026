<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Libraries\Auth\AuthService;
use App\Libraries\Layanan\Cuti\Servis\ResetTundaCuti;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-022 (PRD Fase 4 §6.4.7, AC-04.10) — kontrak event `auth.login`: terbit tepat sekali pada login sukses dengan
 * payload `{id_pengguna, nip}`; tidak terbit pada login gagal / terkunci / akun nonaktif; listener yang melempar tidak
 * menggagalkan login; tidak menambah baris audit; akun tanpa NIP → `nip = null`.
 *
 * Listener bawaan aplikasi (Config\Events) dilepas selama test dan dipasang lagi di tearDown agar tidak bocor.
 *
 * @internal
 */
final class LoginEventTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $seed = AuthSeeder::class;

    private const NIP = '199002152015022002';

    /** @var list<array<string, mixed>> */
    private array $diterima = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->diterima = [];
        Events::removeAllListeners(AuthService::EVENT_LOGIN_SUKSES);
        Events::on(AuthService::EVENT_LOGIN_SUKSES, function (array $payload): void {
            $this->diterima[] = $payload;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners(AuthService::EVENT_LOGIN_SUKSES);
        Events::on(AuthService::EVENT_LOGIN_SUKSES, [ResetTundaCuti::class, 'tangani']);
        $this->clearAuthState();
        $this->bersihkanDataDbv010();
        parent::tearDown();
    }

    public function testEventTerbitSekaliDenganPayloadIdPenggunaDanNip(): void
    {
        $json = $this->json($this->login(self::NIP, AuthSeeder::PASSWORD));

        $this->assertCount(1, $this->diterima);
        $this->assertSame(['id_pengguna', 'nip'], array_keys($this->diterima[0]));
        $this->assertSame($json['data']['user']['id_pengguna'], $this->diterima[0]['id_pengguna']);
        $this->assertSame(self::NIP, $this->diterima[0]['nip']);
    }

    public function testEventTidakTerbitPadaLoginGagal(): void
    {
        $this->login(self::NIP, 'salah-password-1')->assertStatus(401);
        $this->login('000000000000000000', AuthSeeder::PASSWORD)->assertStatus(401);
        $this->login(self::NIP, AuthSeeder::PASSWORD, '')->assertStatus(422);

        $this->assertSame([], $this->diterima);
    }

    public function testEventTidakTerbitUntukAkunNonaktif(): void
    {
        $this->login(AuthSeeder::NIP_INACTIVE, AuthSeeder::PASSWORD)->assertStatus(401);

        $this->assertSame([], $this->diterima);
    }

    public function testEventTidakTerbitUntukAkunTerkunci(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login(self::NIP, 'salah-password-1')->assertStatus(401);
        }

        $this->assertGreaterThanOrEqual(400, $this->login(self::NIP, AuthSeeder::PASSWORD)->response()->getStatusCode());
        $this->assertSame([], $this->diterima);
    }

    public function testEventTidakTerbitPadaRefreshToken(): void
    {
        $login          = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $refresh        = (string) $this->responseCookie($login, 'refresh_token');
        $this->diterima = [];

        $this->clearAuthState();
        $this->withBodyFormat('json')->post('api/v1/auth/refresh', ['refresh_token' => $refresh]);

        $this->assertSame([], $this->diterima);
    }

    public function testListenerYangMelemparTidakMenggagalkanLogin(): void
    {
        Events::removeAllListeners(AuthService::EVENT_LOGIN_SUKSES);
        Events::on(AuthService::EVENT_LOGIN_SUKSES, static function (): void {
            throw new RuntimeException('listener rusak rahasia-123');
        });

        $result = $this->login(self::NIP, AuthSeeder::PASSWORD);

        $result->assertStatus(200);
        $json = $this->json($result);
        $this->assertNotEmpty($json['data']['access_token']);
        $this->assertNotNull($this->responseCookie($result, 'refresh_token'));
        $this->seeInDatabase('token', ['nip' => self::NIP, 'revoked' => 0]);
    }

    public function testListenerResetTundaTidakMenambahBarisAudit(): void
    {
        // Login pemanasan (lazy rehash password seed menulis audit sekali), lalu pembanding tanpa listener Layanan,
        // lalu login dengan listener bawaan: selisih baris audit harus sama.
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);
        $this->clearAuthState();

        $awal = $this->jumlahAudit();
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);
        $tanpaListener = $this->jumlahAudit() - $awal;

        $this->clearAuthState();
        Events::removeAllListeners(AuthService::EVENT_LOGIN_SUKSES);
        Events::on(AuthService::EVENT_LOGIN_SUKSES, [ResetTundaCuti::class, 'tangani']);

        $awal = $this->jumlahAudit();
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        $this->assertSame($tanpaListener, $this->jumlahAudit() - $awal);
    }

    public function testAkunTanpaNipMenghasilkanPayloadNipNullTanpaGalat(): void
    {
        $akun = $this->buatAkunTanpaNip('admin.event');

        $this->login('admin.event', AuthSeeder::PASSWORD)->assertStatus(200);

        $this->assertCount(1, $this->diterima);
        $this->assertSame((int) $akun['id_pengguna'], $this->diterima[0]['id_pengguna']);
        $this->assertNull($this->diterima[0]['nip']);
    }

    private function jumlahAudit(): int
    {
        return $this->db->table('audit_logs')->countAllResults();
    }
}
