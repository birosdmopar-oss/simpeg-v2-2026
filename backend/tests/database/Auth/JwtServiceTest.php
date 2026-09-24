<?php

declare(strict_types=1);

namespace Tests\Database\Auth;

use App\Constants\Role;
use App\Exceptions\AuthException;
use App\Libraries\Auth\JwtService;
use App\Models\Auth\TokenModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Jwt as JwtConfig;

/**
 * F0-06 — issue / verify / refresh token.
 *
 * @internal
 */
final class JwtServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private JwtService $jwt;

    private JwtConfig $config;

    /**
     * @var array<string, mixed>
     */
    private array $claims = ['sub' => '198501012010011001', 'role' => Role::ADMIN_SATKER, 'id_unit' => 'U01', 'id_satker' => 'S01'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config             = new JwtConfig();
        $this->config->secret     = str_repeat('s', 64);
        $this->config->accessTtl  = 3600;
        $this->config->refreshTtl = 604800;

        $this->jwt = new JwtService($this->config, new TokenModel($this->db));
    }

    public function testIssueAndVerifyAccessToken(): void
    {
        $token  = $this->jwt->issueAccessToken($this->claims);
        $claims = $this->jwt->verifyAccessToken($token);

        $this->assertSame('198501012010011001', $claims['sub']);
        $this->assertSame(Role::ADMIN_SATKER, $claims['role']);
        $this->assertSame('U01', $claims['id_unit']);
        $this->assertSame('S01', $claims['id_satker']);
        $this->assertSame($claims['iat'] + 3600, $claims['exp'], 'Access token harus berlaku tepat 1 jam');
    }

    public function testAccessTokenExpiredAfterOneHourIsRejected(): void
    {
        $issuedAt = time() - 3601; // diterbitkan 1 jam 1 detik lalu
        $this->jwt->setNow($issuedAt);
        $token = $this->jwt->issueAccessToken($this->claims);

        $this->jwt->setNow(null); // kembali ke waktu nyata

        try {
            $this->jwt->verifyAccessToken($token);
            $this->fail('Token expired seharusnya ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_EXPIRED, $e->getReason());
        }
    }

    public function testAccessTokenStillValidJustBeforeOneHour(): void
    {
        $this->jwt->setNow(time() - 3500);
        $token = $this->jwt->issueAccessToken($this->claims);
        $this->jwt->setNow(null);

        $this->assertSame('198501012010011001', $this->jwt->verifyAccessToken($token)['sub']);
    }

    public function testTamperedOrForeignTokenIsRejected(): void
    {
        $token = $this->jwt->issueAccessToken($this->claims);

        $other         = clone $this->config;
        $other->secret = str_repeat('x', 64);

        try {
            (new JwtService($other, new TokenModel($this->db)))->verifyAccessToken($token);
            $this->fail('Token dengan secret berbeda seharusnya ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_INVALID, $e->getReason());
        }

        $this->expectException(AuthException::class);
        $this->jwt->verifyAccessToken($token . 'x');
    }

    public function testRefreshTokenIsStoredAsHashNotPlaintext(): void
    {
        $refresh = $this->jwt->issueRefreshToken($this->claims);

        $this->assertSame(64, strlen($refresh['token']));
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $refresh['token']), 'nip' => '198501012010011001', 'revoked' => 0]);
        $this->dontSeeInDatabase('token', ['token_hash' => $refresh['token']]);

        $row = $this->db->table('token')->get()->getRowArray();
        $this->assertStringNotContainsString($refresh['token'], json_encode($row, JSON_THROW_ON_ERROR), 'Plaintext refresh token tidak boleh ada di DB');
        $this->assertSame(time() + 604800, $refresh['expires_at'], 'Refresh token harus berlaku 7 hari');
    }

    public function testRefreshRotatesTokensAndReusesClaims(): void
    {
        $pair = $this->jwt->issueTokenPair($this->claims);

        $new = $this->jwt->refresh($pair['refresh_token']);

        $this->assertNotSame($pair['refresh_token'], $new['refresh_token']);
        $this->assertNotSame($pair['access_token'], $new['access_token']);

        $claims = $this->jwt->verifyAccessToken($new['access_token']);
        $this->assertSame('198501012010011001', $claims['sub']);
        $this->assertSame(Role::ADMIN_SATKER, $claims['role']);

        // Token lama sudah revoked, token baru aktif.
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $pair['refresh_token']), 'revoked' => 1]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $new['refresh_token']), 'revoked' => 0]);
    }

    public function testReusedRefreshTokenIsRejected(): void
    {
        $pair = $this->jwt->issueTokenPair($this->claims);
        $this->jwt->refresh($pair['refresh_token']);

        try {
            $this->jwt->refresh($pair['refresh_token']);
            $this->fail('Refresh token yang sudah dipakai harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_REUSED, $e->getReason());
        }
    }

    /**
     * DEV-002 Bagian 8 #1 — race: satu refresh token dipakai 2x bersamaan.
     * Interleaving dibuat deterministik: request A sudah membaca row (revoked=0) ketika request B
     * menyelesaikan rotasi. A tidak boleh ikut menerbitkan token; A = reuse → seluruh sesi dicabut.
     */
    public function testConcurrentRefreshWithSameTokenIssuesOnlyOneSessionAndTriggersReuse(): void
    {
        $pair = $this->jwt->issueTokenPair($this->claims);

        $winner = null;
        $racing = new JwtService($this->config, new class ($this->db, function () use ($pair, &$winner): void {
            // Request B memakai token yang sama dan menang di antara SELECT dan UPDATE milik request A.
            $winner = $this->jwt->refresh($pair['refresh_token']);
        }) extends TokenModel {
            /** @var (callable(): void)|null */
            private $beforeReturn;

            public function __construct(\CodeIgniter\Database\ConnectionInterface $db, callable $beforeReturn)
            {
                parent::__construct($db);
                $this->beforeReturn = $beforeReturn;
            }

            public function findByHash(string $hash): ?array
            {
                $row = parent::findByHash($hash);

                if ($this->beforeReturn !== null) {
                    ($this->beforeReturn)();
                    $this->beforeReturn = null;
                }

                return $row;
            }
        });

        try {
            $racing->refresh($pair['refresh_token']);
            $this->fail('Request kedua dengan token yang sama harus ditolak sebagai reuse');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_REUSED, $e->getReason());
        }

        // Hanya B yang sempat menerbitkan token (1 row lama + 1 row baru), dan reuse mencabut sesi B juga.
        $this->assertNotNull($winner);
        $this->assertSame(2, $this->db->table('token')->countAllResults());
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $winner['refresh_token']), 'revoked' => 1]);
        $this->dontSeeInDatabase('token', ['nip' => '198501012010011001', 'revoked' => 0]);

        try {
            $this->jwt->refresh($winner['refresh_token']);
            $this->fail('Sesi hasil rotasi harus ikut dicabut setelah reuse terdeteksi');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_REUSED, $e->getReason());
        }
    }

    public function testTokenModelRevokeIsConditionalOnRevokedZero(): void
    {
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $model   = new TokenModel($this->db);
        $id      = (int) $model->findByHash(hash('sha256', $refresh['token']))['id'];

        $this->assertTrue($model->revoke($id, time()), 'Revoke pertama harus mengubah tepat 1 baris');
        $this->assertFalse($model->revoke($id, time()), 'Revoke kedua (token sudah revoked) harus affected rows = 0');
        $this->assertFalse($model->revoke($id + 999, time()), 'Id tidak dikenal harus affected rows = 0');
    }

    public function testRefreshTokenExpiredAfterSevenDaysIsRejected(): void
    {
        $this->jwt->setNow(time() - 604801); // diterbitkan 7 hari 1 detik lalu
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $this->jwt->setNow(null);

        try {
            $this->jwt->refresh($refresh['token']);
            $this->fail('Refresh token expired harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_EXPIRED, $e->getReason());
        }

        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $refresh['token']), 'revoked' => 1]);
    }

    public function testUnknownRefreshTokenIsRejected(): void
    {
        $this->expectException(AuthException::class);
        $this->jwt->refresh(bin2hex(random_bytes(32)));
    }

    public function testRevokeAllForNipInvalidatesEveryActiveToken(): void
    {
        $a = $this->jwt->issueRefreshToken($this->claims);
        $b = $this->jwt->issueRefreshToken($this->claims);

        $this->assertSame(2, $this->jwt->revokeAllForNip('198501012010011001'));

        $this->expectException(AuthException::class);
        $this->jwt->refresh($a['token']);
        $this->jwt->refresh($b['token']);
    }

    public function testCookiesAreHttpOnly(): void
    {
        $pair = $this->jwt->issueTokenPair($this->claims);

        $access  = $this->jwt->accessCookie($pair['access_token']);
        $refresh = $this->jwt->refreshCookie($pair['refresh_token']);

        $this->assertTrue($access->isHTTPOnly());
        $this->assertTrue($refresh->isHTTPOnly());
        $this->assertSame('access_token', $access->getName());
        $this->assertSame('refresh_token', $refresh->getName());
        $this->assertSame('/api/v1/auth', $refresh->getPath());
    }

    public function testRejectsWeakSecret(): void
    {
        $weak         = new JwtConfig();
        $weak->secret = 'short';

        $this->expectException(\InvalidArgumentException::class);
        new JwtService($weak, new TokenModel($this->db));
    }
}
