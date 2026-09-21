<?php

declare(strict_types=1);

namespace Tests\Auth;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-05 — refresh (rotating), reuse → seluruh sesi dicabut, logout menghapus refresh token di DB; A-13 JWT expiry.
 *
 * @internal
 */
final class TokenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP = '199002152015022002';

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

    public function testRefreshWithCookieRotatesTokens(): void
    {
        $login   = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $refresh = (string) $this->responseCookie($login, 'refresh_token');
        $access  = $this->json($login)['data']['access_token'];

        $this->clearAuthState();
        $this->setRefreshCookie($refresh);

        $result = $this->post('api/v1/auth/refresh');

        $result->assertStatus(200);
        $json = $this->json($result);
        $this->assertNotSame($access, $json['data']['access_token']);
        $this->assertSame(self::NIP, service('jwt')->verifyAccessToken($json['data']['access_token'])['sub']);

        $newRefresh = (string) $this->responseCookie($result, 'refresh_token');
        $this->assertNotSame($refresh, $newRefresh);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $refresh), 'revoked' => 1]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $newRefresh), 'revoked' => 0]);
    }

    public function testRefreshWithoutCookieReturns401(): void
    {
        $this->post('api/v1/auth/refresh')->assertStatus(401);
    }

    public function testRefreshWithUnknownTokenReturns401(): void
    {
        $this->setRefreshCookie(bin2hex(random_bytes(32)));
        $this->post('api/v1/auth/refresh')->assertStatus(401);
    }

    public function testRefreshTokenReuseIsRejectedAndInvalidatesAllSessions(): void
    {
        // Dua sesi (dua perangkat) untuk akun yang sama.
        $sessionA = $this->issueTokensFor(self::NIP);
        $sessionB = $this->issueTokensFor(self::NIP);

        // Sesi A refresh normal → token A lama revoked, A' aktif.
        $this->setRefreshCookie($sessionA['refresh_token']);
        $rotated = $this->post('api/v1/auth/refresh');
        $rotated->assertStatus(200);
        $aPrime = (string) $this->responseCookie($rotated, 'refresh_token');

        // Reuse token A lama (dicuri/replay) → 401 dan SELURUH sesi nip ini dicabut (A', B).
        $this->setRefreshCookie($sessionA['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        $this->assertSame(0, $this->db->table('token')->where('nip', self::NIP)->where('revoked', 0)->countAllResults(), 'Tidak boleh ada refresh token aktif tersisa');

        $this->setRefreshCookie($aPrime);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        $this->setRefreshCookie($sessionB['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $jwt = service('jwt');
        $jwt->setNow(time() - 7 * 86400 - 1);
        $tokens = $jwt->issueTokenPair(['sub' => self::NIP, 'role' => 2]);
        $jwt->setNow(null);

        $this->setRefreshCookie($tokens['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);
    }

    public function testExpiredAccessTokenIsRejectedOnProtectedEndpoint(): void
    {
        $jwt = service('jwt');
        $jwt->setNow(time() - 3601);
        $token = $jwt->issueAccessToken(['sub' => self::NIP, 'role' => 2]);
        $jwt->setNow(null);

        $this->withBearer($token)->get('api/v1/auth/me')->assertStatus(401);
    }

    public function testLogoutRevokesRefreshTokenInDbClearsCookiesAndAudits(): void
    {
        $login   = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $refresh = (string) $this->responseCookie($login, 'refresh_token');
        $access  = $this->json($login)['data']['access_token'];

        $this->clearAuthState();
        $this->setRefreshCookie($refresh);

        $result = $this->withBearer($access)->post('api/v1/auth/logout');

        $result->assertStatus(200);
        // A-05: row refresh token DIHAPUS dari DB, bukan sekadar revoked / cookie di-clear.
        $this->dontSeeInDatabase('token', ['token_hash' => hash('sha256', $refresh)]);
        $this->assertTrue($result->response()->hasCookie('refresh_token'));
        $this->assertSame('', $result->response()->getCookie('refresh_token')->getValue(), 'Cookie refresh dihapus');

        // Refresh token yang sudah di-logout tidak bisa dipakai lagi.
        $this->clearAuthState();
        $this->setRefreshCookie($refresh);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        // Audit logout dengan actor benar.
        $this->seeInDatabase('audit_logs', ['event' => 'logout', 'nip_actor' => self::NIP, 'entity' => 'pengguna']);
    }

    public function testLogoutDeletesOnlyCurrentSession(): void
    {
        $sessionA = (string) $this->responseCookie($this->login(self::NIP, AuthSeeder::PASSWORD), 'refresh_token');
        $this->clearAuthState();
        $loginB   = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $sessionB = (string) $this->responseCookie($loginB, 'refresh_token');
        $accessB  = $this->json($loginB)['data']['access_token'];

        $this->clearAuthState();
        $this->setRefreshCookie($sessionB);
        $this->withBearer($accessB)->post('api/v1/auth/logout')->assertStatus(200);

        $this->dontSeeInDatabase('token', ['token_hash' => hash('sha256', $sessionB)]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $sessionA), 'revoked' => 0]);

        // Sesi perangkat lain tetap bisa refresh.
        $this->clearAuthState();
        $this->setRefreshCookie($sessionA);
        $this->post('api/v1/auth/refresh')->assertStatus(200);
    }

    public function testLogoutRequiresAuthentication(): void
    {
        $this->post('api/v1/auth/logout')->assertStatus(401);
    }

    public function testMeReturnsCurrentUser(): void
    {
        $result = $this->asNip(self::NIP)->get('api/v1/auth/me');

        $result->assertStatus(200);
        $json = $this->json($result);
        $this->assertSame(self::NIP, $json['data']['user']['nip']);
        $this->assertSame(2, $json['data']['claims']['role']);
        $this->assertSame('S01', $json['data']['claims']['id_satker']);
        $this->assertArrayNotHasKey('password', $json['data']['user']);
    }
}
