<?php

declare(strict_types=1);

namespace Tests\Auth;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * A-01 — verifikasi eksplisit skema tabel auth (DoD: password_decode tidak ada, password >= 255).
 *
 * @internal
 */
final class AuthSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testAuthTablesExist(): void
    {
        foreach (['pengguna', 'login_attempts', 'forgot_attempts', 'token', 'audit_logs'] as $table) {
            $this->assertTrue($this->db->tableExists($table), "Tabel {$table} harus ada");
        }
    }

    public function testPasswordDecodeColumnDoesNotExist(): void
    {
        $fields = array_map(static fn ($f) => $f->name, $this->db->getFieldData('pengguna'));

        $this->assertNotContains('password_decode', $fields, 'Kolom plaintext legacy password_decode TIDAK BOLEH ada di v2');
        $this->assertContains('password', $fields);
        $this->assertContains('password_legacy', $fields);
    }

    public function testPasswordColumnLength(): void
    {
        foreach ($this->db->getFieldData('pengguna') as $field) {
            if ($field->name === 'password') {
                $this->assertGreaterThanOrEqual(255, (int) $field->max_length, 'password harus cukup panjang untuk hash Argon2id');
                $this->assertTrue((bool) $field->nullable, 'password NULL sampai login pertama (lazy rehash)');

                return;
            }
        }

        $this->fail('Kolom password tidak ditemukan');
    }

    public function testNipIsUniqueAndNotNull(): void
    {
        $indexes = $this->db->getIndexData('pengguna');
        $unique  = [];

        foreach ($indexes as $index) {
            if ($index->type === 'UNIQUE') {
                $unique[] = implode(',', $index->fields);
            }
        }

        $this->assertContains('nip', $unique);
        $this->assertContains('username', $unique);

        foreach ($this->db->getFieldData('pengguna') as $field) {
            if ($field->name === 'nip') {
                $this->assertFalse((bool) $field->nullable);
            }
        }
    }

    public function testAuditEventEnumIncludesLoginLogout(): void
    {
        $row = $this->db->query('SHOW COLUMNS FROM ' . $this->db->prefixTable('audit_logs') . " LIKE 'event'")->getRowArray();

        $this->assertNotNull($row);
        $this->assertStringContainsString("'login'", (string) $row['Type']);
        $this->assertStringContainsString("'logout'", (string) $row['Type']);
    }
}
