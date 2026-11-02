<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-056 M1 — impor Tier 1 (master) dari skema legacy sintetis ({@see ImporLegacyTestCase}).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTier1Test extends ImporLegacyTestCase
{
    private function isiLegacyDasar(): void
    {
        $this->isiMasterDasar();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function impor(string $zona = 'WIB', ?Keputusan $k = null, bool $dryRun = false): array
    {
        return $this->jalankanImpor([1], $zona, $k, $dryRun);
    }

    public function testTier1MenyalinIdApaAdanyaDenganAturanUmum(): void
    {
        $this->isiLegacyDasar();
        $this->src->table('group_jabatan')->insertBatch([
            ['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1, 'order' => 1, 'created_at' => '2026-01-01 07:00:00'],
            ['id_group_jabatan' => 2, 'group_jabatan' => 'Fungsional', 'status' => 1, 'order' => 2, 'created_at' => '2026-01-01 07:00:00'],
        ]);
        // sub_group_jabatan legacy tidak punya kolom `order` [V2] (G-02 §2.4) → 1..n per id_group_jabatan.
        $this->src->query('ALTER TABLE sub_group_jabatan DROP COLUMN `order`');
        $this->src->table('sub_group_jabatan')->insertBatch([
            ['id_sub_group_jabatan' => 3, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'S1', 'need_satker' => 1, 'status' => 1, 'created_at' => '2026-01-01 07:00:00'],
            ['id_sub_group_jabatan' => 4, 'id_group_jabatan' => 2, 'sub_group_jabatan' => 'S2', 'need_satker' => 1, 'status' => 1, 'created_at' => '2026-01-01 07:00:00'],
            ['id_sub_group_jabatan' => 6, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'S3', 'need_satker' => 1, 'status' => 1, 'created_at' => '2026-01-01 07:00:00'],
        ]);
        $this->src->table('kelas_jabatan')->insert(['kelas_jabatan' => 7, 'tukin' => 1000, 'status' => 1, 'created_at' => '2026-01-01 07:00:00']);
        $this->src->table('jabatan')->insert([
            'id_jabatan'    => 100, 'id_group_jabatan' => 1, 'id_sub_group_jabatan' => 3, 'id_satker' => 9, 'id_jenjang_jf' => 0,
            'kelas_jabatan' => 7, 'jabatan' => 'Kepala', 'status' => 1, 'created_at' => '2026-01-01 07:00:00',
        ]);
        // Rujukan ke baris sesudahnya di tabel yang sama → kolom tunda.
        $this->src->table('jabatan_koordinasi')->insertBatch([
            ['id_jabatan_koordinasi' => 1, 'jenis' => 1, 'jabatan' => 'K1', 'id_atasan_es_3_koord' => 2, 'status' => 1, 'created_at' => '2026-01-01 07:00:00', 'updated_at' => '2026-01-01 07:00:00'],
            ['id_jabatan_koordinasi' => 2, 'jenis' => 1, 'jabatan' => 'K2', 'id_atasan_es_3_koord' => null, 'status' => 1, 'created_at' => '2026-01-01 07:00:00', 'updated_at' => '2026-01-01 07:00:00'],
        ]);
        $this->src->table('jenis_libur')->insert(['id_jenis_libur' => 1, 'jenis_libur' => 'Libur Nasional', 'order' => 1, 'status' => 1, 'created_at' => '2026-01-01 07:00:00']);
        // created_at TIMESTAMP di sumber (R1b): sudah UTC, tidak dikonversi.
        $this->src->query('ALTER TABLE hari_libur MODIFY created_at TIMESTAMP NULL');
        $this->src->table('hari_libur')->insert([
            'id_libur'   => 40, 'id_jenis_libur' => 1, 'tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17',
            'nama_libur' => 'HUT RI', 'status' => 1, 'created_at' => '2026-08-01 03:00:00',
        ]);
        $this->src->table('web_config')->insertBatch([
            ['id_web_config' => 11, 'config_name' => 'TL1/PSW1', 'config_value' => '0.5', 'updated_at' => '2026-01-01 07:00:00'],
            ['id_web_config' => 12, 'config_name' => 'kunci_tak_dikenal', 'config_value' => 'x', 'updated_at' => '2026-01-01 07:00:00'],
        ]);

        $lap = $this->impor();

        $unit = $this->db->table('unit')->where('id_unit', 5)->get()->getRowArray();
        $this->assertSame("Biro's Umum", $unit['unit'], 'stripslashes + normalisasi nama');
        $this->assertSame('2026-08-15 17:30:00', $unit['created_at'], 'R1a WIB → UTC');

        $this->assertNull($this->db->table('satker')->where('id_satker', 10)->get()->getRowArray()['id_unit'], 'rujukan 0 → NULL');
        $this->assertSame('2', (string) $this->db->table('satker')->where('id_satker', 10)->get()->getRowArray()['status']);

        $order = array_column($this->db->table('sub_group_jabatan')->orderBy('id_sub_group_jabatan')->get()->getResultArray(), 'order', 'id_sub_group_jabatan');
        $this->assertSame([3 => '1', 4 => '1', 6 => '2'], array_map('strval', $order));

        $this->assertNull($this->db->table('jabatan')->where('id_jabatan', 100)->get()->getRowArray()['id_jenjang_jf']);
        $koord = $this->db->table('jabatan_koordinasi')->where('id_jabatan_koordinasi', 1)->get()->getRowArray();
        $this->assertSame('2', (string) $koord['id_atasan_es_3_koord']);
        $this->assertSame('2026-01-01 00:00:00', $koord['updated_at'], 'UPDATE kolom tunda tidak memicu ON UPDATE CURRENT_TIMESTAMP');
        $this->assertSame('2026-08-01 03:00:00', $this->db->table('hari_libur')->where('id_libur', 40)->get()->getRowArray()['created_at']);

        $this->assertSame(6, $lap['jenis_cuti']['dilewati'], 'seed migration sama → dilewati (GAP-3)');
        $this->assertSame(0, $lap['jenis_cuti']['ditulis']);
        $this->assertSame(['TL1/PSW1'], array_column($this->db->table('web_config')->get()->getResultArray(), 'config_name'));
        $this->assertSame(['G-09 REVIEW' => 1], $lap['web_config']['dibuang']);
        $this->assertSame(3, $lap['sub_group_jabatan']['ditulis']);
    }

    public function testR1aZonaUtcTidakMengonversi(): void
    {
        $this->isiLegacyDasar();
        $this->impor('UTC');

        $this->assertSame('2026-08-16 00:30:00', $this->db->table('unit')->where('id_unit', 5)->get()->getRowArray()['created_at']);
    }

    public function testWaktuPerTierDicatatDanBatchMinimalSatu(): void
    {
        $this->isiLegacyDasar();

        $laporan = (new PengimporLegacy($this->src, $this->db, 'WIB', Keputusan::kosong(), 1))->jalankan([1], false);

        $this->assertSame([1], array_keys($laporan->waktu()));
        $this->assertStringStartsWith('Tier 1 selesai dalam ', $laporan->baris()[array_key_last($laporan->baris())]);
        $this->assertSame(2, $laporan->semua()['satker']['ditulis'], 'batch 1 baris tetap menyalin semua baris');

        $this->expectException(ImporGagal::class);
        new PengimporLegacy($this->src, $this->db, 'WIB', Keputusan::kosong(), 0);
    }

    public function testZonaSumberWajibDiisi(): void
    {
        $this->expectException(ImporGagal::class);
        new PengimporLegacy($this->src, $this->db, '', Keputusan::kosong());
    }

    public function testSeedBedaGagalDanTierDiRollback(): void
    {
        $this->isiLegacyDasar();
        $this->src->query("UPDATE jenis_cuti SET nama_jenis_cuti = 'Lain' WHERE id_jenis_cuti = 1");

        try {
            $this->impor();
            $this->fail('Seed berbeda harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('jenis_cuti id 1', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('unit')->countAllResults(), 'tier di-rollback');
    }

    public function testTujuanTidakKosongGagal(): void
    {
        $this->isiLegacyDasar();
        $this->db->table('unit')->insert(['id_unit' => 1, 'unit' => 'Sudah Ada', 'is_upt' => 0, 'order' => 1, 'status' => 1]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Tabel tujuan unit tidak kosong');
        $this->impor();
    }

    public function testStatusLuarDomainGagalLaluDipetakanKeputusan(): void
    {
        $this->isiLegacyDasar();
        $this->src->query('UPDATE unit SET status = 3 WHERE id_unit = 5');

        try {
            $this->impor();
            $this->fail('Status 3 tanpa keputusan harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString("Status unit '3'", $e->getMessage());
        }

        $this->impor(k: Keputusan::dariArray(['status' => ['unit' => ['3' => 2]]]));
        $this->assertSame('2', (string) $this->db->table('unit')->where('id_unit', 5)->get()->getRowArray()['status']);
    }

    public function testUnitIsUpt2DipetakanKe0SesuaiG02(): void
    {
        $this->isiLegacyDasar();
        $this->lepasCheck('unit');
        $this->src->table('unit')->insert(['id_unit' => 6, 'unit' => 'Unit UPT', 'is_upt' => 2, 'order' => 2, 'status' => 1, 'created_at' => '2026-01-01 07:00:00']);

        $lap = $this->impor();

        $isUpt = array_column($this->db->table('unit')->orderBy('id_unit')->get()->getResultArray(), 'is_upt', 'id_unit');
        $this->assertSame([5 => '0', 6 => '0'], array_map('strval', $isUpt));
        $this->assertSame(['G-02 #9 is_upt 2→0' => 1], $lap['unit']['koreksi']);
    }

    public function testIsUptLuarDomainGagalSaatDryRunLaluDipetakanKeputusan(): void
    {
        $this->isiLegacyDasar();
        $this->lepasCheck('unit');
        $this->lepasCheck('satker');
        $this->src->query('UPDATE unit SET is_upt = 3 WHERE id_unit = 5');
        $this->src->query('UPDATE satker SET is_upt = 2 WHERE id_satker = 9');

        foreach (["Nilai unit.is_upt '3'", "Nilai satker.is_upt '2'"] as $i => $pesan) {
            try {
                $this->impor(dryRun: true);
                $this->fail("{$pesan} harus gagal saat dry-run (sebelum CHECK MySQL).");
            } catch (ImporGagal $e) {
                $this->assertStringContainsString($pesan, $e->getMessage());
            }

            if ($i === 0) {
                $this->src->query('UPDATE unit SET is_upt = 1 WHERE id_unit = 5');
            }
        }

        $this->impor(k: Keputusan::dariArray(['status' => ['satker.is_upt' => ['2' => 0]]]));
        $this->assertSame('0', (string) $this->db->table('satker')->where('id_satker', 9)->get()->getRowArray()['is_upt']);
        $this->assertSame('1', (string) $this->db->table('unit')->where('id_unit', 5)->get()->getRowArray()['is_upt']);
    }

    public function testTanggalNolNotNullButuhKeputusan(): void
    {
        $this->isiLegacyDasar();
        $this->src->query("UPDATE unit SET created_at = '0000-00-00 00:00:00', updated_at = '0000-00-00 00:00:00' WHERE id_unit = 5");

        try {
            $this->impor();
            $this->fail('Tanggal nol NOT NULL tanpa keputusan harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('unit.created_at', $e->getMessage());
        }

        $this->impor(k: Keputusan::dariArray(['tanggal_nol' => ['unit.created_at' => '2020-01-01 00:00:00']]));
        $unit = $this->db->table('unit')->where('id_unit', 5)->get()->getRowArray();
        $this->assertSame('2020-01-01 00:00:00', $unit['created_at']);
        $this->assertNull($unit['updated_at'], 'kolom nullable → NULL');
    }

    public function testNilaiNullDipetakanKeputusan(): void
    {
        $this->isiLegacyDasar();
        $this->src->table('jenis_libur')->insert(['id_jenis_libur' => 1, 'jenis_libur' => 'Libur Nasional', 'order' => 1, 'status' => 1]);
        $this->src->table('hari_libur')->insert(['id_libur' => 41, 'id_jenis_libur' => null, 'tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17', 'nama_libur' => 'X', 'status' => 1]);

        $this->impor(k: Keputusan::dariArray(['nilai' => ['hari_libur.id_jenis_libur' => ['null' => 1]]]));

        $this->assertSame('1', (string) $this->db->table('hari_libur')->where('id_libur', 41)->get()->getRowArray()['id_jenis_libur']);
    }

    public function testBentrokUniqueSesudahNormalisasiTerdeteksiDiDryRunDanCommit(): void
    {
        $this->isiLegacyDasar();
        // Beda spasi tengah → identik sesudah normalisasi nama → UNIQUE uq_jenis_pegawai_nama [V2] bentrok.
        $this->src->table('jenis_pegawai')->insertBatch([
            ['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS Pusat', 'order' => 1, 'status' => 1],
            ['id_jenis_pegawai' => 2, 'jenis_pegawai' => 'PNS  Pusat', 'order' => 2, 'status' => 1],
        ]);

        foreach ([true, false] as $dryRun) {
            try {
                $this->impor(dryRun: $dryRun);
                $this->fail('Bentrok UNIQUE harus gagal (dry-run=' . var_export($dryRun, true) . ').');
            } catch (ImporGagal $e) {
                $this->assertStringContainsString('Bentrok UNIQUE jenis_pegawai.uq_jenis_pegawai_nama', $e->getMessage());
                $this->assertStringNotContainsString('Pusat', $e->getMessage(), 'pesan tanpa nilai baris');
            }
        }

        $this->assertSame(0, $this->db->table('unit')->countAllResults(), 'tier di-rollback');
    }

    public function testDryRunTidakMenulis(): void
    {
        $this->isiLegacyDasar();

        $lap = $this->impor(dryRun: true);

        $this->assertSame(1, $lap['unit']['ditulis']);
        $this->assertSame(0, $this->db->table('unit')->countAllResults());
    }

    public function testTabelSumberWajibTidakAdaGagal(): void
    {
        $this->src->query('DROP TABLE unit');

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Tabel sumber unit tidak ada');
        $this->impor();
    }

    public function testTabelOpsionalBolehTidakAda(): void
    {
        $this->isiLegacyDasar();
        $this->src->query('DROP TABLE jenjang_jf');

        $this->assertSame('tidak ada di sumber (opsional)', $this->impor()['jenjang_jf']['catatan']);
    }
}
