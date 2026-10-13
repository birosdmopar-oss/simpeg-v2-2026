<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * CR-010 (temuan QA CR-009) — definisi di Config\MasterData wajib cocok dengan DDL hasil migration:
 *  - `orderColumnType` = tipe kolom `order` (batas MAX+1 otomatis, kapasitas lingkup, dan nilai mode manual);
 *  - `columnType` field int (bawaan INT signed) dan field boolean (TINYINT) = tipe kolomnya.
 *
 * Tipe yang lebih lebar dari kolom membuat batas aplikasi tidak pernah tercapai: tambah entri saat `order` sudah di
 * batas kolom berujung error 1264 (422 generik tanpa `errors` di koneksi strict) atau terpotong diam-diam (koneksi
 * default strictOn=false, urutan kembar). `auditColumns` juga wajib sama dengan kolom audit tabelnya. Test generik: ikut
 * memeriksa setiap master baru grup DBV lain.
 *
 * @internal
 */
final class MasterConfigSchemaTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testOrderAndIntegerFieldTypesMatchDdl(): void
    {
        $checked = 0;

        foreach (service('masterRegistry')->all() as $key => $def) {
            if ($def->hasOrder) {
                $this->assertSame($def->orderColumnType, $this->columnType($def->table, MasterDefinition::ORDER_FIELD), "{$key}: orderColumnType vs kolom order");
                $checked++;
            }

            foreach ($def->fields as $field) {
                if ($field->type === MasterField::TYPE_INT) {
                    $this->assertSame($field->columnType ?? MasterField::DEFAULT_INT_COLUMN, $this->columnType($def->table, $field->name), "{$key}.{$field->name}: columnType");
                }

                if ($field->type === MasterField::TYPE_BOOLEAN) {
                    $this->assertSame('tinyint', $this->columnType($def->table, $field->name), "{$key}.{$field->name}: boolean = TINYINT");
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked);

        // Temuan QA CR-009 yang diperbaiki: order Batch 1 TINYINT, order wilayah INT UNSIGNED.
        $registry = service('masterRegistry');

        foreach (['agama', 'jenis-pegawai', 'jenis-status'] as $key) {
            $this->assertSame(127, $registry->get($key)->orderMax(), $key);
        }

        foreach (['provinsi', 'kabupaten-kota', 'kecamatan', 'kelurahan'] as $key) {
            $this->assertSame(4294967295, $registry->get($key)->orderMax(), $key);
        }
    }

    /**
     * Master ber-`order` TINYINT tanpa induk/lingkup: `order` terbesar 127 → tambah (MAX+1 = 128) ditolak 422 pada
     * `errors.order` sebelum INSERT, tanpa baris baru.
     */
    public function testTinyintOrderMastersRejectOverflowWith422OnOrder(): void
    {
        $this->asRole(Role::SUPER_ADMIN);
        $covered = [];

        foreach (self::masterFixtures() as $key => $fx) {
            $def = service('masterRegistry')->get($key);

            if (! $def->hasOrder || $def->isManualOrder() || $def->orderScopeColumns() !== [] || $def->orderColumnType !== 'tinyint') {
                continue;
            }

            $this->db->table($def->table)->where($def->primaryKey, $fx['existing'])->update([MasterDefinition::ORDER_FIELD => 127]);
            $before = $this->db->table($def->table)->countAllResults();

            $result = $this->sendJson('POST', "api/v1/master/{$key}", $fx['new']);
            $result->assertStatus(422);
            $this->assertSame(["Urutan {$def->label} sudah mencapai batas maksimal 127."], $this->json($result)['errors']['order'] ?? null, $key);
            $this->assertSame($before, $this->db->table($def->table)->countAllResults(), $key);

            $covered[] = $key;
        }

        foreach (['agama', 'jenis-pegawai', 'jenis-status'] as $key) {
            $this->assertContains($key, $covered);
        }
    }

    /**
     * `auditColumns` setiap master = kolom audit yang benar-benar ada di tabelnya (DBV-003: kursem tanpa `*_by`,
     * kantor dengan `created_by`). Kolom yang terlewat tidak pernah diisi aplikasi; kolom yang tidak ada membuat tulis
     * gagal. Master tanpa `*_by` tidak mengarang kolom itu di respons, dan aktornya tetap tercatat di audit_logs.
     */
    public function testAuditColumnsMatchDdlAndActorIsAlwaysAudited(): void
    {
        $audit = [
            MasterDefinition::AUDIT_CREATED_AT, MasterDefinition::AUDIT_CREATED_BY, MasterDefinition::AUDIT_UPDATED_AT,
            MasterDefinition::AUDIT_UPDATED_BY, MasterDefinition::AUDIT_DELETED_AT,
        ];

        foreach (service('masterRegistry')->all() as $key => $def) {
            foreach ($audit as $column) {
                $this->assertSame($this->columnExists($def->table, $column), $def->hasAudit($column), "{$key}.{$column}: auditColumns vs DDL");
            }
        }

        $this->asRole(Role::SUPER_ADMIN);
        $withoutBy = 0;

        foreach (self::masterFixtures() as $key => $fx) {
            $def = service('masterRegistry')->get($key);

            if ($def->hasAudit(MasterDefinition::AUDIT_CREATED_BY) || $def->hasAudit(MasterDefinition::AUDIT_UPDATED_BY)) {
                continue;
            }

            $result = $this->sendJson('POST', "api/v1/master/{$key}", $fx['new']);
            $result->assertStatus(201);
            $created = $this->json($result)['data'];

            $this->assertArrayNotHasKey(MasterDefinition::AUDIT_CREATED_BY, $created, $key);
            $this->assertArrayNotHasKey(MasterDefinition::AUDIT_UPDATED_BY, $created, $key);
            $this->seeInDatabase('audit_logs', [
                'entity'    => $def->table,
                'entity_id' => (string) $created[$def->primaryKey],
                'event'     => 'create',
                'nip_actor' => '198501012010011001',
            ]);
            $withoutBy++;
        }

        $this->assertGreaterThanOrEqual(2, $withoutBy, 'bidang-kursem & instansi-kursem tanpa *_by');
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray()['n'] > 0;
    }

    /**
     * COLUMN_TYPE ternormalisasi: huruf kecil, tanpa lebar tampilan (MariaDB/MySQL lama: `tinyint(4)`,
     * `int(10) unsigned`).
     */
    private function columnType(string $table, string $column): string
    {
        $row = $this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray();

        $this->assertNotNull($row, "kolom {$table}.{$column} tidak ada");

        return (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));
    }
}
