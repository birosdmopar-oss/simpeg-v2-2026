<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\ValidationException;
use App\Libraries\ApiExceptionHandler;
use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterRegistry;
use App\Libraries\MasterData\MasterService;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockConnection;
use CodeIgniter\Test\TestLogger;
use Config\MasterData as MasterDataConfig;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\Libraries\RecordingCacheService;
use Throwable;

/**
 * CR-011 + CR-041 — terjemahan error DB tulis master di MasterService::translateDuplicate(), tanpa DB: exception persis
 * bentukan MasterModel (pesan "Penulisan master data gagal: …" + errno driver) dilempar dari closure $work. Kode
 * yang tidak bisa dimunculkan MySQL lokal (167 MariaDB) ikut teruji di sini; kasus nyata (MySQL 1467 / MariaDB 167
 * dari counter 128, simulasi trigger 167) ada di MasterGenericTcTest.
 *  - PK master AUTO_INCREMENT habis — 1467 (MySQL 8, counter di atas batas), 167 (MariaDB 10.4), 1062 PRIMARY
 *    (MySQL 8, id maksimum terpakai) → 422 pesan batas kode berlabel master, tanpa `errors`; pesan DB hanya di log.
 *  - Error DB lain (deadlock, lock wait, kode acak, tanpa kode, 167 kolom lain, 1467 di luar tambah atau di master
 *    berkode manual) dilempar apa adanya → 500. 1062 index lain tetap perilaku lama: cek ulang, lalu dilempar.
 *
 * @internal
 */
final class MasterAutoIncrementExhaustedTest extends CIUnitTestCase
{
    private const LOG_MARKER = 'Kapasitas AUTO_INCREMENT master';

