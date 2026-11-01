<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * DBV-010/CR-013 — koneksi aplikasi (grup `default`) dan test (grup `tests`) sama-sama strict dan membuat tabel dengan
 * utf8mb4_unicode_ci (ISSUE-008, ISSUE-010). Nilai dibaca setelah override .env, jadi .env yang mematikan strictOn atau
 * mengganti DBCollat ikut ketahuan (runbook A-01 Bagian 9 langkah 4).
 *
 * @internal
 */
final class DatabaseConfigTest extends CIUnitTestCase
{
    public function testDefaultAndTestsGroupsAreStrictUnicode(): void
    {
        $config = config(Database::class);

        foreach (['default' => $config->default, 'tests' => $config->tests] as $group => $settings) {
            $this->assertTrue($settings['strictOn'], "{$group}.strictOn");
            $this->assertSame('utf8mb4', $settings['charset'], "{$group}.charset");
            $this->assertSame('utf8mb4_unicode_ci', $settings['DBCollat'], "{$group}.DBCollat");
        }
    }

    public function testKoneksiAplikasiMemakaiDriverSesiUtc(): void
    {
        $config = config(Database::class);

        // CR-086 / DBV-025: grup default selalu driver aplikasi (zona sesi UTC), walau .env lama menyetel DBDriver = MySQLi.
        $this->assertSame('App\\Database\\MySQLi', $config->default['DBDriver']);

        // Grup tests boleh diganti phpunit.dist.xml dengan driver kelas lain, asal turunan driver aplikasi.
        $driverTests = $config->tests['DBDriver'];
        $this->assertTrue(
            $driverTests === 'App\\Database\\MySQLi' || is_subclass_of($driverTests . '\\Connection', \App\Database\MySQLi\Connection::class),
            "tests.DBDriver {$driverTests} harus driver aplikasi atau turunannya",
        );
    }
}
