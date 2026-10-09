<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\SnapshotSync;
use App\Models\AuditLogModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\Definisi\ContohKarpeg;
use Tests\Support\Kepegawaian\Riwayat\UjiKp;
use Tests\Support\Kepegawaian\Riwayat\UjiPendidikan;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * WS-1 M1 (MAKE-004) — SnapshotSync ber-DB: multi-target, pilih ulang, DELETE bila kosong, audit manual, transaksi.
 *
 * @internal
 */
final class SnapshotSyncTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private UjiKp $kp;
    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kp  = new UjiKp();
        $this->nip = $this->buatPegawai();

        $this->siapkanMasterKp();
    }

    public function testMultiTargetKpCpnsPns(): void
    {
        $cpns = $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(1, '2010-03-01') + ['status' => 1]);
        $pns  = $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(2, '2011-04-01') + ['status' => 1]);
        $reg  = $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(3, '2015-04-01') + ['status' => 1]);
        $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(6, '2020-10-01') + ['status' => 1]);
        $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(3, '2024-04-01') + ['status' => 0]);

        (new SnapshotSync())->sinkronkan($this->kp, $this->nip);

        $this->assertSame((string) $reg, (string) $this->snapshotUji('pegawai_kp', $this->nip)['id_riwayat_kp']);
        $this->assertSame((string) $cpns, (string) $this->snapshotUji('pegawai_cpns', $this->nip)['id_riwayat_kp']);
        $this->assertSame((string) $pns, (string) $this->snapshotUji('pegawai_pns', $this->nip)['id_riwayat_kp']);
        $this->assertSame('2015-04-01', $this->snapshotUji('pegawai_kp', $this->nip)['tmtsk']);
    }

    public function testPilihUlangDanHapusSnapshotBilaTidakAdaBaris(): void
    {
        $lama = $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(3, '2015-04-01') + ['status' => 1]);
        $baru = $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(3, '2019-04-01') + ['status' => 1]);
        $sync = new SnapshotSync();
        $sync->sinkronkan($this->kp, $this->nip);
        $this->assertSame((string) $baru, (string) $this->snapshotUji('pegawai_kp', $this->nip)['id_riwayat_kp']);

        // Baris aktif keluar dari status 1 (ditolak/dihapus) → snapshot dihitung ulang, bukan dibiarkan.
        $this->db->table('riwayat_kp')->where('id_riwayat_kp', $baru)->update(['status' => 10]);
        $sync->sinkronkan($this->kp, $this->nip);
        $this->assertSame((string) $lama, (string) $this->snapshotUji('pegawai_kp', $this->nip)['id_riwayat_kp']);

        $this->db->table('riwayat_kp')->where('id_riwayat_kp', $lama)->update(['status' => 2]);
        $sync->sinkronkan($this->kp, $this->nip);
        $this->assertNull($this->snapshotUji('pegawai_kp', $this->nip));
        $this->assertNull($this->snapshotUji('pegawai_cpns', $this->nip));
    }

    public function testAuditManualCreateUpdateDeleteDenganPelaku(): void
    {
        [, $auth, $akun] = $this->aktor(Role::SUPER_ADMIN);
        $id              = $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(3, '2015-04-01') + ['status' => 1]);
        $sync            = new SnapshotSync();

        $sync->sinkronkan($this->kp, $this->nip, $auth);
        $this->db->table('riwayat_kp')->where('id_riwayat_kp', $id)->update(['tmtsk' => '2016-04-01']);
        $sync->sinkronkan($this->kp, $this->nip, $auth);
        // Tanpa perubahan → tidak menulis dan tidak menambah audit.
        $sync->sinkronkan($this->kp, $this->nip, $auth);
        $this->db->table('riwayat_kp')->where('id_riwayat_kp', $id)->update(['status' => 10]);
        $sync->sinkronkan($this->kp, $this->nip, $auth);

        $audit = $this->auditUji('pegawai_kp', $this->nip);
        $this->assertSame([AuditLogModel::EVENT_CREATE, AuditLogModel::EVENT_UPDATE, AuditLogModel::EVENT_DELETE], array_column($audit, 'event'));
        $this->assertSame((string) $akun['id_pengguna'], (string) $audit[0]['id_pengguna_actor']);
        $this->assertNull($audit[0]['before_json']);
        $this->assertSame('2016-04-01', json_decode((string) $audit[1]['after_json'], true)['tmtsk']);
        $this->assertNull($audit[2]['after_json']);
    }

    public function testJoinUrutanJenjangTertinggi(): void
    {
        $d  = new UjiPendidikan();
        $s1 = $this->buatJenjang('S1', 7);
        $s2 = $this->buatJenjang('S2', 8);
        $this->sisipRiwayat($d, $this->nip, ['id_jenjang_pendidikan' => $s1, 'tgl_lulus' => '2020-08-30', 'status' => 1]);
        $tertinggi = $this->sisipRiwayat($d, $this->nip, ['id_jenjang_pendidikan' => $s2, 'tgl_lulus' => '2015-08-30', 'status' => 1]);

        (new SnapshotSync())->sinkronkan($d, $this->nip);

        $snap = $this->snapshotUji('pegawai_pendidikan', $this->nip);
        $this->assertSame((string) $tertinggi, (string) $snap['id_riwayat_pendidikan']);
        $this->assertSame((string) $s2, (string) $snap['id_jenjang_pendidikan']);
    }

    public function testDefinisiTanpaSnapshotTidakMenyentuhApaPun(): void
    {
        $sebelum = $this->db->table('audit_logs')->countAllResults();

        (new SnapshotSync())->sinkronkan(new ContohKarpeg(), $this->nip);

        $this->assertSame($sebelum, $this->db->table('audit_logs')->countAllResults());
    }

    public function testDiLuarTransaksiPemanggilTetapAtomik(): void
    {
        $this->sisipRiwayat($this->kp, $this->nip, $this->barisKp(1, '2010-03-01') + ['status' => 1]);
        $this->assertSame(0, $this->db->transDepth);

        (new SnapshotSync())->sinkronkan($this->kp, $this->nip);

        $this->assertSame(0, $this->db->transDepth, 'transaksi sendiri ditutup');
        $this->assertNotNull($this->snapshotUji('pegawai_kp', $this->nip));
        $this->assertNotNull($this->snapshotUji('pegawai_cpns', $this->nip));
    }
}
