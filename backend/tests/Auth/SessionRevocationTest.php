<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * T-01 (QAFUNC-002-R1 24-09) — pencabutan massal sesi (ganti/reset password, perubahan/penghapusan akun oleh admin)
 * MENGHAPUS baris refresh token seperti logout. Cookie lama di tab/perangkat lain yang me-refresh terbaca "tidak
 * dikenal" (401, cookie dihapus) dan TIDAK memicu reuse detection yang mencabut sesi baru pengguna setelah ia login
 * ulang — sebelumnya sesi baru ikut dicabut berulang kali.
 *
 * @internal
 */
final class SessionRevocationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP         = '199002152015022002'; // role 2, S01
    private const SUPER_ADMIN = '198501012010011001';
    private const NEW         = 'PasswordBaru456';
    private const ADMIN_SET   = 'PasswordAdmin789';

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

    public function testStaleSessionAfterChangePasswordDoesNotRevokeNewSession(): void
    {
        $deviceA = $this->deviceSession(self::NIP);

        $this->clearAuthState();
        $this->asNip(self::NIP)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $this->assertStaleDeviceCannotRevokeNewSession(self::NIP, self::NEW, $deviceA);
    }

    /**
     * Reproduksi 24-09: perangkat A login → lupa/reset password → login di perangkat B → perangkat A refresh.
     */
    public function testStaleSessionAfterResetPasswordDoesNotRevokeNewSession(): void
    {
        $deviceA = $this->deviceSession(self::NIP);

        // Token reset ditanam langsung (setara hasil forgot-password) agar test tidak bergantung pada kanal/captcha.
        $resetToken = bin2hex(random_bytes(32));
        $this->db->table('forgot_attempts')->insert([
            'username'     => self::NIP,
            'ip_address'   => null,
            'token_hash'   => hash('sha256', $resetToken),
            'expires_at'   => date('Y-m-d H:i:s', time() + 1800),
            'used_at'      => null,
            'requested_at' => date('Y-m-d H:i:s'),
        ]);

        $this->clearAuthState();
        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token'                     => $resetToken,
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $this->assertStaleDeviceCannotRevokeNewSession(self::NIP, self::NEW, $deviceA);
    }

    /**
     * @return iterable<string, array{list<array{string, string, array<string, mixed>}>, string}>
     */
    public static function adminAccountChanges(): iterable
    {
        yield 'ubah role' => [[['put', '', ['user_level' => Role::PPPK]]], AuthSeeder::PASSWORD];

        yield 'ganti password oleh admin' => [[['put', '', ['password' => self::ADMIN_SET]]], self::ADMIN_SET];

        yield 'pindah satker' => [[['put', '', ['id_satker' => 'S02']]], AuthSeeder::PASSWORD];

        yield 'nonaktif lalu aktif lagi' => [[['patch', '/status', ['status' => '0']], ['patch', '/status', ['status' => '1']]], AuthSeeder::PASSWORD];
    }

    /**
     * @param list<array{string, string, array<string, mixed>}> $requests
     */
    #[DataProvider('adminAccountChanges')]
    public function testStaleSessionAfterAdminAccountChangeDoesNotRevokeNewSession(array $requests, string $passwordAfter): void
    {
        $deviceA = $this->deviceSession(self::NIP);
        $url     = 'api/v1/auth/users/' . $this->idOf(self::NIP);

        foreach ($requests as [$method, $suffix, $body]) {
            $this->clearAuthState();
            $request = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json');
            $result  = $method === 'put' ? $request->put($url . $suffix, $body) : $request->patch($url . $suffix, $body);
            $result->assertStatus(200);
        }

        $this->assertStaleDeviceCannotRevokeNewSession(self::NIP, $passwordAfter, $deviceA);
    }

    public function testStaleSessionAfterAccountDeletionIsUnknownToken(): void
    {
        $deviceA = $this->deviceSession(self::NIP);

        $this->clearAuthState();
        $this->asNip(self::SUPER_ADMIN)->delete('api/v1/auth/users/' . $this->idOf(self::NIP))->assertStatus(200);

        $this->assertSame(0, $this->db->table('token')->where('nip', self::NIP)->countAllResults(), 'Seluruh baris token akun yang dihapus harus ikut dihapus');

        foreach ($deviceA as $stale) {
            $this->assertStaleRefreshIsUnknown($stale);
        }
    }

    /**
     * Sesi perangkat A yang sudah pernah refresh sekali: token hasil rotasi (revoked=1) dan token aktifnya.
     *
     * @return array{rotated: string, current: string}
     */
    private function deviceSession(string $nip): array
    {
        $rotated = $this->issueTokensFor($nip)['refresh_token'];

        $this->clearAuthState();
        $this->setRefreshCookie($rotated);
        $result = $this->post('api/v1/auth/refresh');
        $result->assertStatus(200);
        $current = (string) $this->responseCookie($result, 'refresh_token');

        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $rotated), 'revoked' => 1]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $current), 'revoked' => 0]);

        return ['rotated' => $rotated, 'current' => $current];
    }

    /**
     * Inti T-01: setelah pencabutan massal pengguna login ulang di perangkat B, lalu perangkat A (cookie lama) refresh
     * berulang kali → selalu 401 "tidak dikenal" dengan cookie refresh dihapus, dan sesi B tetap hidup.
     *
     * @param array{rotated: string, current: string} $deviceA
     */
    private function assertStaleDeviceCannotRevokeNewSession(string $nip, string $password, array $deviceA): void
    {
        $this->assertSame(0, $this->db->table('token')->where('nip', $nip)->countAllResults(), 'Seluruh baris token akun (aktif maupun hasil rotasi) harus dihapus');

        $this->clearAuthState();
        $login = $this->login($nip, $password);
        $login->assertStatus(200);
        $deviceB = (string) $this->responseCookie($login, 'refresh_token');

        for ($round = 1; $round <= 2; $round++) {
            foreach ($deviceA as $stale) {
                $this->assertStaleRefreshIsUnknown($stale);
            }

            $this->assertSame(1, $this->db->table('token')->where('nip', $nip)->where('revoked', 0)->countAllResults(), "Putaran {$round}: sesi baru di perangkat B tidak boleh ikut dicabut");

            $this->clearAuthState();
            $this->setRefreshCookie($deviceB);
            $refreshB = $this->post('api/v1/auth/refresh');
            $refreshB->assertStatus(200);
            $deviceB = (string) $this->responseCookie($refreshB, 'refresh_token');
        }
    }

    private function assertStaleRefreshIsUnknown(string $staleRefresh): void
    {
        $this->clearAuthState();
        $this->setRefreshCookie($staleRefresh);
        $result = $this->post('api/v1/auth/refresh');

        $result->assertStatus(401);
        $this->assertSame('Refresh token tidak dikenal.', $this->json($result)['message'], 'Token yang dicabut massal bukan reuse');
        $this->assertSame('', $this->responseCookie($result, 'refresh_token'), 'Cookie refresh lama harus dihapus');
    }

    private function idOf(string $nip): int
    {
        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }
}
