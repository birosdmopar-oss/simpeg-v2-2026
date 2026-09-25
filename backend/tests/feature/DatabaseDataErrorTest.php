<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Controllers\DataErrorProbeController;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * CR-007 (prasyarat strictOn) — error data MySQL (1406/1264/1366/1292/1265/1364) diterjemahkan ke 422 generik,
 * detail (kolom, nilai) hanya di log. Error DB lain (1062 yang tidak ditangani service, 1205, 1644, dll.) tetap 500.
 *
 * Error-nya nyata: ditulis lewat koneksi `tests` (strictOn=true) ke tabel dummy (DataErrorProbeController) dan ke
 * master sungguhan yang kolomnya dipersempit, jadi melewati ApiController::_remap() dan engine master yang sama
 * dengan produksi.
 *
 * @internal
 */
final class DatabaseDataErrorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const PROBE_URI = 'api/v1/_probe/write/';

    private const THROW_URI = 'api/v1/_probe/throw/';

    /**
     * Kode error data yang WAJIB menjadi 422 (spesifikasi CR-007), sengaja ditulis ulang di sini — bukan dibaca dari
     * ApiExceptionHandler::DATA_ERROR_CODES — agar kode yang hilang dari daftar itu ketahuan.
     */
    private const SPEC_DATA_CODES = [1264, 1265, 1292, 1364, 1366, 1406];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();

        $this->withRoutes([
            ['POST', 'api/v1/_probe/write/(:segment)', '\\' . DataErrorProbeController::class . '::write/$1'],
            ['POST', 'api/v1/_probe/throw/(:segment)', '\\' . DataErrorProbeController::class . '::throwDbError/$1'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        // withRoutes() mengubah RouteCollection shared (dan router shared memegang referensinya): bangun ulang
        // keduanya agar route probe tidak bocor ke test lain.
        Services::resetSingle('routes');
        Services::resetSingle('router');
        parent::tearDown();
    }

    public function testEachDataErrorCodeBecomesGeneric422WithDetailOnlyInLog(): void
    {
        // Prasyarat: koneksi strict. Tanpa strict, nilai-nilai ini dipotong/diubah diam-diam dan tidak ada error.
        $this->assertStringContainsString('STRICT_ALL_TABLES', (string) $this->db->query('SELECT @@SESSION.sql_mode AS m')->getRow()->m);

        $codes = ApiExceptionHandler::DATA_ERROR_CODES;
        sort($codes);
        $this->assertSame(self::SPEC_DATA_CODES, $codes);

        $covered = [];

        foreach (DataErrorProbeController::cases() as $case => ['code' => $code]) {
            if (! in_array($code, self::SPEC_DATA_CODES, true)) {
                continue;
            }

            $covered[] = $code;
            $result    = $this->post(self::PROBE_URI . $case);

            $result->assertStatus(422);
            $this->assertSame(['status' => 'error', 'message' => ApiExceptionHandler::DATA_ERROR_MESSAGE], $this->json($result), $case);

            // Pesan MySQL tidak bocor ke klien (termasuk di non-production).
            $body = (string) $result->response()->getBody();
            $this->assertStringNotContainsString('column', $body, $case);
            $this->assertStringNotContainsString('nip', $body, $case);

            // ...tetapi tercatat lengkap di log, dengan kode MySQL-nya.
            $this->assertLogContains('error', "Error data database diterjemahkan ke 422: [{$code}]");
        }

        // Seluruh kode spesifikasi punya kasus probe nyata.
        $covered = array_values(array_unique($covered));
        sort($covered);
        $this->assertSame(self::SPEC_DATA_CODES, $covered);

        $this->assertSame(0, $this->db->table('riwayat_dummy')->countAllResults(), 'Tidak ada baris yang tertulis');
        $this->assertLogContains('error', 'Error data database diterjemahkan ke 422: [1366] Incorrect string value');
    }

    public function testOtherDatabaseErrorsStayServerErrors(): void
    {
        $this->db->table('pegawai_dummy')->insert(['nip' => 'NIP-GANDA']);

        // Error DB selain kode data tidak ditelan _remap(): tetap dilempar ke handler global (log critical + trace, 500)
        // dan tidak tercatat sebagai "diterjemahkan ke 422". Nyata: 1062 yang tidak diterjemahkan service, 1146; simulasi
        // dari dalam controller: lock wait, deadlock, SIGNAL, DatabaseException tanpa kode.
        $cases = [
            self::PROBE_URI . 'duplikat'        => 1062,
            self::PROBE_URI . 'tabel-tidak-ada' => 1146,
        ];

        foreach (DataErrorProbeController::simulatedServerErrorCases() as $case => $code) {
            $cases[self::THROW_URI . $case] = $code;
        }

        foreach ($cases as $uri => $code) {
            try {
                $this->post($uri);
                $this->fail("Error DB {$code} ({$uri}) harus tetap dilempar ke handler global.");
            } catch (DatabaseException $e) {
                $this->assertSame($code, $e->getCode(), $uri);
                $this->assertSame(500, ApiExceptionHandler::toEnvelope($e)[0], $uri);
            }

            $this->assertFalse(TestLogger::didLog('error', "Error data database diterjemahkan ke 422: [{$code}]", false), $uri);
        }

        $this->assertSame(1, $this->db->table('pegawai_dummy')->countAllResults());
    }

    public function testGlobalHandlerTranslatesOnlyDataErrorCodes(): void
    {
        // Jalur handler global (exception lolos dari filter / controller non-ApiController).
        foreach (self::SPEC_DATA_CODES as $code) {
            [$status, $body] = ApiExceptionHandler::toEnvelope(new DatabaseException("Data too long for column 'nip' at row 1", $code), 500);

            $this->assertSame(422, $status, (string) $code);
            $this->assertSame(['status' => 'error', 'message' => ApiExceptionHandler::DATA_ERROR_MESSAGE], $body, (string) $code);
        }

        // Kode lain (duplikat, lock wait, deadlock, SIGNAL, tanpa kode) tetap 500; begitu juga kode data di exception
        // non-DB.
        foreach ([
            'duplikat'   => new DatabaseException('Duplicate entry', 1062),
            'lock wait'  => new DatabaseException('Lock wait timeout exceeded', 1205),
            'deadlock'   => new DatabaseException('Deadlock found', 1213),
            'signal'     => new DatabaseException('simulasi gagal', 1644),
            'tanpa kode' => new DatabaseException('Reset password gagal disimpan.'),
            'bukan DB'   => new RuntimeException('Data too long', 1406),
        ] as $case => $exception) {
            $this->assertSame(500, ApiExceptionHandler::toEnvelope($exception, 500)[0], $case);
        }

        // Error API tidak berubah.
        $this->assertSame(422, ApiExceptionHandler::toEnvelope(new ValidationException())[0]);
    }

    public function testMasterEngineWriteFailureIsRolledBackAndReturns422(): void
    {
        // Simulasikan master yang batas validasinya lebih longgar dari kolom (risiko R2 DBV-003..005): kolom nama agama
        // dipersempit ke panjang nama seed terpanjang, validasi tetap maks. 30 karakter.
        $table    = $this->db->prefixTable('agama');
        $original = $this->columnDefinition($table, 'agama');
        $maxLen   = (int) $this->db->query("SELECT MAX(CHAR_LENGTH(`agama`)) AS n FROM {$table}")->getRow()->n;
        $name     = 'Kepercayaan Terhadap Tuhan YME'; // 30 karakter
        $this->assertGreaterThan($maxLen, mb_strlen($name));

        $this->db->query("ALTER TABLE {$table} MODIFY `agama` VARCHAR({$maxLen}) COLLATE utf8mb4_unicode_ci NOT NULL");

        try {
            $auditBefore = $this->db->table('audit_logs')->countAllResults();

            $result = $this->asRole(Role::SUPER_ADMIN)->sendJson('POST', 'api/v1/master/agama', ['agama' => $name]);

            // MasterModel melempar DatabaseException(1406) dari $db->error() di dalam transaksi MasterService.
            $result->assertStatus(422);
            $this->assertSame(['status' => 'error', 'message' => ApiExceptionHandler::DATA_ERROR_MESSAGE], $this->json($result));
            $this->assertLogContains('error', 'Error data database diterjemahkan ke 422: [1406]');

            // Transaksi di-rollback utuh dan koneksi tidak tertinggal dalam status gagal.
            $this->dontSeeInDatabase('agama', ['agama' => $name]);
            $this->assertSame($auditBefore, $this->db->table('audit_logs')->countAllResults());
            $this->assertTrue($this->db->transStatus());
        } finally {
            $this->db->query("ALTER TABLE {$table} MODIFY {$original}");
        }

        // Dengan kolom asli, nilai yang sama tersimpan: 422 tadi murni karena batas kolom.
        $this->clearAuthState();
        $this->asRole(Role::SUPER_ADMIN)->sendJson('POST', 'api/v1/master/agama', ['agama' => $name])->assertStatus(201);
    }

    /**
     * Definisi kolom persis dari SHOW CREATE TABLE (tanpa koma akhir), untuk memulihkan kolom setelah dipersempit.
     */
    private function columnDefinition(string $table, string $column): string
    {
        $create = (string) $this->db->query("SHOW CREATE TABLE {$table}")->getRowArray()['Create Table'];

        $this->assertSame(1, preg_match('/^\s*(`' . preg_quote($column, '/') . '` .*?),?$/m', $create, $m), $create);

        return $m[1];
    }
}
