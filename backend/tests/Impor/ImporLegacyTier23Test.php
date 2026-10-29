<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-056 M2 — Tier 2 (pegawai) dan Tier 3 (riwayat jabatan/KP, snapshot, Plt/Plh). NIP sintetis berawalan nol.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTier23Test extends ImporLegacyTestCase
{
    private const NIP_A = '000000000000000001';
    private const NIP_B = '000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->isiMasterDasar();
        $this->src->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'order' => 1, 'status' => 1]);
        $this->src->table('pegawai')->insertBatch([
            [
                'nip'        => ' ' . self::NIP_A . ' ', 'nama' => "Pegawai Uji\\'A", 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1,
                'id_agama'   => 0, 'id_provinsi_lahir' => '99', 'jenis_kelamin' => 1, 'flag_update' => null, 'status' => 1,
                'created_at' => '2026-01-01 07:00:00',
            ],
            [
                'nip'        => self::NIP_B, 'nama' => 'Pegawai Uji B', 'tgl_lahir' => '1991-02-02', 'id_jenis_pegawai' => 1,
                'id_agama'   => null, 'id_provinsi_lahir' => null, 'jenis_kelamin' => 2, 'flag_update' => 0, 'status' => 1,
                'created_at' => '2026-01-01 07:00:00',
            ],
        ]);
        $this->src->table('riwayat_mutasi_jabatan')->insert([
            'id_riwayat_mutasi_jabatan' => 50, 'nip' => self::NIP_A, 'id_unit' => 5, 'id_satker' => 0, 'tmtsk' => '2024-01-01', 'status' => 3,
        ]);
        $this->src->table('riwayat_kp')->insert(['id_riwayat_kp' => 60, 'nip' => self::NIP_A, 'tmtsk' => '2024-04-01', 'status' => 1]);
        $this->src->table('pegawai_mutasi_jabatan')->insertBatch([
            ['nip' => self::NIP_A, 'id_riwayat_mutasi_jabatan' => 50, 'id_unit' => 5, 'tmtsk' => '2024-01-01'],
            ['nip' => self::NIP_B, 'id_riwayat_mutasi_jabatan' => 999, 'id_unit' => 5, 'tmtsk' => '2024-01-01'],
        ]);
        $this->src->table('pegawai_kp')->insert([
            'nip' => self::NIP_A, 'id_riwayat_kp' => 60, 'tmtsk' => '2024-04-01', 'tgl_sk' => '2024-03-01', 'jenis_kp' => 'Reguler',
            'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda',
        ]);
        $this->src->table('pegawai_plt')->insert(['id_pegawai_plt' => 7, 'nip_plt' => self::NIP_B, 'id_unit' => 5, 'status' => 1]);
    }

    public function testTier2Tier3DenganAturanPegawaiDanSnapshot(): void
    {
        $lap = $this->jalankanImpor([1, 2, 3]);

        $a = $this->db->table('pegawai')->where('nip', self::NIP_A)->get()->getRowArray();
        $this->assertNotNull($a, 'NIP di-trim (kolom kode)');
        $this->assertSame("Pegawai Uji'A", $a['nama']);
        $this->assertNull($a['id_agama'], 'id 0 → NULL');
        $this->assertSame('99', $a['id_provinsi_lahir'], 'rujukan sentinel wilayah');
        $this->assertSame('0', (string) $a['flag_update']);
        $this->assertSame('2026-01-01 00:00:00', $a['created_at']);

        $this->assertSame('3', (string) $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', 50)->get()->getRowArray()['status'], 'status 3 sah untuk riwayat');
        $this->assertNull($this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', 50)->get()->getRowArray()['id_satker']);

        $snap = array_column($this->db->table('pegawai_mutasi_jabatan')->get()->getResultArray(), 'id_riwayat_mutasi_jabatan', 'nip');
        $this->assertSame('50', (string) $snap[self::NIP_A]);
        $this->assertNull($snap[self::NIP_B], 'id_riwayat_* yatim di snapshot → NULL');
        $this->assertSame(['id_riwayat_mutasi_jabatan yatim→NULL' => 1], $lap['pegawai_mutasi_jabatan']['koreksi']);
        $this->assertSame(2, $lap['pegawai_mutasi_jabatan']['ditulis']);

        $this->assertSame('60', (string) $this->db->table('pegawai_kp')->where('nip', self::NIP_A)->get()->getRowArray()['id_riwayat_kp']);
        $this->assertSame(1, $this->db->table('pegawai_plt')->countAllResults());
    }

    public function testRujukanYatimSelainSnapshotGagalDanHanyaTierItuDiRollback(): void
    {
        $this->src->query('UPDATE pegawai_plt SET id_jabatan = 777');

        try {
            $this->jalankanImpor([1, 2, 3]);
            $this->fail('Rujukan yatim harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertSame('Rujukan yatim pegawai_plt.id_jabatan: 1 nilai tidak ada di tabel induk (audit DBV-019).', $e->getMessage());
        }

        $this->assertSame(2, $this->db->table('pegawai')->countAllResults(), 'tier 2 sudah commit');
        $this->assertSame(0, $this->db->table('riwayat_mutasi_jabatan')->countAllResults(), 'tier 3 di-rollback');
    }

    public function testDomainJenisKelaminDiLuarDomainGagalLaluDipetakan(): void
    {
        $this->lepasCheck('pegawai');
        $this->src->query("UPDATE pegawai SET jenis_kelamin = 3 WHERE nip = '" . self::NIP_B . "'");
        $this->jalankanImpor([1]);

        try {
            $this->jalankanImpor([2]);
            $this->fail('jenis_kelamin 3 tanpa keputusan harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString("Nilai pegawai.jenis_kelamin '3'", $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('pegawai')->countAllResults(), 'tier 2 di-rollback');

        $this->jalankanImpor([2], k: Keputusan::dariArray(['status' => ['pegawai.jenis_kelamin' => ['3' => 2]]]));
        $this->assertSame('2', (string) $this->db->table('pegawai')->where('nip', self::NIP_B)->get()->getRowArray()['jenis_kelamin']);
    }

    public function testNipMelebihiPanjangKolomGagal(): void
    {
        // Legacy V4: kolom NIP VARCHAR(50) (B-01 §6 #2); v2 VARCHAR(30).
        $this->src->query('ALTER TABLE pegawai MODIFY nip VARCHAR(50) NOT NULL');
        $this->src->query("UPDATE pegawai SET nip = '" . str_repeat('0', 31) . "' WHERE nip = '" . self::NIP_B . "'");

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Panjang pegawai.nip melebihi 30 karakter.');
        $this->jalankanImpor([1, 2]);
    }
}
