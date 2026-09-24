<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Exceptions\ValidationException;
use App\Libraries\ApiExceptionHandler;
use App\Libraries\Auth\JwtService;
use App\Libraries\Auth\PasswordService;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Auth\ResetPasswordService;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use App\Models\Auth\TokenModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use Config\Database;
use Config\Jwt as JwtConfig;
use InvalidArgumentException;
use mysqli;
use mysqli_result;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\CommitFailing\Connection as CommitFailingConnection;
use Tests\Support\Database\Seeds\AuthSeeder;
use Throwable;

/**
 * DEV-002 Bagian 8 #2 (CR-005) — transaksi ResetPasswordService::reset() terhadap kegagalan SQL NYATA, bukan exception
 * PHP: lock wait timeout lewat koneksi kedua, trigger SIGNAL, deadlock, commit gagal, dan dua reset paralel (proses
 * PHP terpisah) dengan token berbeda milik user yang sama.
 *
 * Di CI4 4.7 query yang gagal di dalam transaksi hanya mengembalikan false + transStatus false (DBDebug true maupun
 * false), sehingga skenario lock/trigger dijalankan di kedua mode DBDebug.
 *
 * @internal
 */
final class ResetPasswordTransactionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP     = '199002152015022002';
    private const NEW     = 'PasswordReset789';
    private const TRIGGER = 'cr005_simulasi_gagal';

    private AuthConfig $config;

    /**
     * @var list<BaseConnection> koneksi tambahan; transaksi yang tertinggal di-rollback dan koneksi ditutup di tearDown
     */
    private array $connections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        $this->config                             = config(AuthConfig::class);
        $this->config->exposeResetTokenInResponse = true;
        $this->config->forgotMaxPerWindow         = 5;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $conn) {
            try {
                if ($conn->transDepth > 0) {
                    $conn->transRollback();
                }
            } catch (Throwable) {
                // koneksi korban deadlock/putus: close() di bawah tetap melepas lock
            }

            $conn->close();
        }

        $this->connections = [];
        $this->db->query('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        $this->clearAuthState();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function lockedStepProvider(): array
    {
        $cases = [];

        foreach (['DBDebug=true' => true, 'DBDebug=false' => false] as $mode => $dbDebug) {
            $cases["lock baris pengguna, {$mode}"]             = ['pengguna', $dbDebug];
            $cases["klaim token (markUsed), {$mode}"]          = ['token_reset', $dbDebug];
            $cases["batalkan token reset lain, {$mode}"]       = ['token_reset_lain', $dbDebug];
            $cases["cabut refresh token (revokeAll), {$mode}"] = ['refresh_token', $dbDebug];
        }

        return $cases;
    }

    /**
     * Setiap langkah tulis bisa kena lock wait timeout (baris dikunci transaksi lain, mis. admin/login/refresh yang
     * sedang berjalan). Reset harus gagal 500 dan TIDAK ada yang ter-commit: token reset belum terpakai (user bisa
     * mencoba lagi), password tidak berubah, token lain tetap berlaku, refresh token tidak dicabut, tanpa audit.
     */
    #[DataProvider('lockedStepProvider')]
    public function testLockWaitTimeoutInsideResetRollsBackEverything(string $lockedRow, bool $dbDebug): void
    {
        $conn    = $this->connection($dbDebug);
        $service = $this->serviceOn($conn);
        $token   = (string) $service->request(self::NIP, null)['token'];
        $other   = (string) $service->request(self::NIP, null)['token'];
        $this->issueTokensFor(self::NIP);
        $before = $this->state($token);

        [$sql, $binds] = match ($lockedRow) {
            'pengguna'         => ['SELECT id_pengguna FROM ' . $this->table('pengguna') . ' WHERE nip = ? FOR UPDATE', [self::NIP]],
            'token_reset'      => ['SELECT id FROM ' . $this->table('forgot_attempts') . ' WHERE token_hash = ? FOR UPDATE', [hash('sha256', $token)]],
            'token_reset_lain' => ['SELECT id FROM ' . $this->table('forgot_attempts') . ' WHERE token_hash = ? FOR UPDATE', [hash('sha256', $other)]],
            'refresh_token'    => ['SELECT id FROM ' . $this->table('token') . ' WHERE nip = ? FOR UPDATE', [self::NIP]],
            default            => throw new InvalidArgumentException('Target lock tidak dikenal: ' . $lockedRow),
        };
        $holder = $this->holdLock($sql, $binds);

        try {
            $this->assertResetFailsWithServerError($service, $token);
        } finally {
            $holder->transRollback();
        }

        $this->assertSame($before, $this->state($token));
        $this->assertCleanTransaction($conn);

        // Lock dilepas → token yang sama masih bisa dipakai.
        $this->assertResetSucceeds($service, $token);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function failingStatementProvider(): array
    {
        return [
            'UPDATE pengguna gagal, DBDebug=true'    => ['BEFORE UPDATE ON pengguna', true],
            'UPDATE pengguna gagal, DBDebug=false'   => ['BEFORE UPDATE ON pengguna', false],
            'INSERT audit_logs gagal, DBDebug=true'  => ['BEFORE INSERT ON audit_logs', true],
            'INSERT audit_logs gagal, DBDebug=false' => ['BEFORE INSERT ON audit_logs', false],
        ];
    }

    /**
     * Statement yang gagal karena error SQL (trigger SIGNAL). UPDATE pengguna yang gagal mengembalikan false dari
     * withActor(); INSERT audit_logs yang gagal ditelan writeAudit() (fail-open) dan hanya terlihat dari transStatus —
     * keduanya harus membatalkan reset (tidak ada password yang "berhasil" direset tanpa tersimpan/teraudit).
     */
    #[DataProvider('failingStatementProvider')]
    public function testFailingStatementInsideResetRollsBackEverything(string $timing, bool $dbDebug): void
    {
        $conn    = $this->connection($dbDebug);
        $service = $this->serviceOn($conn);
        $token   = (string) $service->request(self::NIP, null)['token'];
        $service->request(self::NIP, null);
        $this->issueTokensFor(self::NIP);
        $before = $this->state($token);

        [$when, $table] = explode(' ON ', $timing);
        $this->db->query(sprintf(
            "CREATE TRIGGER %s %s ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulasi gagal (test CR-005)'",
            self::TRIGGER,
            $when,
            $this->table($table),
        ));

        $this->assertResetFailsWithServerError($service, $token);

        $this->assertSame($before, $this->state($token));
        $this->assertCleanTransaction($conn);

        $this->db->query('DROP TRIGGER ' . self::TRIGGER);
        $this->assertResetSucceeds($service, $token);
    }

    /**
     * Deadlock membuat InnoDB me-rollback SELURUH transaksi korban; statement berikutnya di koneksi itu berjalan
     * autocommit. Skenario: transaksi pesaing (dibuat lebih "berat" agar reset yang jadi korban) memegang next-key lock
     * di ujung audit_logs lalu meminta lock baris pengguna yang dipegang reset; INSERT audit milik reset → deadlock.
     * UPDATE pengguna sendiri sukses (true) — kegagalan hanya terlihat dari transStatus. Reset harus berhenti di situ:
     * token lain dan refresh token tidak boleh ikut dibatalkan/dicabut di luar transaksi.
     */
    public function testDeadlockVictimDoesNotRunRemainingStepsOutsideTransaction(): void
    {
        $conn  = $this->connection(true);
        $token = (string) $this->serviceOn($conn)->request(self::NIP, null)['token'];
        $this->serviceOn($conn)->request(self::NIP, null);
        $this->issueTokensFor(self::NIP);
        $before = $this->state($token);
        $userId = (int) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['id_pengguna'];

        $holder = $this->connection(true);
        $holder->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $holder->transBegin();
        $holder->table('login_attempts')->insertBatch(array_fill(0, 50, [
            'username' => 'cr005-beban', 'ip_address' => null, 'success' => 0, 'attempted_at' => date('Y-m-d H:i:s'),
        ]));
        $maxLog = (int) $holder->query('SELECT COALESCE(MAX(id_log), 0) AS m FROM ' . $this->table('audit_logs'))->getRow()->m;
        $holder->query('SELECT id_log FROM ' . $this->table('audit_logs') . ' WHERE id_log > ? FOR UPDATE', [$maxLog]);

        $mysqli = $holder->connID;
        $this->assertInstanceOf(mysqli::class, $mysqli);

        $afterClaim = function () use ($mysqli, $userId): void {
            // Pesaing meminta lock baris pengguna yang sudah dipegang reset → menunggu (async).
            $mysqli->query('SELECT id_pengguna FROM ' . $this->table('pengguna') . ' WHERE id_pengguna = ' . $userId . ' FOR UPDATE', MYSQLI_ASYNC);
            $this->assertTrue(
                $this->waitUntil(fn (): bool => $this->lockWaitCount('trx_mysql_thread_id = ' . (int) $mysqli->thread_id) === 1),
                'Transaksi pesaing tidak kunjung menunggu lock baris pengguna',
            );
        };

        try {
            $this->assertResetFailsWithServerError($this->serviceOn($conn, $this->attemptsWithHook($conn, $afterClaim)), $token);
        } finally {
            $this->reapAsync($mysqli);
            $holder->transRollback();
        }

        $this->assertSame($before, $this->state($token));
        $this->assertCleanTransaction($conn);
    }

    /**
     * transCommit() yang gagal (mysqli commit() = false) tidak boleh dianggap sukses: rollback, 500, token belum
     * terpakai. Kegagalan COMMIT disimulasikan di driver karena tidak bisa dipicu dengan SQL secara deterministik.
     */
    public function testFailedCommitIsReportedAndRolledBack(): void
    {
        $conn                = new CommitFailingConnection(config(Database::class)->tests);
        $this->connections[] = $conn;

        $service = $this->serviceOn($conn);
        $token   = (string) $service->request(self::NIP, null)['token'];
        $this->issueTokensFor(self::NIP);
        $before = $this->state($token);

        $e = $this->assertResetFailsWithServerError($service, $token);
        $this->assertSame('Reset password gagal disimpan.', $e->getMessage());

        $this->assertSame($before, $this->state($token));
        $this->assertCleanTransaction($conn);
    }

    /**
     * DEV-002 Bagian 8 #2 — dua reset BENAR-BENAR paralel (dua proses PHP, dua koneksi) dengan dua token berbeda milik
     * user yang sama. Kedua proses dipastikan sudah berada di dalam transaksi dan menunggu lock (baris pengguna
     * ditahan koneksi ketiga) sebelum dilepas bersamaan. Hasil wajib: satu 200, satu 422 "tidak berlaku lagi", tanpa
     * deadlock (500) dan password akhir milik pemenang. Tanpa lock baris pengguna di awal transaksi, keduanya saling
     * mengunci baris forgot_attempts dengan urutan berlawanan → deadlock.
     */
    public function testParallelResetsWithDifferentTokensOfSameUserOneWinsWithoutDeadlock(): void
    {
        $passwords = ['A' => 'PasswordDariA123', 'B' => 'PasswordDariB456'];
        $tokens    = [];

        foreach (array_keys($passwords) as $name) {
            $tokens[$name] = (string) $this->serviceOn($this->db)->request(self::NIP, null)['token'];
        }

        $holder  = $this->holdLock('SELECT id_pengguna FROM ' . $this->table('pengguna') . ' WHERE nip = ? FOR UPDATE', [self::NIP]);
        $workers = [];

        try {
            foreach ($passwords as $name => $password) {
                $workers[$name] = $this->startWorker($tokens[$name], $password);
            }

            $bothWaiting = $this->waitUntil(
                fn (): bool => $this->lockWaitCount('p.DB = ' . $this->db->escape($this->db->getDatabase())) >= 2,
                30.0,
            );
        } finally {
            $holder->transRollback();
        }

        $results = [];

        foreach ($workers as $name => $worker) {
            $results[$name] = $this->finishWorker($worker);
        }

        $this->assertTrue($bothWaiting, 'Kedua worker harus sudah menunggu lock sebelum dilepas; hasil: ' . json_encode($results));

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses, 'Hasil worker: ' . json_encode($results));

        $winner = $results['A']['status'] === 200 ? 'A' : 'B';
        $loser  = $winner === 'A' ? 'B' : 'A';
        $this->assertSame(['token' => ['Token reset sudah tidak berlaku lagi.']], $results[$loser]['errors']);

        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertTrue(password_verify($passwords[$winner], (string) $row['password']));
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'update')->countAllResults());

        $attempt = fn (string $name): array => (array) $this->db->table('forgot_attempts')->where('token_hash', hash('sha256', $tokens[$name]))->get()->getRowArray();
        $this->assertFalse(ForgotAttemptModel::isInvalidated($attempt($winner)));
        $this->assertTrue(ForgotAttemptModel::isInvalidated($attempt($loser)));
    }

    /**
     * PenggunaModel::lockForUpdate(): true untuk baris yang ada (dan benar-benar dikunci sampai transaksi selesai),
     * false untuk baris yang tidak ada, DatabaseException bila query gagal (bukan false).
     */
    public function testLockForUpdateLocksExistingRowAndReportsMissingRow(): void
    {
        $userId   = (int) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['id_pengguna'];
        $conn     = $this->connection(true);
        $pengguna = new PenggunaModel($conn);

        $conn->transBegin();
        $this->assertTrue($pengguna->lockForUpdate($userId));
        $this->assertFalse($pengguna->lockForUpdate($userId + 100_000));

        // Koneksi lain tidak bisa mengunci baris yang sama selama transaksi pertama belum selesai.
        $otherConn = $this->connection(false);
        $otherConn->transBegin();

        try {
            (new PenggunaModel($otherConn))->lockForUpdate($userId);
            $this->fail('Baris yang sedang dikunci harus membuat lockForUpdate() gagal (lock wait timeout)');
        } catch (DatabaseException $e) {
            $this->assertSame('Gagal mengunci baris pengguna.', $e->getMessage());
        } finally {
            $otherConn->transRollback();
            $conn->transRollback();
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function connection(bool $dbDebug): BaseConnection
    {
        /** @var BaseConnection $conn */
        $conn = Database::connect(array_merge(config(Database::class)->tests, ['DBDebug' => $dbDebug]), false);
        $conn->initialize();
        $conn->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->connections[] = $conn;

        $this->assertSame($dbDebug, $conn->DBDebug);

        return $conn;
    }

    /**
     * Koneksi lain yang memegang lock (transaksi terbuka) sampai transRollback() dipanggil.
     *
     * @param list<mixed> $binds
     */
    private function holdLock(string $sql, array $binds): BaseConnection
    {
        $holder = $this->connection(true);
        $holder->transBegin();
        $this->assertNotEmpty($holder->query($sql, $binds)->getResultArray(), 'Baris yang dikunci harus ada');

        return $holder;
    }

    private function serviceOn(BaseConnection $conn, ?ForgotAttemptModel $attempts = null): ResetPasswordService
    {
        $pengguna = new PenggunaModel($conn);
        $verifier = new PasswordVerifier($pengguna, $this->config);
        $jwt      = new JwtService(config(JwtConfig::class), new TokenModel($conn));

        return new ResetPasswordService(
            $pengguna,
            $attempts ?? new ForgotAttemptModel($conn),
            $verifier,
            new PasswordService($pengguna, $verifier, $jwt),
            $jwt,
            $this->config,
            $conn,
        );
    }

    /**
     * ForgotAttemptModel yang menjalankan $afterClaim sekali, tepat setelah markUsed() (di dalam transaksi reset).
     */
    private function attemptsWithHook(BaseConnection $conn, Closure $afterClaim): ForgotAttemptModel
    {
        return new class ($conn, $afterClaim) extends ForgotAttemptModel {
            private ?Closure $afterClaim;

            public function __construct(ConnectionInterface $db, Closure $afterClaim)
            {
                parent::__construct($db);
                $this->afterClaim = $afterClaim;
            }

            public function markUsed(int $id, int $now): bool
            {
                $claimed = parent::markUsed($id, $now);

                if ($this->afterClaim !== null) {
                    $hook             = $this->afterClaim;
                    $this->afterClaim = null;
                    $hook();
                }

                return $claimed;
            }
        };
    }

    private function assertResetFailsWithServerError(ResetPasswordService $service, string $token): DatabaseException
    {
        try {
            $service->reset($token, self::NEW, self::NEW);
        } catch (ValidationException $e) {
            $this->fail('Error database tidak boleh dilaporkan sebagai 422: ' . json_encode($e->getErrors()));
        } catch (DatabaseException $e) {
            $this->assertSame(500, ApiExceptionHandler::toEnvelope($e)[0]);

            return $e;
        }

        $this->fail('Reset harus gagal (500) saat query di dalam transaksi gagal, bukan dianggap sukses');
    }

    private function assertResetSucceeds(ResetPasswordService $service, string $token): void
    {
        $service->reset($token, self::NEW, self::NEW);

        $state = $this->state($token);
        $this->assertNotNull($state['token_used_at']);
        $this->assertTrue(password_verify(self::NEW, (string) $state['password']));
        $this->assertSame(0, $state['reset_tokens_unused']);
        $this->assertSame(0, $state['refresh_tokens_active']);
        $this->assertSame(1, $state['audit_updates']);
    }

    private function assertCleanTransaction(BaseConnection $conn): void
    {
        $this->assertSame(0, $conn->transDepth, 'Transaksi harus sudah ditutup');
        $this->assertTrue($conn->transStatus(), 'transStatus harus di-reset agar transaksi berikutnya tidak ikut gagal');
    }

    /**
     * Snapshot data yang disentuh reset (dibaca lewat koneksi utama test = hanya data yang sudah ter-commit).
     *
     * @return array<string, mixed>
     */
    private function state(string $token): array
    {
        $user    = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $attempt = $this->db->table('forgot_attempts')->where('token_hash', hash('sha256', $token))->get()->getRowArray();

        return [
            'password'              => $user['password'],
            'password_legacy'       => $user['password_legacy'],
            'password_changed_at'   => $user['password_changed_at'],
            'token_used_at'         => $attempt['used_at'],
            'token_expires_at'      => $attempt['expires_at'],
            'reset_tokens_unused'   => $this->db->table('forgot_attempts')->where('username', self::NIP)->where('token_hash IS NOT NULL')->where('used_at', null)->countAllResults(),
            'refresh_tokens_active' => $this->db->table('token')->where('nip', self::NIP)->where('revoked', 0)->countAllResults(),
            'audit_updates'         => $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'update')->countAllResults(),
        ];
    }

    private function table(string $name): string
    {
        return $this->db->protectIdentifiers($name, true);
    }

    private function lockWaitCount(string $where): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX t'
            . ' JOIN information_schema.PROCESSLIST p ON p.ID = t.trx_mysql_thread_id'
            . " WHERE t.trx_state = 'LOCK WAIT' AND " . $where,
        )->getRow();

        return (int) $row->n;
    }

    /**
     * Interval polling sengaja > 100 ms: isi information_schema.INNODB_TRX di-cache InnoDB dan hanya diperbarui bila
     * pembacaan terakhir lebih dari 100 ms yang lalu, sehingga polling yang lebih rapat membaca data basi terus.
     */
    private function waitUntil(Closure $condition, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                return false;
            }

            usleep(200_000);
        }

        return true;
    }

    private function reapAsync(mysqli $mysqli): void
    {
        $read = $error = $reject = [$mysqli];

        if (mysqli::poll($read, $error, $reject, 10) < 1) {
            return;
        }

        try {
            $result = $mysqli->reap_async_query();

            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } catch (Throwable) {
            // pesaing bisa saja jadi korban; yang penting hasil async sudah diambil sebelum rollback
        }
    }

    /**
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function startWorker(string $token, string $password): array
    {
        $env = array_merge(getenv(), [
            'CI_ENVIRONMENT'          => 'testing',
            'database.tests.database' => $this->db->getDatabase(),
            'jwt.secret'              => config(JwtConfig::class)->secret,
        ]);

        $process = proc_open(
            [PHP_BINARY, SUPPORTPATH . 'Scripts' . DIRECTORY_SEPARATOR . 'reset_password_worker.php', $token, $password],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOTPATH,
            $env,
        );
        $this->assertIsResource($process, 'Worker PHP gagal dijalankan');

        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{process: resource, stdout: resource, stderr: resource} $worker
     *
     * @return array{status: int, class?: string, message?: string, errors?: mixed}
     */
    private function finishWorker(array $worker): array
    {
        // Output worker kecil (satu baris JSON), jadi membaca stdout lalu stderr berurutan tidak akan macet.
        $output = (string) stream_get_contents($worker['stdout']);
        $errors = (string) stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        proc_close($worker['process']);

        $lines  = preg_split('/\R/', trim($output)) ?: [];
        $result = json_decode((string) end($lines), true);
        $this->assertIsArray($result, 'Output worker bukan JSON: ' . $output . $errors);

        return $result;
    }
}
