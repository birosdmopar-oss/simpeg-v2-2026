<?php

declare(strict_types=1);

namespace Tests\Database\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Models\DummyAuditableModel;
use Tests\Support\Models\DummySoftDeleteModel;

/**
 * F0-04 — create/update/delete otomatis menulis audit_logs dengan before/after JSON.
 *
 * @internal
 */
final class BaseAuditableModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        service('authContext')->setClaims(['sub' => '198501012010011001', 'role' => 1]);
    }

    protected function tearDown(): void
    {
        service('authContext')->clear();
        parent::tearDown();
    }

    public function testCreateWritesAuditWithAfterJson(): void
    {
        $model = new DummyAuditableModel($this->db);
        $id    = $model->insert(['name' => 'Kursi', 'qty' => 2]);

        $this->assertIsInt($id);

        $log = $this->fetchLogs('dummy_items', (string) $id, 'create');
        $this->assertCount(1, $log);
        $this->assertNull($log[0]['before_json']);
        $this->assertSame('198501012010011001', $log[0]['nip_actor']);

        $after = json_decode((string) $log[0]['after_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Kursi', $after['name']);
        $this->assertSame(2, (int) $after['qty']);
        $this->assertSame($id, (int) $after['id']);
    }

    public function testUpdateWritesAuditWithBeforeAndAfterJson(): void
    {
        $model = new DummyAuditableModel($this->db);
        $id    = $model->insert(['name' => 'Meja', 'qty' => 1]);

        $this->assertTrue($model->update($id, ['qty' => 5]));

        $log = $this->fetchLogs('dummy_items', (string) $id, 'update');
        $this->assertCount(1, $log);

        $before = json_decode((string) $log[0]['before_json'], true, 512, JSON_THROW_ON_ERROR);
        $after  = json_decode((string) $log[0]['after_json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, (int) $before['qty']);
        $this->assertSame(5, (int) $after['qty']);
        $this->assertSame('Meja', $after['name']);
    }

    public function testHardDeleteWritesAuditWithBeforeJsonAndNullAfter(): void
    {
        $model = new DummyAuditableModel($this->db);
        $id    = $model->insert(['name' => 'Lemari', 'qty' => 3]);

        $this->assertTrue($model->delete($id));
        $this->dontSeeInDatabase('dummy_items', ['id' => $id]);

        $log = $this->fetchLogs('dummy_items', (string) $id, 'delete');
        $this->assertCount(1, $log);

        $before = json_decode((string) $log[0]['before_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Lemari', $before['name']);
        $this->assertNull($log[0]['after_json']);
    }

    public function testSoftDeleteWritesAuditWithDeletedAtInAfterJson(): void
    {
        $model = new DummySoftDeleteModel($this->db);
        $id    = $model->insert(['name' => 'Rak', 'qty' => 4]);

        $this->assertTrue($model->delete($id));
        $this->seeInDatabase('dummy_items', ['id' => $id]);

        $log = $this->fetchLogs('dummy_items', (string) $id, 'delete');
        $this->assertCount(1, $log);

        $before = json_decode((string) $log[0]['before_json'], true, 512, JSON_THROW_ON_ERROR);
        $after  = json_decode((string) $log[0]['after_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($before['deleted_at']);
        $this->assertNotNull($after['deleted_at']);
    }

    public function testAllThreeEventsProduceExactlyThreeRows(): void
    {
        $model = new DummyAuditableModel($this->db);
        $id    = $model->insert(['name' => 'Sofa', 'qty' => 1]);
        $model->update($id, ['qty' => 2]);
        $model->delete($id);

        $rows = $this->db->table('audit_logs')->where('entity', 'dummy_items')->where('entity_id', (string) $id)->orderBy('id_log')->get()->getResultArray();
        $this->assertSame(['create', 'update', 'delete'], array_column($rows, 'event'));
    }

    public function testActorIsNullWhenNoAuthenticatedUser(): void
    {
        service('authContext')->clear();

        $model = new DummyAuditableModel($this->db);
        $id    = $model->insert(['name' => 'CLI', 'qty' => 0]);

        $log = $this->fetchLogs('dummy_items', (string) $id, 'create');
        $this->assertNull($log[0]['nip_actor']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLogs(string $entity, string $entityId, string $event): array
    {
        return $this->db->table('audit_logs')
            ->where('entity', $entity)
            ->where('entity_id', $entityId)
            ->where('event', $event)
            ->get()
            ->getResultArray();
    }
}
