<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Database\Migrations\AlterIdentitasAkunIdPengguna;
use App\Database\Migrations\AlterPenggunaAkunNonPegawai;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Throwable;

/**
 * DBV-010 (2/3, 3/3) — skema akun non-pegawai dan identitas akun `id_pengguna`:
 *   - 2026-09-25-130100_AlterPenggunaAkunNonPegawai: pengguna.nip VARCHAR(30) NULL, username VARCHAR(100), kolom legacy
 *     name/email/expired_at; login_attempts/forgot_attempts.username VARCHAR(100).
 *   - 2026-09-25-130200_AlterIdentitasAkunIdPengguna: token.id_pengguna (+ tabel dikosongkan), audit_logs.id_pengguna_actor
 *     (+ backfill dari nip_actor), nip/nip_actor VARCHAR(30) sebagai jejak.
 * down() menolak data yang tidak bisa disimpan skema lama sebelum ALTER apa pun (backend/docs/db-review/A-01-auth-schema.md
 * Bagian 9).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class AkunNonPegawaiSchemaTest extends DatabaseTestCase
{
    private const UNICODE = 'utf8mb4_unicode_ci';

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi.
     */
    private const COLUMNS = [
        'pengguna' => [
            'id_pengguna' => 'int unsigned', 'nip' => 'varchar(30) null', 'username' => 'varchar(100)', 'name' => 'varchar(150) null',
            'email'       => 'varchar(150) null', 'password' => 'varchar(255) null', 'password_legacy' => 'varchar(32) null',
            'user_level'  => 'tinyint unsigned', 'id_unit' => 'varchar(10) null', 'id_satker' => 'varchar(10) null',
            'status'      => "enum('0','1')", 'last_login_at' => 'datetime null', 'password_changed_at' => 'datetime null',
            'expired_at'  => 'datetime null', 'created_at' => 'datetime null', 'updated_at' => 'datetime null', 'deleted_at' => 'datetime null',
        ],
        'token' => [
            'id'          => 'int unsigned', 'id_pengguna' => 'int unsigned', 'nip' => 'varchar(30) null', 'token_hash' => 'char(64)',
            'claims_json' => 'json null', 'expires_at' => 'datetime', 'revoked' => 'tinyint unsigned', 'revoked_at' => 'datetime null',
            'created_at'  => 'datetime',
        ],
        'audit_logs' => [
            'id_log'      => 'int unsigned', 'id_pengguna_actor' => 'int unsigned null', 'nip_actor' => 'varchar(30) null',
            'entity'      => 'varchar(100)', 'entity_id' => 'varchar(50)', 'event' => "enum('create','update','delete','login','logout')",
            'before_json' => 'json null', 'after_json' => 'json null', 'created_at' => 'datetime',
        ],
        'login_attempts' => [
            'id'           => 'int unsigned', 'username' => 'varchar(100)', 'ip_address' => 'varchar(45) null', 'success' => 'tinyint unsigned',
            'attempted_at' => 'datetime',
        ],
    ];

    private const COMMENTS = [
        ['pengguna', 'nip', 'NIP pegawai (legacy id_pegawai); NULL untuk akun non-pegawai (role 1/3/4/5/8)'],
        ['pengguna', 'name', 'nama akun (legacy name); wajib diisi aplikasi untuk akun tanpa NIP'],
        ['pengguna', 'email', 'email akun (legacy); tujuan tautan reset password (K3)'],
        ['pengguna', 'expired_at', 'legacy: batas berlaku password (paksa ganti); belum dipakai aplikasi v2'],
        ['token', 'id_pengguna', 'pemilik sesi (pengguna.id_pengguna)'],
        ['token', 'nip', 'NIP pemilik saat token terbit (jejak); NULL untuk akun tanpa NIP'],
        ['audit_logs', 'id_pengguna_actor', 'pelaku (pengguna.id_pengguna); NULL untuk proses sistem/CLI'],
        ['audit_logs', 'nip_actor', 'NIP pelaku saat kejadian (jejak); NULL untuk akun tanpa NIP dan proses sistem/CLI'],
    ];

    protected function tearDown(): void
    {
        foreach (['audit_logs', 'token', 'login_attempts', 'forgot_attempts', 'pengguna'] as $table) {
            $this->db->table($table)->emptyTable();
        }

        $this->m2()->up();
        $this->m3()->up();

        parent::tearDown();
    }

    public function testSchemaAfterMigration(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $this->assertSame($columns, $this->columns($table), "kolom {$table}");
        }

        $this->assertSame('varchar(100)', $this->columns('forgot_attempts')['username']);

        foreach (self::COMMENTS as [$table, $column, $comment]) {
            $this->assertSame($comment, $this->columnInfo($table, $column)['COLUMN_COMMENT'], "COMMENT {$table}.{$column}");
        }

        foreach ([['pengguna', 'nip'], ['pengguna', 'username'], ['pengguna', 'name'], ['pengguna', 'email'], ['token', 'nip'],
            ['audit_logs', 'nip_actor'], ['login_attempts', 'username'], ['forgot_attempts', 'username']] as [$table, $column]) {
            $this->assertSame(self::UNICODE, $this->columnInfo($table, $column)['COLLATION_NAME'], "{$table}.{$column}");
        }

        $this->assertSame(['id_pengguna'], $this->indexColumns('token', 'id_pengguna'));
        $this->assertNull($this->indexColumns('token', 'nip'), 'KEY token.nip dihapus (tidak dipakai query lagi)');
        $this->assertSame(['id_pengguna_actor'], $this->indexColumns('audit_logs', 'id_pengguna_actor'));
        $this->assertSame(['nip_actor'], $this->indexColumns('audit_logs', 'nip_actor'));
        $this->assertSame(['nip'], $this->indexColumns('pengguna', 'nip', true), 'UNIQUE nip tetap');
        $this->assertSame(['username'], $this->indexColumns('pengguna', 'username', true));
    }

    /**
     * up() 130200 mengosongkan token (paksa login ulang) dan mengisi id_pengguna_actor dari nip_actor (akun yang sudah
     * dihapus ikut; NIP yang tidak dikenal tetap NULL).
     */
    public function testIdentityMigrationEmptiesTokenAndBackfillsAuditActor(): void
    {
        $this->m3()->down();

        $aktif   = $this->insertAccount('198501012010011001', 'aktif');
        $dihapus = $this->insertAccount('199002152015022002', 'dihapus', ['deleted_at' => date('Y-m-d H:i:s')]);

        $this->db->table('token')->insert([
            'nip'        => '198501012010011001', 'token_hash' => hash('sha256', 'lama'), 'claims_json' => json_encode(['sub' => '198501012010011001', 'role' => 1]),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'revoked' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $audit = [];

        foreach (['198501012010011001', '199002152015022002', '000000000000000000', null] as $nip) {
            $this->db->table('audit_logs')->insert(['nip_actor' => $nip, 'entity' => 'uji', 'entity_id' => '1', 'event' => 'update', 'created_at' => date('Y-m-d H:i:s')]);
            $audit[] = (int) $this->db->insertID();
        }

        $this->m3()->up();

        $this->assertSame(0, $this->db->table('token')->countAllResults(), 'seluruh sesi lama berakhir (D-9)');

        $actors = array_column($this->db->table('audit_logs')->orderBy('id_log')->get()->getResultArray(), 'id_pengguna_actor', 'id_log');
        $this->assertSame((string) $aktif, (string) $actors[$audit[0]]);
        $this->assertSame((string) $dihapus, (string) $actors[$audit[1]]);
        $this->assertNull($actors[$audit[2]], 'NIP tanpa akun → pelaku tetap NULL');
        $this->assertNull($actors[$audit[3]], 'proses sistem tetap NULL');
    }

    /**
     * Rollback 130200 ditolak bila ada audit berpelaku akun tanpa NIP (pelaku akan hilang) — tanpa ALTER apa pun.
     */
    public function testIdentityRollbackRejectsAuditActorWithoutNip(): void
    {
        $this->db->table('audit_logs')->insert([
            'id_pengguna_actor' => 5, 'nip_actor' => null, 'entity' => 'uji', 'entity_id' => '1', 'event' => 'update', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $error = $this->catchError(fn () => $this->m3()->down());

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('DBV-010: rollback ditolak', $error->getMessage());
        $this->assertStringContainsString('1 baris audit dengan pelaku akun tanpa NIP', $error->getMessage());
        $this->assertArrayHasKey('id_pengguna_actor', $this->columns('audit_logs'), 'tidak ada ALTER yang jalan');
        $this->assertArrayHasKey('id_pengguna', $this->columns('token'));
    }

    /**
     * `nip_actor` 30 → 20 saat rollback: NIP pelaku > 20 karakter (mis. hasil impor) harus ditolak SEBELUM ALTER apa pun,
     * termasuk bila `id_pengguna_actor` kosong — tanpa pengaman ini `token` sudah dikosongkan/di-ALTER lalu MODIFY
     * `nip_actor` gagal 1265/1406 di tengah jalan (koneksi strict).
     */
    public function testIdentityRollbackRejectsAuditActorNipLongerThanOldColumn(): void
    {
        foreach ([5, null] as $actorId) {
            $this->db->table('audit_logs')->insert([
                'id_pengguna_actor' => $actorId, 'nip_actor' => str_repeat('1', 21), 'entity' => 'uji', 'entity_id' => '1', 'event' => 'update', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->db->table('token')->insert([
                'id_pengguna' => 5, 'nip' => null, 'token_hash' => hash('sha256', 'uji-' . ($actorId ?? 'null')), 'claims_json' => '{}',
                'expires_at'  => date('Y-m-d H:i:s', time() + 3600), 'created_at' => date('Y-m-d H:i:s'),
            ]);

            $error = $this->catchError(fn () => $this->m3()->down());

            $label = 'id_pengguna_actor ' . ($actorId ?? 'NULL');
            $this->assertInstanceOf(RuntimeException::class, $error, $label);
            $this->assertStringContainsString('DBV-010: rollback ditolak', $error->getMessage());
            $this->assertStringContainsString('1 baris audit dengan NIP pelaku > 20 karakter', $error->getMessage(), $label);
            $this->assertArrayHasKey('id_pengguna_actor', $this->columns('audit_logs'), "{$label}: tidak ada ALTER yang jalan");
            $this->assertArrayHasKey('id_pengguna', $this->columns('token'));
            $this->assertSame(1, $this->db->table('token')->countAllResults(), "{$label}: token tidak dikosongkan");

            $this->db->table('audit_logs')->emptyTable();
            $this->db->table('token')->emptyTable();
        }
    }

    /**
     * Rollback 130100 ditolak bila ada akun tanpa NIP (termasuk soft-deleted), username > 30, atau NIP > 20 karakter.
     */
    public function testAccountRollbackRejectsDataTheOldSchemaCannotStore(): void
    {
        $this->m3()->down();

        foreach ([
            'akun tanpa NIP'         => [null, 'admin.legacy', ['deleted_at' => date('Y-m-d H:i:s')]],
            'username > 30 karakter' => ['198501012010011001', str_repeat('u', 31), []],
            'NIP > 20 karakter'      => ['123456789012345678901', 'nip.panjang', []],
        ] as $problem => [$nip, $username, $extra]) {
            $this->insertAccount($nip, $username, $extra);

            $error = $this->catchError(fn () => $this->m2()->down());

            $this->assertInstanceOf(RuntimeException::class, $error, $problem);
            $this->assertStringContainsString('DBV-010: rollback ditolak', $error->getMessage());
            $this->assertStringContainsString($problem, $error->getMessage());
            $this->assertArrayHasKey('name', $this->columns('pengguna'), "{$problem}: tidak ada ALTER yang jalan");

            $this->db->table('pengguna')->emptyTable();
        }
    }

    /**
     * Rollback 130100 menghapus log login/forgot dengan username > 30 karakter, mempertahankan akun biasa, dan up()
     * setelahnya mengembalikan skema tanpa kehilangan akun.
     */
    public function testAccountRollbackRoundTripKeepsAccountsAndDropsLongUsernameLogs(): void
    {
        $id  = $this->insertAccount('198501012010011001', 'admin', ['name' => 'Admin', 'email' => 'admin@example.go.id']);
        $now = date('Y-m-d H:i:s');

        foreach ([str_repeat('p', 31), str_repeat('p', 30)] as $username) {
            $this->db->table('login_attempts')->insert(['username' => $username, 'success' => 0, 'attempted_at' => $now]);
            $this->db->table('forgot_attempts')->insert(['username' => $username, 'requested_at' => $now]);
        }

        $this->m3()->down();
        $this->m2()->down();

        $this->assertSame([str_repeat('p', 30)], array_column($this->db->table('login_attempts')->get()->getResultArray(), 'username'));
        $this->assertSame([str_repeat('p', 30)], array_column($this->db->table('forgot_attempts')->get()->getResultArray(), 'username'));
        $this->assertSame('varchar(20)', $this->columns('pengguna')['nip']);
        $this->assertArrayNotHasKey('name', $this->columns('pengguna'));
        $this->assertArrayNotHasKey('expired_at', $this->columns('pengguna'));

        $this->m2()->up();
        $this->m3()->up();

        $this->assertSame(self::COLUMNS['pengguna'], $this->columns('pengguna'));
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'nip' => '198501012010011001', 'username' => 'admin', 'name' => null]);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    private function insertAccount(?string $nip, string $username, array $extra = []): int
    {
        $this->db->table('pengguna')->insert([
            'nip' => $nip, 'username' => $username, 'password_legacy' => md5('x'), 'user_level' => 1, 'status' => '1',
        ] + $extra);

        return (int) $this->db->insertID();
    }

    private function m2(): AlterPenggunaAkunNonPegawai
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-130100_AlterPenggunaAkunNonPegawai.php';

        return new AlterPenggunaAkunNonPegawai();
    }

    private function m3(): AlterIdentitasAkunIdPengguna
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-130200_AlterIdentitasAkunIdPengguna.php';

        return new AlterIdentitasAkunIdPengguna();
    }

    private function catchError(callable $work): ?Throwable
    {
        try {
            $work();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * @return array<string, string> kolom => "COLUMN_TYPE[ null]", urut posisi
     */
    private function columns(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(3), int(10)); samakan dengan MySQL 8. JSON MariaDB = longtext.
            $type = (string) preg_replace('/^(tinyint|int)\(\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));
            $type = $type === 'longtext' ? 'json' : $type;

            $columns[(string) $row['COLUMN_NAME']] = $type . ($row['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInfo(string $table, string $column): array
    {
        return $this->db->query(
            'SELECT COLUMN_COMMENT, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray() ?? [];
    }

    /**
     * @return list<string>|null kolom index berurutan; null bila index tidak ada (atau keunikan tidak sesuai)
     */
    private function indexColumns(string $table, string $index, bool $unique = false): ?array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $index],
        )->getResultArray();

        if ($rows === [] || ((int) $rows[0]['NON_UNIQUE'] === 0) !== $unique) {
            return null;
        }

        return array_map('strval', array_column($rows, 'COLUMN_NAME'));
    }
}
