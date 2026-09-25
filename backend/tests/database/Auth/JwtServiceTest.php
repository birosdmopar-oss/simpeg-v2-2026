<?php

declare(strict_types=1);

namespace Tests\Database\Auth;

use App\Constants\Role;
use App\Exceptions\AuthException;
use App\Libraries\Auth\JwtService;
use App\Models\Auth\TokenModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Jwt as JwtConfig;
use Throwable;

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

    /**
     * Koneksi DB tambahan (request/proses lain) yang dibuka test; ditutup di tearDown.
     *
     * @var list<BaseConnection>
     */
    private array $otherConnections = [];

    /**
     * Koneksi yang menahan lock baris token (lihat lockTokenRow()).
     */
    private ?BaseConnection $lockHolder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config             = new JwtConfig();
        $this->config->secret     = str_repeat('s', 64);
        $this->config->accessTtl  = 3600;
        $this->config->refreshTtl = 604800;

        $this->jwt = new JwtService($this->config, new TokenModel($this->db));
    }

    protected function tearDown(): void
    {
        foreach ($this->otherConnections as $conn) {
            while ($conn->transDepth > 0) {
                $conn->transRollback();
            }

            $conn->close();
        }

        $this->otherConnections = [];
        $this->lockHolder       = null;

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function dbDebugModes(): iterable
    {
        yield 'DBDebug=true' => [true];

        yield 'DBDebug=false' => [false];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tokenWriteModes(): iterable
    {
        foreach (['revoke', 'revokeAllForNip', 'deleteAllForNip', 'deleteExpired'] as $method) {
            yield $method . ' DBDebug=true' => [$method, true];

            yield $method . ' DBDebug=false' => [$method, false];
        }
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
        // Waktu dibekukan agar assertion expires_at tidak goyah saat detik berganti di tengah test.
        $now = time();
        $this->jwt->setNow($now);
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $this->jwt->setNow(null);

        $this->assertSame(64, strlen($refresh['token']));
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $refresh['token']), 'nip' => '198501012010011001', 'revoked' => 0]);
        $this->dontSeeInDatabase('token', ['token_hash' => $refresh['token']]);

        $row = $this->db->table('token')->get()->getRowArray();
        $this->assertStringNotContainsString($refresh['token'], json_encode($row, JSON_THROW_ON_ERROR), 'Plaintext refresh token tidak boleh ada di DB');
        $this->assertSame($now + 604800, $refresh['expires_at'], 'Refresh token harus berlaku 7 hari');
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
     * A memakai waktu berbeda (+60 detik) agar UPDATE tanpa syarat revoked=0 benar-benar mengubah baris
     * (affected rows 1) — tanpa itu MySQL (foundRows=false) melaporkan 0 dan syarat revoked=0 tidak teruji.
     */
    public function testConcurrentRefreshWithSameTokenIssuesOnlyOneSessionAndTriggersReuse(): void
    {
        $pair = $this->jwt->issueTokenPair($this->claims);

        $winner = null;
        $racing = new JwtService($this->config, $this->modelWithHookAfterFind($this->db, function () use ($pair, &$winner): void {
            // Request B memakai token yang sama dan menang di antara SELECT dan UPDATE milik request A.
            $winner = $this->jwt->refresh($pair['refresh_token']);
        }));
        $racing->setNow(time() + 60);

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

    /**
     * DEV-002 Bagian 8 #1 — urutan "UPDATE pemenang → revokeAll pihak kalah → INSERT pemenang" tidak boleh terjadi.
     *
     * Pemenang (koneksi test) sudah lolos UPDATE bersyarat; tepat sebelum INSERT token barunya, request pihak kalah
     * berjalan di koneksi DB kedua (= proses lain). Karena revoke + INSERT satu transaksi, lock baris token lama masih
     * ditahan: pihak kalah tertahan di UPDATE (di test ini berakhir lock wait timeout 1 detik) dan TIDAK sempat
     * mencabut sesi sebelum token baru pemenang ada. Setelah pemenang commit, pihak kalah melanjutkan dengan baris
     * yang sudah ia baca → affected rows 0 → reuse → seluruh sesi, termasuk hasil rotasi pemenang, dicabut.
     */
    public function testRaceLoserCannotRevokeAllBetweenWinnerUpdateAndInsert(): void
    {
        $pair  = $this->jwt->issueTokenPair($this->claims);
        $other = $this->jwt->issueRefreshToken($this->claims); // sesi di perangkat lain

        $loserDb = $this->otherConnection();
        $loser   = new JwtService($this->config, new class ($loserDb) extends TokenModel {
            /** @var array<string, mixed>|null */
            private ?array $row = null;

            // Request pihak kalah membaca baris SEKALI; setelah lolos dari tunggu lock ia lanjut dengan baris itu.
            public function findByHash(string $hash): ?array
            {
                return $this->row ??= parent::findByHash($hash);
            }
        });

        $loserIssued       = false;
        $loserError        = null;
        $otherActiveInGap  = null;
        $inGapBeforeInsert = function () use ($loser, $loserDb, $pair, $other, &$loserIssued, &$loserError, &$otherActiveInGap): void {
            try {
                $loser->refresh($pair['refresh_token']);
                $loserIssued = true;
            } catch (Throwable $e) {
                $loserError = $e;
            }

            $otherActiveInGap = $loserDb->table('token')
                ->where('token_hash', hash('sha256', $other['token']))
                ->where('revoked', 0)
                ->countAllResults();
        };

        $winnerModel = new class ($this->db, $inGapBeforeInsert) extends TokenModel {
            /** @var (callable(): void)|null */
            private $hookBeforeInsert;

            public function __construct(ConnectionInterface $db, callable $hookBeforeInsert)
            {
                parent::__construct($db);
                $this->hookBeforeInsert = $hookBeforeInsert;
            }

            public function insert($row = null, bool $returnID = true)
            {
                if ($this->hookBeforeInsert !== null) {
                    $hook                   = $this->hookBeforeInsert;
                    $this->hookBeforeInsert = null;
                    $hook();
                }

                return parent::insert($row, $returnID);
            }
        };

        $winner = (new JwtService($this->config, $winnerModel))->refresh($pair['refresh_token']);

        $this->assertFalse($loserIssued, 'Pihak kalah tidak boleh ikut menerbitkan token');
        $this->assertInstanceOf(DatabaseException::class, $loserError, 'Pihak kalah harus tertahan lock baris token lama sampai token baru pemenang ter-commit, bukan langsung diperlakukan sebagai reuse');
        $this->assertSame(1205, $loserError->getCode(), 'Harus lock wait timeout (1205)');
        $this->assertSame(1, $otherActiveInGap, 'revokeAll pihak kalah tidak boleh jalan sebelum INSERT token baru pemenang');

        // Pemenang sudah commit → lock dilepas; pihak kalah melanjutkan UPDATE-nya → affected rows 0 → reuse.
        try {
            $loser->refresh($pair['refresh_token']);
            $this->fail('Pihak kalah harus ditolak sebagai reuse setelah lock dilepas');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_REUSED, $e->getReason());
        }

        $this->dontSeeInDatabase('token', ['nip' => '198501012010011001', 'revoked' => 0]);

        try {
            $this->jwt->refresh($winner['refresh_token']);
            $this->fail('Sesi hasil rotasi pemenang harus ikut dicabut setelah reuse terdeteksi');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_REUSED, $e->getReason());
        }
    }

    /**
     * Logout (hapus fisik row) yang jatuh di antara SELECT dan UPDATE milik refresh = token tidak dikenal, sama seperti
     * jalur berurutan (logout lalu refresh) — bukan reuse, sehingga sesi di perangkat lain tidak ikut dicabut.
     */
    public function testRefreshRacingLogoutIsUnknownTokenAndKeepsOtherSessions(): void
    {
        $pair  = $this->jwt->issueTokenPair($this->claims);
        $other = $this->jwt->issueRefreshToken($this->claims); // sesi di perangkat lain

        $racing = new JwtService($this->config, $this->modelWithHookAfterFind($this->db, function () use ($pair): void {
            $this->assertTrue($this->jwt->deleteRefreshToken($pair['refresh_token']), 'Logout harus menghapus row token');
        }));

        try {
            $racing->refresh($pair['refresh_token']);
            $this->fail('Refresh dengan token yang dihapus logout harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_NOT_FOUND, $e->getReason());
        }

        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $other['token']), 'revoked' => 0]);
        $this->assertSame(1, $this->db->table('token')->countAllResults(), 'Tidak boleh ada token baru yang terbit');
    }

    /**
     * T-01 — pencabutan massal (mis. reset password) lalu login ulang di perangkat lain, keduanya jatuh di antara
     * SELECT dan UPDATE milik refresh token lama: baris token lama sudah dihapus → unknownToken, bukan reuse, dan sesi
     * baru hasil login ulang tidak ikut dicabut.
     */
    public function testRefreshRacingMassRevocationIsUnknownTokenAndKeepsNewSession(): void
    {
        $pair  = $this->jwt->issueTokenPair($this->claims);
        $fresh = null;

        $racing = new JwtService($this->config, $this->modelWithHookAfterFind($this->db, function () use (&$fresh): void {
            $this->jwt->revokeAllForNip('198501012010011001');
            $fresh = $this->jwt->issueRefreshToken($this->claims);
        }));

        try {
            $racing->refresh($pair['refresh_token']);
            $this->fail('Refresh dengan token yang sudah dicabut massal harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_NOT_FOUND, $e->getReason(), 'Token yang dicabut massal harus "tidak dikenal", bukan reuse');
        }

        $this->assertNotNull($fresh);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $fresh['token']), 'revoked' => 0]);
        $this->assertSame(1, $this->db->table('token')->countAllResults(), 'Tidak boleh ada token baru yang terbit dari token lama');
    }

    /**
     * Error DB saat revoke (di sini lock wait timeout karena proses lain menahan lock baris) harus menjadi error DB:
     * transaksi di-rollback, token lama tetap berlaku — BUKAN dibaca "kalah race" lalu dianggap reuse (cabut semua sesi,
     * 401). Di dalam transaksi CI4 query gagal hanya mengembalikan false apa pun DBDebug-nya; diuji di kedua mode.
     *
     * @dataProvider dbDebugModes
     */
    public function testDatabaseErrorDuringRevokeIsNotTreatedAsReuse(bool $dbDebug): void
    {
        $pair  = $this->jwt->issueTokenPair($this->claims);
        $other = $this->jwt->issueRefreshToken($this->claims);
        $this->lockTokenRow($pair['refresh_token']);

        $db    = $this->otherConnection($dbDebug);
        $model = new class ($db) extends TokenModel {
            public int $revokeAllCalls = 0;

            public function revokeAllForNip(string $nip, int $now): int
            {
                $this->revokeAllCalls++;

                return parent::revokeAllForNip($nip, $now);
            }
        };
        $jwt = new JwtService($this->config, $model);

        try {
            $jwt->refresh($pair['refresh_token']);
            $this->fail('Error DB saat revoke harus dilempar');
        } catch (DatabaseException $e) {
            $this->assertSame(1205, $e->getCode(), 'Harus lock wait timeout (1205)');
        } finally {
            $this->releaseTokenLocks();
        }

        $this->assertSame(0, $model->revokeAllCalls, 'Error DB tidak boleh diperlakukan sebagai reuse (cabut semua sesi)');
        $this->assertSame(0, $db->transDepth, 'Transaksi rotasi harus ditutup (rollback)');
        $this->assertTrue($db->transStatus(), 'transStatus koneksi harus di-reset setelah rollback');
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $pair['refresh_token']), 'revoked' => 0]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $other['token']), 'revoked' => 0]);

        // Lock sudah dilepas → token yang sama masih bisa dipakai (sesi tidak hilang karena error DB sesaat).
        $new = $jwt->refresh($pair['refresh_token']);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $new['refresh_token']), 'revoked' => 0]);
    }

    /**
     * INSERT token baru gagal di dalam transaksi rotasi (di sini pelanggaran UNIQUE token_hash, 1062). CI4 tidak
     * melempar exception di dalam transaksi dan transCommit() tidak memeriksa transStatus, jadi tanpa cek eksplisit
     * revoke token lama ikut ter-commit dan klien menerima refresh token yang tidak ada di DB (sesi hilang).
     *
     * @dataProvider dbDebugModes
     */
    public function testFailedInsertDuringRotationRollsBackRevoke(bool $dbDebug): void
    {
        $pair    = $this->jwt->issueTokenPair($this->claims);
        $oldHash = hash('sha256', $pair['refresh_token']);

        $db  = $this->otherConnection($dbDebug);
        $jwt = new JwtService($this->config, new class ($db, $oldHash) extends TokenModel {
            public function __construct(ConnectionInterface $db, private string $duplicateHash)
            {
                parent::__construct($db);
            }

            public function insert($row = null, bool $returnID = true)
            {
                if (is_array($row)) {
                    $row['token_hash'] = $this->duplicateHash;
                }

                return parent::insert($row, $returnID);
            }
        });

        try {
            $jwt->refresh($pair['refresh_token']);
            $this->fail('INSERT token baru yang gagal harus menggagalkan rotasi');
        } catch (DatabaseException) {
            // diharapkan
        }

        $this->assertSame(0, $db->transDepth, 'Transaksi rotasi harus ditutup (rollback)');
        $this->assertTrue($db->transStatus(), 'transStatus koneksi harus di-reset setelah rollback');
        $this->seeInDatabase('token', ['token_hash' => $oldHash, 'revoked' => 0]);
        $this->assertSame(1, $this->db->table('token')->countAllResults());

        // Token lama tetap berlaku.
        $new = $this->jwt->refresh($pair['refresh_token']);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $new['refresh_token']), 'revoked' => 0]);
    }

    /**
     * Status gagal sisa transaksi lain (strict mode: transStatus tetap false sampai di-reset) di koneksi bersama tidak
     * boleh membuat rotasi yang sehat ikut dianggap gagal.
     */
    public function testStaleFailedTransStatusOnSharedConnectionDoesNotBreakRefresh(): void
    {
        $pair = $this->jwt->issueTokenPair($this->claims);

        $this->db->transBegin();
        $this->assertFalse($this->db->query('SELECT 1 FROM tabel_yang_tidak_ada'));
        $this->db->transRollback();
        $this->assertFalse($this->db->transStatus(), 'Prasyarat: transStatus koneksi bersama tertinggal false');

        $new = $this->jwt->refresh($pair['refresh_token']);

        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $new['refresh_token']), 'revoked' => 0]);
        $this->assertTrue($this->db->transStatus());
    }

    public function testTokenModelRevokeIsConditionalOnRevokedZero(): void
    {
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $model   = new TokenModel($this->db);
        $id      = (int) $model->findByHash(hash('sha256', $refresh['token']))['id'];
        $now     = time();

        $this->assertTrue($model->revoke($id, $now), 'Revoke pertama harus mengubah tepat 1 baris');
        // Timestamp berbeda: tanpa syarat revoked=0 UPDATE ini benar-benar mengubah revoked_at (affected rows 1).
        $this->assertFalse($model->revoke($id, $now + 60), 'Revoke kedua (token sudah revoked) harus affected rows = 0');
        $this->seeInDatabase('token', ['id' => $id, 'revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s', $now)]);
        $this->assertFalse($model->revoke($id + 999, $now + 60), 'Id tidak dikenal harus affected rows = 0');
    }

    /**
     * Query tulis TokenModel yang gagal harus dilempar sebagai DatabaseException di kedua mode DBDebug — dengan
     * DBDebug=false CI4 hanya mengembalikan false (affected rows -1) yang kalau diabaikan terbaca "0 baris".
     *
     * @dataProvider tokenWriteModes
     */
    public function testTokenModelWriteErrorIsThrownNotReportedAsZeroRows(string $method, bool $dbDebug): void
    {
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $id      = $this->lockTokenRow($refresh['token']);
        $model   = new TokenModel($this->otherConnection($dbDebug));

        try {
            if ($method === 'revoke') {
                $model->revoke($id, time());
            } elseif ($method === 'revokeAllForNip') {
                $model->revokeAllForNip('198501012010011001', time());
            } elseif ($method === 'deleteExpired') {
                $model->deleteExpired($id, time() + $this->config->refreshTtl + 60);
            } else {
                $model->deleteAllForNip('198501012010011001');
            }

            $this->fail($method . '() harus melempar DatabaseException saat query gagal');
        } catch (DatabaseException $e) {
            $this->assertSame(1205, $e->getCode(), 'Harus lock wait timeout (1205)');
        } finally {
            $this->releaseTokenLocks();
        }

        $this->seeInDatabase('token', ['id' => $id, 'revoked' => 0]);
    }

    /**
     * Token kedaluwarsa ditolak lalu barisnya DIHAPUS (bukan revoked=1). Dikirim lagi (retry klien body, tab paralel,
     * jam klien tertinggal) → unknownToken, bukan reuse yang mencabut sesi lain milik nip yang sama (T-01).
     */
    public function testRefreshTokenExpiredAfterSevenDaysIsRejected(): void
    {
        $this->jwt->setNow(time() - 604801); // diterbitkan 7 hari 1 detik lalu
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $this->jwt->setNow(null);
        $other = $this->jwt->issueRefreshToken($this->claims); // sesi baru di perangkat lain

        try {
            $this->jwt->refresh($refresh['token']);
            $this->fail('Refresh token expired harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_EXPIRED, $e->getReason());
        }

        $this->dontSeeInDatabase('token', ['token_hash' => hash('sha256', $refresh['token'])]);

        try {
            $this->jwt->refresh($refresh['token']);
            $this->fail('Refresh token expired yang dikirim lagi harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_NOT_FOUND, $e->getReason(), 'Token expired yang dikirim lagi harus "tidak dikenal", bukan reuse');
        }

        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $other['token']), 'revoked' => 0]);
        $new = $this->jwt->refresh($other['token']);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $new['refresh_token']), 'revoked' => 0]);
    }

    /**
     * Request lain merotasi token (masih berlaku menurut jamnya) di antara SELECT dan DELETE milik request yang sudah
     * melihatnya kedaluwarsa: baris hasil rotasi (revoked=1) TIDAK ikut terhapus, sehingga pemakaian ulangnya tetap
     * terbaca reuse; sesi hasil rotasi tetap hidup.
     */
    public function testExpiredRefreshRacingRotationKeepsRotatedRowForReuseDetection(): void
    {
        $refresh = $this->jwt->issueRefreshToken($this->claims);
        $rotated = null;

        $racing = new JwtService($this->config, $this->modelWithHookAfterFind($this->db, function () use ($refresh, &$rotated): void {
            $rotated = $this->jwt->refresh($refresh['token']);
        }));
        $racing->setNow($refresh['expires_at'] + 1); // jam request ini sudah lewat masa berlaku token

        try {
            $racing->refresh($refresh['token']);
            $this->fail('Refresh token expired harus ditolak');
        } catch (AuthException $e) {
            $this->assertSame(AuthException::REASON_EXPIRED, $e->getReason());
        }

        $this->assertNotNull($rotated);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $refresh['token']), 'revoked' => 1]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $rotated['refresh_token']), 'revoked' => 0]);
    }

    public function testTokenModelDeleteExpiredOnlyDeletesExpiredUnrotatedRow(): void
    {
        $model   = new TokenModel($this->db);
        $now     = time();
        $expired = $now + $this->config->refreshTtl + 60; // jam "sekarang" setelah token kedaluwarsa

        $active = $this->jwt->issueRefreshToken($this->claims);
        $id     = (int) $model->findByHash(hash('sha256', $active['token']))['id'];
        $this->assertFalse($model->deleteExpired($id, $now), 'Token yang masih berlaku tidak boleh dihapus');
        $this->seeInDatabase('token', ['id' => $id]);

        $rotated   = $this->jwt->issueRefreshToken($this->claims);
        $rotatedId = (int) $model->findByHash(hash('sha256', $rotated['token']))['id'];
        $this->assertTrue($model->revoke($rotatedId, $now));
        $this->assertFalse($model->deleteExpired($rotatedId, $expired), 'Token yang sudah dirotasi (revoked=1) tidak boleh dihapus');
        $this->seeInDatabase('token', ['id' => $rotatedId, 'revoked' => 1]);

        $this->assertTrue($model->deleteExpired($id, $expired), 'Token kedaluwarsa yang belum dirotasi harus terhapus');
        $this->dontSeeInDatabase('token', ['id' => $id]);
        $this->assertFalse($model->deleteExpired($id, $expired), 'Baris yang sudah terhapus = affected rows 0');
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

    /**
     * T-01 (QAFUNC-002-R1 24-09) — pencabutan massal (ganti/reset password, perubahan/hapus akun oleh admin) MENGHAPUS
     * seluruh baris token nip tsb, termasuk token yang sudah dirotasi (revoked=1). Token lama di perangkat lain →
     * unknownToken, bukan reuse, sehingga sesi yang terbit sesudahnya (login ulang) tidak ikut dicabut.
     */
    public function testRevokeAllForNipDeletesEveryTokenSoStaleTokensAreUnknownNotReuse(): void
    {
        $rotated = $this->jwt->issueTokenPair($this->claims);
        $current = $this->jwt->refresh($rotated['refresh_token']); // token lama revoked=1, token baru aktif
        $other   = $this->jwt->issueRefreshToken($this->claims);  // perangkat lain
        $foreign = $this->jwt->issueRefreshToken(['sub' => '199002152015022002', 'role' => Role::PEGAWAI]);

        $this->assertSame(3, $this->jwt->revokeAllForNip('198501012010011001'));
        $this->dontSeeInDatabase('token', ['nip' => '198501012010011001']);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $foreign['token']), 'revoked' => 0]);

        $fresh = $this->jwt->issueRefreshToken($this->claims); // login ulang di perangkat B

        foreach ([$rotated['refresh_token'], $current['refresh_token'], $other['token']] as $stale) {
            try {
                $this->jwt->refresh($stale);
                $this->fail('Token yang dicabut massal harus ditolak');
            } catch (AuthException $e) {
                $this->assertSame(AuthException::REASON_NOT_FOUND, $e->getReason(), 'Token yang dicabut massal harus "tidak dikenal", bukan reuse');
            }
        }

        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $fresh['token']), 'revoked' => 0]);
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $foreign['token']), 'revoked' => 0]);
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

    /**
     * Koneksi DB kedua (non-shared) ke database test = request/proses lain; lock wait dipersingkat jadi 1 detik.
     */
    private function otherConnection(bool $dbDebug = true): BaseConnection
    {
        $group            = config(Database::class)->tests;
        $group['DBDebug'] = $dbDebug;

        $db = Database::connect($group, false);
        $db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->otherConnections[] = $db;

        return $db;
    }

    /**
     * Proses lain menahan X-lock baris token (SELECT ... FOR UPDATE dalam transaksi yang dibiarkan terbuka).
     *
     * @return int id baris token
     */
    private function lockTokenRow(string $plainToken): int
    {
        $id = (int) (new TokenModel($this->db))->findByHash(hash('sha256', $plainToken))['id'];

        $this->lockHolder ??= $this->otherConnection();
        $this->lockHolder->transBegin();
        $this->lockHolder->query('SELECT id FROM ' . $this->lockHolder->prefixTable('token') . ' WHERE id = ? FOR UPDATE', [$id]);

        return $id;
    }

    private function releaseTokenLocks(): void
    {
        while ($this->lockHolder !== null && $this->lockHolder->transDepth > 0) {
            $this->lockHolder->transRollback();
        }
    }

    /**
     * TokenModel yang menjalankan $hook SEKALI tepat setelah findByHash() membaca baris (= di antara SELECT dan UPDATE).
     */
    private function modelWithHookAfterFind(ConnectionInterface $db, callable $hook): TokenModel
    {
        return new class ($db, $hook) extends TokenModel {
            /** @var (callable(): void)|null */
            private $hookAfterFind;

            public function __construct(ConnectionInterface $db, callable $hookAfterFind)
            {
                parent::__construct($db);
                $this->hookAfterFind = $hookAfterFind;
            }

            public function findByHash(string $hash): ?array
            {
                $row = parent::findByHash($hash);

                if ($this->hookAfterFind !== null) {
                    $hook                = $this->hookAfterFind;
                    $this->hookAfterFind = null;
                    $hook();
                }

                return $row;
            }
        };
    }
}
