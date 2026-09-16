<?php

declare(strict_types=1);

namespace Tests\Database\Models;

use App\Interfaces\SyncsToSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Models\DummyRiwayatModel;

/**
 * F0-05 — snapshot TIDAK berubah sampai syncToActiveSnapshot() dipanggil eksplisit.
 *
 * @internal
 */
final class BaseSnapshotModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testModelImplementsContract(): void
    {
        $model = new DummyRiwayatModel($this->db);

        $this->assertInstanceOf(SyncsToSnapshot::class, $model);
        $this->assertSame('pegawai_dummy', $model->snapshotTable());
        $this->assertSame('nip', $model->snapshotKey());
    }

    public function testInsertUpdateDeleteRiwayatDoesNotTouchSnapshot(): void
    {
        $model = new DummyRiwayatModel($this->db);

        $id = $model->insert(['nip' => '199002152015022002', 'id_jabatan' => 10, 'tmt' => '2024-04-01', 'status' => 0]);
        $this->assertSame(0, $this->snapshotCount(), 'Insert riwayat tidak boleh membuat snapshot');

        $model->update($id, ['status' => 1, 'id_jabatan' => 11]);
        $this->assertSame(0, $this->snapshotCount(), 'Update riwayat (termasuk approve status) tidak boleh menyentuh snapshot');

        $model->delete($id);
        $this->assertSame(0, $this->snapshotCount(), 'Delete riwayat tidak boleh menyentuh snapshot');

        // Audit riwayat tetap jalan (BaseAuditableModel), tapi tidak ada audit untuk tabel snapshot.
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'pegawai_dummy')->countAllResults());
        $this->assertSame(3, $this->db->table('audit_logs')->where('entity', 'riwayat_dummy')->countAllResults());
    }

    public function testExplicitSyncCreatesSnapshotAndWritesManualAudit(): void
    {
        $model = new DummyRiwayatModel($this->db);
        $id    = $model->insert(['nip' => '199002152015022002', 'id_jabatan' => 10, 'tmt' => '2024-04-01', 'status' => 1]);

        $this->assertSame(0, $this->snapshotCount());

        $this->assertTrue($model->syncToActiveSnapshot($id));

        $this->seeInDatabase('pegawai_dummy', ['nip' => '199002152015022002', 'id_jabatan' => 10, 'tmt_jabatan' => '2024-04-01']);
        $this->assertSame(1, $this->snapshotCount());

        // Sync memakai raw builder -> audit ditulis manual (event create untuk row snapshot baru).
        $this->seeInDatabase('audit_logs', ['entity' => 'pegawai_dummy', 'entity_id' => '199002152015022002', 'event' => 'create']);
    }

    public function testSecondSyncUpdatesExistingSnapshotRow(): void
    {
        $model = new DummyRiwayatModel($this->db);
        $first = $model->insert(['nip' => '199002152015022002', 'id_jabatan' => 10, 'tmt' => '2024-04-01', 'status' => 1]);
        $model->syncToActiveSnapshot($first);

        $second = $model->insert(['nip' => '199002152015022002', 'id_jabatan' => 20, 'tmt' => '2025-10-01', 'status' => 1]);
        $this->seeInDatabase('pegawai_dummy', ['nip' => '199002152015022002', 'id_jabatan' => 10]);

        $model->syncToActiveSnapshot($second);

        $this->assertSame(1, $this->snapshotCount(), 'Satu row snapshot per nip');
        $this->seeInDatabase('pegawai_dummy', ['nip' => '199002152015022002', 'id_jabatan' => 20, 'tmt_jabatan' => '2025-10-01']);

        $log = $this->db->table('audit_logs')->where('entity', 'pegawai_dummy')->where('event', 'update')->get()->getRowArray();
        $this->assertNotNull($log);
        $before = json_decode((string) $log['before_json'], true, 512, JSON_THROW_ON_ERROR);
        $after  = json_decode((string) $log['after_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(10, (int) $before['id_jabatan']);
        $this->assertSame(20, (int) $after['id_jabatan']);
    }

    public function testSyncUnknownRiwayatThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DummyRiwayatModel($this->db))->syncToActiveSnapshot(999999);
    }

    private function snapshotCount(): int
    {
        return $this->db->table('pegawai_dummy')->countAllResults();
    }
}