    private MasterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MasterService(new MasterRegistry(new MasterDataConfig()), new RecordingCacheService(), new MockConnection([]));
    }

    public function testExhaustedAutoIncrementKeyBecomes422OnEveryEngine(): void
    {
        $def = $this->service->registry()->get('jenis-kp');

        $cases = [
            'MySQL 8 counter di atas batas' => self::dbError('Failed to read auto-increment value from storage engine', 1467),
            'MariaDB 10.4'                  => self::dbError("Out of range value for column 'id_jenis_kp' at row 1", 167),
            'MySQL 8 id 127 terpakai'       => self::dbError("Duplicate entry '127' for key 'jenis_kp.PRIMARY'", 1062),
        ];

        foreach ($cases as $case => $error) {
            $logEntry            = self::LOG_MARKER . " jenis_kp habis, diterjemahkan ke 422: [{$error->getCode()}] {$error->getMessage()}";
            $logs                = self::capacityLogCount($logEntry, 'error');
            [$thrown, $rechecks] = $this->translate($error, $def);

            $this->assertInstanceOf(ValidationException::class, $thrown, $case);
            $this->assertSame(1, $rechecks, "{$case}: nama/kode ganda hasil balapan tetap dicek lebih dulu");

            [$status, $body] = ApiExceptionHandler::toEnvelope($thrown);
            $this->assertSame(422, $status, $case);
            $this->assertSame(
                ['status' => 'error', 'message' => 'Kode Jenis Kenaikan Pangkat sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database.'],
                $body,
                $case,
            );

            // Pesan DB tidak sampai ke klien, tetapi tercatat lengkap (tabel, kode, pesan) di log — dihitung sebelum &
            // sesudah, karena catatan yang sama bisa sudah ada dari test lain (MasterGenericTcTest, 1467 nyata).
            $this->assertStringNotContainsString('Penulisan master data gagal', (string) json_encode($body), $case);
            $this->assertSame($logs + 1, self::capacityLogCount($logEntry, 'error'), "{$case}: {$logEntry}");
        }
    }

    /**
     * Nama ganda yang ditemukan cek ulang menang atas "PK habis" (perilaku CR-011, kini juga untuk 1467).
     */
    public function testDuplicateNameFoundByRecheckWinsOverExhaustedKey(): void
    {
        $def = $this->service->registry()->get('jenis-kp');

        foreach ([1467 => 'Failed to read auto-increment value from storage engine', 167 => "Out of range value for column 'id_jenis_kp' at row 1"] as $code => $message) {
            $logs                = self::capacityLogCount();
            [$thrown, $rechecks] = $this->translate(self::dbError($message, $code), $def, static function () use ($def): void {
                throw ValidationException::forField($def->nameField, 'Jenis KP sudah dipakai.');
            });

            $this->assertInstanceOf(ValidationException::class, $thrown, (string) $code);
            $this->assertSame([$def->nameField => ['Jenis KP sudah dipakai.']], $thrown->getErrors(), (string) $code);
            $this->assertSame(1, $rechecks, (string) $code);
            $this->assertSame($logs, self::capacityLogCount(), (string) $code);
        }
    }

    public function testOtherDatabaseErrorsAreRethrownAsServerErrors(): void
    {
        $jenisKp  = $this->service->registry()->get('jenis-kp');
        $provinsi = $this->service->registry()->get('provinsi');

        // [error, insertDef, jumlah cek ulang yang diharapkan]
        $cases = [
            'deadlock'                => [self::dbError('Deadlock found when trying to get lock; try restarting transaction', 1213), $jenisKp, 0],
            'lock wait'               => [self::dbError('Lock wait timeout exceeded; try restarting transaction', 1205), $jenisKp, 0],
            'kode acak'               => [self::dbError('simulasi gagal', 4242), $jenisKp, 0],
            'tanpa kode'              => [new DatabaseException('Penulisan master data gagal: '), $jenisKp, 0],
            '167 kolom lain'          => [self::dbError("Out of range value for column 'order' at row 1", 167), $jenisKp, 0],
            '1467 saat ubah'          => [self::dbError('Failed to read auto-increment value from storage engine', 1467), null, 0],
            '1467 master kode manual' => [self::dbError('Failed to read auto-increment value from storage engine', 1467), $provinsi, 0],
            '167 saat ubah'           => [self::dbError("Out of range value for column 'id_jenis_kp' at row 1", 167), null, 0],
            // 1062 selain PRIMARY: cek ulang dijalankan (lolos di sini), lalu exception asli dilempar — perilaku lama.
            '1062 index lain'        => [self::dbError("Duplicate entry 'Reguler' for key 'jenis_kp.uq_jenis_kp_nama'", 1062), $jenisKp, 1],
            '1062 PRIMARY saat ubah' => [self::dbError("Duplicate entry '127' for key 'jenis_kp.PRIMARY'", 1062), null, 1],
        ];

        foreach ($cases as $case => [$error, $insertDef, $expectedRechecks]) {
            $logs                = self::capacityLogCount();
            [$thrown, $rechecks] = $this->translate($error, $insertDef);

            $this->assertSame($error, $thrown, "{$case}: exception asli dilempar ulang");
            $this->assertSame($expectedRechecks, $rechecks, $case);
            $this->assertSame(500, ApiExceptionHandler::toEnvelope($thrown)[0], $case);
            $this->assertSame($logs, self::capacityLogCount(), "{$case}: tidak dicatat sebagai kapasitas habis");
        }

        // Di luar engine (handler global), 1467 juga tetap 500 — bukan 422 generik CR-007.
        $this->assertSame(500, ApiExceptionHandler::toEnvelope(new DatabaseException('Failed to read auto-increment value from storage engine', 1467), 500)[0]);
    }

    /**
     * Jalankan translateDuplicate() dengan $work yang melempar $error.
     *
     * @return array{0: Throwable, 1: int} exception yang keluar dan jumlah pemanggilan $recheck
     */
    private function translate(DatabaseException $error, ?MasterDefinition $insertDef, ?Closure $recheck = null): array
    {
        $rechecks = 0;

        try {
            (new ReflectionMethod(MasterService::class, 'translateDuplicate'))->invoke(
                $this->service,
                static function () use ($error): void {
                    throw $error;
                },
                static function () use (&$rechecks, $recheck): void {
                    $rechecks++;

                    if ($recheck !== null) {
                        $recheck();
                    }
                },
                $insertDef,
            );
        } catch (Throwable $e) {
            return [$e, $rechecks];
        }

        $this->fail('translateDuplicate() harus menerjemahkan atau melempar ulang error DB.');
    }

    /**
     * Jumlah catatan log yang memuat $needle (bawaan: semua catatan "kapasitas habis"), opsional hanya level $level,
     * sejauh ini (log TestLogger statis, terkumpul lintas test).
     */
    private static function capacityLogCount(string $needle = self::LOG_MARKER, ?string $level = null): int
    {
        /** @var list<array{level: mixed, message: string, file: string|null}> $logs */
        $logs = (new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue();

        return count(array_filter(
            $logs,
            static fn (array $log): bool => ($level === null || strtolower((string) $log['level']) === $level)
                && str_contains($log['message'], $needle),
        ));
    }

    /**
     * DatabaseException seperti MasterModel::writeFailure(): pesan driver diberi awalan, kode = errno driver.
     */
    private static function dbError(string $message, int $code): DatabaseException
    {
        return new DatabaseException('Penulisan master data gagal: ' . $message, $code);
    }
}
