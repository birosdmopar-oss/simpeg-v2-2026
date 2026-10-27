<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.6.3, §8.1 (AC-D03.5): setiap tambah/ubah/hapus presensi manual menulis `audit_logs` `dh_online`
 * `{nip}:{tgl}` (`create`/`update`/`delete`) dengan before/after, pelaku = akun khusus; hapus = baris hilang dari
 * `dh_online` dan before audit memuat baris lengkap (pengganti trigger arsip `d_dh_online`).
 *
 * @internal
 */
final class PresensiManualAuditTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config(Presensi::class)->akunPresensiManual = [4676];
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->akunPresensiManual = [4675, 4676];
        parent::tearDown();
    }

    public function testAuditTambahUbahHapus(): void
    {
        $nip  = $this->buatPegawai();
        $auth = $this->authAdmin(4676);
        $id   = "{$nip}:2026-10-05";

        $this->manual()->tambah($auth, ['nip' => $nip, 'tgl' => '2026-10-05', 'jam_masuk' => '07:10']);
        $this->manual()->ubah($auth, $nip, '2026-10-05', ['jam_masuk' => '07:20', 'jam_pulang' => '16:05']);
        $this->manual()->hapus($auth, $nip, '2026-10-05');

        $rows = $this->db->table('audit_logs')->where(['entity' => 'dh_online', 'entity_id' => $id])->orderBy('id_log')->get()->getResultArray();
        $this->assertSame(['create', 'update', 'delete'], array_column($rows, 'event'));
        $this->assertSame(['4676', '4676', '4676'], array_map(static fn (array $r): string => (string) $r['id_pengguna_actor'], $rows));

        $create = json_decode((string) $rows[0]['after_json'], true);
        $this->assertSame(['07:10:00', 2], [$create['jam_masuk'], $create['sumber']]);
        $this->assertNull($rows[0]['before_json']);

        $before = json_decode((string) $rows[1]['before_json'], true);
        $after  = json_decode((string) $rows[1]['after_json'], true);
        $this->assertSame(['07:10:00', null], [$before['jam_masuk'], $before['jam_pulang']]);
        $this->assertSame(['07:20:00', '16:05:00'], [$after['jam_masuk'], $after['jam_pulang']]);

        $hapus = json_decode((string) $rows[2]['before_json'], true);
        $this->assertSame([$nip, '2026-10-05', '07:20:00', '16:05:00', '2'], [$hapus['nip'], $hapus['tgl'], $hapus['jam_masuk'], $hapus['jam_pulang'], (string) $hapus['sumber']], 'baris lengkap');
        $this->assertArrayHasKey('created_at', $hapus);
        $this->assertNull($rows[2]['after_json']);
        $this->assertNull($this->barisDh($nip, '2026-10-05'), 'hapus fisik');
    }
}
