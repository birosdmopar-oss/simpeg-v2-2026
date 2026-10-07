<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterService;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * CR-020 (ISSUE-020) — tulis master diserialkan named lock per tabel (MasterService::serialized()): tambah, sisip,
 * pindah lingkup, dan pulihkan yang paralel di lingkup urutan yang sama menghasilkan urutan rapat 1..n tanpa kembar.
 * Sebelumnya MAX(order)+1 dan posisi sisip dibaca tanpa kunci, sehingga 3 tambah kelurahan paralel menghasilkan urutan
 * [1,2,3,3,3] dan 3 tambah agama `order`=1 menghasilkan [1,1,1,2,…] (QAFUNC-002-R2 RACE-ORDER).
 *
 * Paralel sungguhan memakai proses PHP terpisah (tests/_support/Scripts/master_write_worker.php, koneksi DB sendiri):
 * lock ditahan koneksi lain sampai SEMUA worker terlihat menunggu GET_LOCK di information_schema.PROCESSLIST
 * (portabel MySQL 8 / MariaDB 10.4), lalu dilepas bersamaan.
 *
 * Seed (MasterDataSeeder): agama 1-6 (order 1-6, status 1); kelurahan 3171010001/3171010002 di kecamatan 3171010
 * (order 1-2); sentinel wilayah LAIN-LAIN dari migration.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class MasterWriteLockTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    private const AGAMA = 'api/v1/master/agama';

    private const KELURAHAN = 'api/v1/master/kelurahan';

    private const KECAMATAN = '3171010';

    private const TRIGGER = 'cr020_simulasi_gagal';

    /**
     * Batas tunggu lock worker (detik): cukup lama agar worker pertama tetap menunggu selama worker lain masih start-up.
     */
    private const WORKER_LOCK_TIMEOUT = 60;

    /**
     * Koneksi kedua (sesi MySQL lain) yang menahan named lock. Menutup sesinya melepas lock (RELEASE_ALL_LOCKS baru ada
     * di MariaDB 10.5).
     */
    private ?BaseConnection $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            $this->other->close();
            $this->other = null;
        }

        $this->db->query('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        Services::resetSingle('masterService');
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 409 deterministik & pelepasan lock
    // ------------------------------------------------------------------

    /**
     * Selama sesi lain memegang lock tabel agama, SEMUA tulis agama (tambah, ubah, pulihkan lewat PUT maupun PATCH
     * status, nonaktifkan, pindah urutan, hapus) menunggu lalu 409 tanpa tulis & audit. Lock per tabel: master lain
     * tetap bisa ditulis. Setelah dilepas, tulis berhasil dan lock tidak tertinggal.
     */
    public function testWritesWaitForTableLockThenReturn409WithoutWriting(): void
    {
        $this->sendJson('DELETE', self::AGAMA . '/6')->assertStatus(200);

        $service = new MasterService(service('masterRegistry'), service('cacheService'), null, 0);
        Services::injectMock('masterService', $service);
        $agama = $this->def('agama');

        $before = $this->rows('agama', 'id_agama');
        $audits = $this->db->table('audit_logs')->countAllResults();

        $this->other = db_connect('tests', false);
        $this->assertSame('1', (string) $this->other->query('SELECT GET_LOCK(?, 1) AS l', [$service->lockName($agama)])->getRowArray()['l']);

        foreach ([
            ['POST', self::AGAMA, ['agama' => 'Menunggu Lock', 'order' => 1]],
            ['PUT', self::AGAMA . '/2', ['agama' => 'Menunggu Lock']],
            ['PUT', self::AGAMA . '/6', ['status' => '1']],
            ['PATCH', self::AGAMA . '/6/status', ['status' => '1']],
            ['PATCH', self::AGAMA . '/2/status', ['status' => '2']],
            ['PATCH', self::AGAMA . '/3/order', ['order' => 1]],
            ['DELETE', self::AGAMA . '/4', []],
        ] as [$method, $uri, $body]) {
            $result = $this->sendJson($method, $uri, $body);
            $result->assertStatus(409);
            $this->assertSame(sprintf(MasterService::LOCK_BUSY_MESSAGE, 'Agama'), $this->json($result)['message'], "{$method} {$uri}");
        }

        $this->assertSame($before, $this->rows('agama', 'id_agama'));
        $this->assertSame($audits, $this->db->table('audit_logs')->countAllResults());

        // Lock per tabel: kelurahan tidak ikut terkunci.
        $this->sendJson('POST', self::KELURAHAN, ['id_kelurahan' => '3171010003', 'id_kecamatan' => self::KECAMATAN, 'kelurahan' => 'Petojo Utara', 'kd_pos' => '10130'])->assertStatus(201);

        $this->other->query('SELECT RELEASE_LOCK(?)', [$service->lockName($agama)]);
        $this->sendJson('POST', self::AGAMA, ['agama' => 'Setelah Lock', 'order' => 1])->assertStatus(201);
        $this->sendJson('PATCH', self::AGAMA . '/6/status', ['status' => '1'])->assertStatus(200);

        $this->assertLockFree($service, $agama);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->visibleOrders($agama));
    }

    /**
     * Lock selalu dilepas, juga saat tulis gagal di dalam lock: 422 (nama ganda, baris sistem), 404, dan error DB
     * (trigger) yang me-rollback transaksi. Tulis di dalam transaksi pemanggil ditolak (LogicException) sebelum lock
     * diambil, karena lock sesi akan lepas sebelum transaksi luar commit. Nama lock ≤ 64 karakter, per DB + prefix +
     * tabel.
     */
    public function testLockIsAlwaysReleasedAndCallerTransactionIsRejected(): void
    {
        $service   = service('masterService');
        $agama     = $this->def('agama');
        $kelurahan = $this->def('kelurahan');

        $this->assertSame('simpeg_md_' . md5($this->db->getDatabase() . '|' . $this->db->getPrefix() . '|agama'), $service->lockName($agama));
        $this->assertLessThanOrEqual(64, strlen($service->lockName($agama)));
        $this->assertNotSame($service->lockName($agama), $service->lockName($kelurahan));

        $this->sendJson('POST', self::AGAMA, ['agama' => 'islam'])->assertStatus(422);
        $this->sendJson('PUT', self::AGAMA . '/99', ['agama' => 'Tidak Ada'])->assertStatus(404);
        $this->sendJson('PUT', self::KELURAHAN . '/9999999999', ['kelurahan' => 'Diubah'])->assertStatus(422);
        $this->assertLockFree($service, $agama);
        $this->assertLockFree($service, $kelurahan);

        $this->db->query(sprintf(
            "CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulasi gagal CR-020'",
            self::TRIGGER,
            $this->db->escapeIdentifiers($this->db->prefixTable('agama')),
        ));

        try {
            $service->create($agama, ['agama' => 'Pemicu Gagal', 'order' => 1]);
            $this->fail('INSERT yang ditolak trigger harus melempar DatabaseException.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('simulasi gagal CR-020', $e->getMessage());
        } finally {
            $this->db->query('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        }

        $this->dontSeeInDatabase('agama', ['agama' => 'Pemicu Gagal']);
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->visibleOrders($agama));
        $this->assertLockFree($service, $agama);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame(0, $this->db->transDepth);

        $this->db->transBegin();

        try {
            $service->create($agama, ['agama' => 'Dalam Transaksi']);
            $this->fail('Tulis master di dalam transaksi pemanggil harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('di dalam transaksi pemanggil', $e->getMessage());
        } finally {
            $this->db->transRollback();
            $this->db->resetTransStatus();
        }

        $this->dontSeeInDatabase('agama', ['agama' => 'Dalam Transaksi']);
        $this->assertLockFree($service, $agama);

        $this->sendJson('POST', self::AGAMA, ['agama' => 'Setelah Gagal'])->assertStatus(201);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->visibleOrders($agama));
    }

    // ------------------------------------------------------------------
    // Paralel sungguhan (proses PHP terpisah)
    // ------------------------------------------------------------------

    /**
     * Skenario RACE-ORDER QAFUNC-002-R2: 3 tambah kelurahan TANPA `order` di satu kecamatan (MAX+1) dan 3 tambah agama
     * dengan `order`=1 (sisip), keenamnya dilepas bersamaan. Semua 200, urutan tiap lingkup rapat 1..n tanpa kembar:
     * kelurahan baru di posisi 3-5, agama baru di posisi 1-3.
     */
    public function testParallelCreatesInSameOrderScopeKeepOrderTight(): void
    {
        $agama     = $this->def('agama');
        $kelurahan = $this->def('kelurahan');
        $jobs      = [];

        foreach (['A', 'B', 'C'] as $i => $suffix) {
            $jobs["kelurahan {$suffix}"] = ['op' => 'create', 'entity' => 'kelurahan', 'data' => [
                'id_kelurahan' => '31710100' . (11 + $i), 'id_kecamatan' => self::KECAMATAN, 'kelurahan' => "Paralel {$suffix}", 'kd_pos' => '10110',
            ]];
            $jobs["agama {$suffix}"] = ['op' => 'create', 'entity' => 'agama', 'data' => ['agama' => "Paralel {$suffix}", 'order' => 1]];
        }

        $results = $this->runWorkersTogether([$agama, $kelurahan], $jobs);

        $this->assertSame(array_fill_keys(array_keys($jobs), 200), array_map(static fn (array $r): int => $r['status'], $results), json_encode($results, JSON_THROW_ON_ERROR));

        $this->assertSame([1, 2, 3, 4, 5], $this->visibleOrders($kelurahan, ['id_kecamatan' => self::KECAMATAN]));
        $this->assertSame([3, 4, 5], $this->ordersOf('kelurahan', 'id_kelurahan', ['3171010011', '3171010012', '3171010013']));
        $this->assertSame([1, 2], $this->ordersOf('kelurahan', 'id_kelurahan', ['3171010001', '3171010002'], false));

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $this->visibleOrders($agama));
        $newAgama = array_map(static fn (string $suffix): string => $results["agama {$suffix}"]['id'], ['A', 'B', 'C']);
        $this->assertSame([1, 2, 3], $this->ordersOf('agama', 'id_agama', $newAgama));
        $this->assertSame([4, 5, 6, 7, 8, 9], $this->ordersOf('agama', 'id_agama', ['1', '2', '3', '4', '5', '6'], false));
    }

    /**
     * Campuran di satu lingkup (agama): tambah tanpa `order` (MAX+1), pulihkan lewat PATCH status dan lewat PUT status
     * (keduanya MAX+1), dan pindah urutan — dilepas bersamaan. Semua 200 dan urutan rapat 1..7 tanpa kembar.
     */
    public function testParallelCreateRestoreAndReorderInSameScopeKeepOrderTight(): void
    {
        $agama = $this->def('agama');

        $this->sendJson('DELETE', self::AGAMA . '/5')->assertStatus(200);
        $this->sendJson('DELETE', self::AGAMA . '/6')->assertStatus(200);
        $this->assertSame([1, 2, 3, 4], $this->visibleOrders($agama));

        $results = $this->runWorkersTogether([$agama], [
            'tambah'         => ['op' => 'create', 'entity' => 'agama', 'data' => ['agama' => 'Paralel Baru']],
            'pulihkan patch' => ['op' => 'setStatus', 'entity' => 'agama', 'id' => '6', 'status' => '1'],
            'pulihkan put'   => ['op' => 'update', 'entity' => 'agama', 'id' => '5', 'data' => ['status' => '2']],
            'pindah urutan'  => ['op' => 'reorder', 'entity' => 'agama', 'id' => '4', 'order' => 1],
        ]);

        $this->assertSame([200, 200, 200, 200], array_values(array_map(static fn (array $r): int => $r['status'], $results)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->visibleOrders($agama));
        $this->assertSame([1, 2, 3, 4], $this->ordersOf('agama', 'id_agama', ['4', '1', '2', '3'], false));
        $this->seeInDatabase('agama', ['id_agama' => 5, 'status' => 2]);
        $this->seeInDatabase('agama', ['id_agama' => 6, 'status' => 1]);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function def(string $key): MasterDefinition
    {
        return service('masterRegistry')->get($key);
    }

    private function assertLockFree(MasterService $service, MasterDefinition $def): void
    {
        $this->assertSame('1', (string) $this->db->query('SELECT IS_FREE_LOCK(?) AS f', [$service->lockName($def)])->getRowArray()['f'], $def->key);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $pk): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table($table)->orderBy($pk, 'ASC')->get()->getResultArray();

        return $rows;
    }

    /**
     * Nilai `order` entri yang tampil (tanpa status 10 & baris sistem) di satu lingkup, terurut.
     *
     * @param array<string, string> $scope
     *
     * @return list<int>
     */
    private function visibleOrders(MasterDefinition $def, array $scope = []): array
    {
        $builder = $this->db->table($def->table)->select('order')->where('status !=', 10)->where($scope);

        if ($def->systemIds !== []) {
            $builder->whereNotIn($def->primaryKey, $def->systemIds);
        }

        $orders = array_map(static fn (array $row): int => (int) $row['order'], $builder->get()->getResultArray());
        sort($orders);

        return $orders;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int> order tiap id (terurut bila $sorted)
     */
    private function ordersOf(string $table, string $pk, array $ids, bool $sorted = true): array
    {
        $orders = array_map(
            fn (string $id): int => (int) $this->db->table($table)->where($pk, $id)->get()->getRowArray()['order'],
            $ids,
        );

        if ($sorted) {
            sort($orders);
        }

        return $orders;
    }

    /**
     * Tahan lock tabel $defs di koneksi lain, jalankan semua $jobs sebagai proses worker, tunggu sampai SEMUANYA
     * menunggu GET_LOCK, lalu lepas lock sekaligus (tutup sesi penahan). Worker yang tidak pernah menunggu lock (tulis
     * tidak diserialkan) membuat test gagal.
     *
     * @param list<MasterDefinition>              $defs
     * @param array<string, array<string, mixed>> $jobs
     *
     * @return array<string, array{status: int, id?: string, class?: string, message?: string, errors?: mixed}>
     */
    private function runWorkersTogether(array $defs, array $jobs): array
    {
        $service     = service('masterService');
        $this->other = db_connect('tests', false);

        foreach ($defs as $def) {
            $this->assertSame('1', (string) $this->other->query('SELECT GET_LOCK(?, 1) AS l', [$service->lockName($def)])->getRowArray()['l']);
        }

        $workers = [];

        try {
            foreach ($jobs as $name => $job) {
                $workers[$name] = $this->startWorker($job);
            }

            $allWaiting = $this->waitUntil(fn (): bool => $this->lockWaitCount() >= count($jobs), 60.0);
        } finally {
            $this->other->close();
            $this->other = null;
        }

        $results = [];

        foreach ($workers as $name => $worker) {
            $results[$name] = $this->finishWorker($worker);
        }

        $this->assertTrue($allWaiting, 'Semua worker harus menunggu named lock tabel sebelum dilepas; hasil: ' . json_encode($results, JSON_THROW_ON_ERROR));

        return $results;
    }

    /**
     * Jumlah sesi di database test ini yang sedang menunggu GET_LOCK (information_schema.PROCESSLIST: MySQL 8 &
     * MariaDB 10.4). Query ini sendiri tidak terhitung karena teksnya tidak diawali SELECT GET_LOCK.
     */
    private function lockWaitCount(): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE DB = ? AND INFO LIKE 'SELECT GET_LOCK(%'",
            [$this->db->getDatabase()],
        )->getRow();

        return (int) $row->n;
    }

    private function waitUntil(Closure $condition, float $timeout): bool
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

    /**
     * @param array<string, mixed> $job
     *
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function startWorker(array $job): array
    {
        $env = array_merge(getenv(), [
            'CI_ENVIRONMENT'          => 'testing',
            'database.tests.database' => $this->db->getDatabase(),
        ]);

        $process = proc_open(
            [
                PHP_BINARY,
                SUPPORTPATH . 'Scripts' . DIRECTORY_SEPARATOR . 'master_write_worker.php',
                base64_encode(json_encode($job, JSON_THROW_ON_ERROR)),
                (string) self::WORKER_LOCK_TIMEOUT,
            ],
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
     * @return array{status: int, id?: string, class?: string, message?: string, errors?: mixed}
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
