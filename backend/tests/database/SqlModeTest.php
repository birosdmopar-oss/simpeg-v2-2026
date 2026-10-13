<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Throwable;

/**
 * DBV-010/CR-013 (ISSUE-008) — koneksi yang dibangun dari konfigurasi grup `default` (jalur aplikasi) benar-benar
 * berjalan strict: sesi memuat STRICT_ALL_TABLES dan nilai yang terlalu panjang ditolak (1406), bukan dipotong diam-diam
 * seperti QAFUNC-002-R2 B5-nonstrict. Host/database/kredensial ditimpa ke database test agar tidak menyentuh data asli.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SqlModeTest extends DatabaseTestCase
{
    private ?BaseConnection $appConnection = null;

    protected function tearDown(): void
    {
        $this->appConnection?->close();
        $this->appConnection = null;

        parent::tearDown();
    }

    public function testDefaultGroupSessionIsStrict(): void
    {
        $mode = (string) $this->appConnection()->query('SELECT @@SESSION.sql_mode AS m')->getRowArray()['m'];

        $this->assertContains('STRICT_ALL_TABLES', explode(',', $mode));
    }

    public function testDefaultGroupRejectsTooLongValueInsteadOfTruncating(): void
    {
        $max = (int) $this->db->query(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('login_attempts'), 'username'],
        )->getRowArray()['n'];

        $error = null;

        try {
            $this->appConnection()->table('login_attempts')->insert([
                'username'     => str_repeat('u', $max + 1),
                'success'      => 0,
                'attempted_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertInstanceOf(DatabaseException::class, $error, 'isian yang tidak muat harus ditolak (strict)');
        $this->assertSame(1406, $error->getCode());
        $this->assertSame(0, $this->db->table('login_attempts')->countAllResults(), 'tidak boleh ada baris terpotong yang tersimpan');
    }

    private function appConnection(): BaseConnection
    {
        if ($this->appConnection !== null) {
            return $this->appConnection;
        }

        $config = config(Database::class);
        $group  = $config->default;

        foreach (['hostname', 'port', 'username', 'password', 'database', 'DBPrefix'] as $key) {
            $group[$key] = $config->tests[$key];
        }

        /** @var BaseConnection $connection */
        $connection = Database::connect($group, false);
        $connection->initialize();

        return $this->appConnection = $connection;
    }
}
