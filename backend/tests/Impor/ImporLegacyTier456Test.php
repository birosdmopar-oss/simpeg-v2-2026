<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\Keputusan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-056 M3 — Tier 4 (izin, keluarga, SKP/BKN), Tier 5 (lokasi + `dh_online`), Tier 6 (cuti/TB/sisa cuti) dan
 * salinan counter AUTO_INCREMENT. NIP sintetis berawalan nol.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTier456Test extends ImporLegacyTestCase
{
    private const NIP_A = '000000000000000001';
    private const NIP_B = '000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->isiMasterDasar();
        $this->src->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'order' => 1, 'status' => 1]);
        $this->src->table('jenjang_pendidikan')->insert(['id_jenjang_pendidikan' => 1, 'jenjang_pendidikan' => 'Strata 2', 'jenjang_pendidikan_singkat' => 'S2', 'status' => 1]);
        $this->src->table('pegawai')->insertBatch([
            ['nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 1, 'status' => 1],
            ['nip' => self::NIP_B, 'nama' => 'Pegawai Uji B', 'tgl_lahir' => '1991-02-02', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 2, 'status' => 1],
        ]);
    }

    public function testTier4IzinWaktuBisnisDanStatusLama(): void
    {
        $this->lepasCheck('absen_ijin');
        // Legacy belum punya affect_tukin [V2] → DEFAULT 1 (DBV-011 §2.4 #5).
        $this->src->query('ALTER TABLE absen_ijin DROP COLUMN affect_tukin');
        $this->src->table('absen_ijin')->insert([
            'id'     => 70, 'nip' => self::NIP_A, 'date_start' => '2026-08-17 00:00:00', 'date_end' => '2026-08-18 23:59:59', 'kategori' => 1,
            'alasan' => "Dinas \\'luar\\'", 'status' => 'H', 'date_created' => '2026-08-16 08:00:00',
        ]);
        $this->src->table('riwayat_keluarga')->insert(['id_riwayat_keluarga' => 80, 'nip' => self::NIP_A, 'tgl_perkawinan' => '2015-05-05', 'nama_pasangan' => 'Pasangan Uji', 'status' => 1]);
        $this->src->table('detail_anak')->insert(['id_detail_anak' => 81, 'id_riwayat_keluarga' => 80, 'tgl_lahir' => '2016-06-06', 'nama' => 'Anak Uji']);
        $this->src->table('bkn_periode_ekinper')->insert(['id' => 'P-2026-08', 'nama' => 'Agustus', 'tahun' => 2026, 'periode_awal' => '2026-08-01', 'periode_akhir' => '2026-08-31', 'status' => 1]);
        $this->src->table('riwayat_skp_periodik')->insert(['id_riwayat_skp_periodik' => 90, 'id_pns' => 'X1', 'nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'status' => 1]);

        $this->jalankanImpor([1, 2, 3, 4], k: Keputusan::dariArray(['status' => ['absen_ijin' => ['H' => 'V']]]));

        $izin = $this->db->table('absen_ijin')->where('id', 70)->get()->getRowArray();
        $this->assertSame('V', $izin['status'], 'status lama dipetakan berkas keputusan');
        $this->assertSame('2026-08-17 00:00:00', $izin['date_start'], 'waktu bisnis tidak dikonversi');
        $this->assertSame('2026-08-18 23:59:59', $izin['date_end']);
        $this->assertSame('2026-08-16 01:00:00', $izin['date_created'], 'stempel dikonversi R1a');
        $this->assertSame('1', (string) $izin['affect_tukin']);
        $this->assertSame("Dinas 'luar'", $izin['alasan']);
        $this->assertSame(1, $this->db->table('detail_anak')->countAllResults());
        $this->assertSame(1, $this->db->table('bkn_periode_ekinper')->countAllResults());
        $this->assertSame(1, $this->db->table('riwayat_skp_periodik')->countAllResults());
    }

    public function testTier5DhOnlineTanpaJamDibuangDanPkKomposit(): void
    {
        $this->lepasCheck('dh_online');
        $this->src->table('lokasi_presensi')->insert(['id_lokasi_presensi' => 3, 'nama_lokasi' => 'Kantor  Pusat', 'latitude' => '-6.1', 'longitude' => '106.8', 'status' => 1]);
        $this->src->table('dh_online')->insertBatch([
            ['nip' => self::NIP_A, 'tgl' => '2026-08-17', 'jam_masuk' => '07:30:00', 'jam_pulang' => '16:00:00', 'created_at' => '2026-08-17 07:30:00'],
            ['nip' => self::NIP_A, 'tgl' => '2026-08-18', 'jam_masuk' => null, 'jam_pulang' => null, 'created_at' => '2026-08-18 07:30:00'],
            ['nip' => self::NIP_A, 'tgl' => '2026-08-19', 'jam_masuk' => '07:31:00', 'jam_pulang' => null, 'created_at' => '2026-08-19 07:31:00'],
            ['nip' => self::NIP_B, 'tgl' => '2026-08-17', 'jam_masuk' => null, 'jam_pulang' => '16:05:00', 'created_at' => '2026-08-17 16:05:00'],
        ]);

        $lap = $this->jalankanImpor([1, 2, 3, 4, 5]);

        $this->assertSame(['DBV-023 A2 tanpa jam' => 1], $lap['dh_online']['dibuang']);
        $this->assertSame(3, $lap['dh_online']['ditulis'], 'batch 2 baris melewati PK komposit (nip, tgl) tanpa lompat');
        $baris = $this->db->table('dh_online')->where('nip', self::NIP_A)->where('tgl', '2026-08-17')->get()->getRowArray();
        $this->assertSame('07:30:00', $baris['jam_masuk'], 'TIME tidak dikonversi (R2)');
        $this->assertSame('2026-08-17 00:30:00', $baris['created_at']);
        $this->assertNull($baris['sumber'], 'sumber NULL = baris impor (DBV-023 C-4)');
        $this->assertSame('Kantor Pusat', $this->db->table('lokasi_presensi')->get()->getRowArray()['nama_lokasi']);
    }

    public function testTier6SisaCutiDedupeDanSaldoNegatif(): void
    {
        $this->lepasCheck('pegawai_sisa_cuti');
        $this->src->query('ALTER TABLE pegawai_sisa_cuti DROP INDEX nip_tahun');
        $this->src->table('pegawai_sisa_cuti')->insertBatch([
            ['id_pegawai_sisa_cuti' => 11, 'nip' => self::NIP_A, 'tahun' => 2026, 'sisa_cuti' => 12, 'utang_cuti' => -3],
            ['id_pegawai_sisa_cuti' => 12, 'nip' => self::NIP_A, 'tahun' => 2026, 'sisa_cuti' => 5, 'utang_cuti' => 0],
            ['id_pegawai_sisa_cuti' => 13, 'nip' => self::NIP_B, 'tahun' => 2026, 'sisa_cuti' => 10, 'utang_cuti' => 0],
        ]);
        $this->src->table('riwayat_cuti')->insert([
            'id_riwayat_cuti' => 20, 'nip' => self::NIP_A, 'id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'tgl_mulai' => '2026-08-10',
            'tgl_akhir'       => '2026-08-20', 'lama_cuti' => 9, 'status' => 1,
        ]);
        $this->src->table('riwayat_tb')->insert([
            'id_riwayat_tb'              => 30, 'nip' => self::NIP_B, 'id_jenjang_pendidikan' => 1, 'tgl_mulai' => '2025-09-01', 'tgl_akhir' => '2027-08-31',
            'jenjang_pendidikan_singkat' => 'S2', 'institusi_pendidikan' => 'Univ Uji', 'jurusan_pendidikan' => 'Uji', 'dalam_negeri' => 1,
            'lokasi_pendidikan'          => 'Kota Uji', 'lembaga_yang_mendanai' => 'Uji', 'status' => 1,
        ]);
        $this->src->table('riwayat_tb_perpan')->insert(['id_riwayat_tb_perpan' => 31, 'id_riwayat_tb' => 30, 'tgl_mulai_perpan' => '2027-09-01', 'tgl_akhir_perpan' => '2028-02-28', 'file_sperpan' => 'x.pdf']);

        $lap = $this->jalankanImpor([1, 2, 3, 4, 5, 6]);

        $sisa = array_column($this->db->table('pegawai_sisa_cuti')->orderBy('id_pegawai_sisa_cuti')->get()->getResultArray(), null, 'id_pegawai_sisa_cuti');
        $this->assertSame([11, 13], array_map('intval', array_keys($sisa)), 'duplikat (nip, tahun) → id terkecil dipertahankan');
        $this->assertSame('0', (string) $sisa[11]['utang_cuti']);
        $this->assertSame(['DBV-022 §13.1 duplikat (nip, tahun)' => 1], $lap['pegawai_sisa_cuti']['dibuang']);
        $this->assertSame(1, $lap['pegawai_sisa_cuti']['koreksi']['DBV-022 §13.1 utang_cuti negatif→0']);
        $this->assertSame(1, $this->db->table('riwayat_cuti')->countAllResults());
        $this->assertSame(1, $this->db->table('riwayat_tb_perpan')->countAllResults());
    }

    public function testCounterAutoIncrementDisalinDanTinyintDiperiksa(): void
    {
        $this->src->table('riwayat_cuti')->insert([
            'id_riwayat_cuti' => 20, 'nip' => self::NIP_A, 'id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'tgl_mulai' => '2026-08-10',
            'tgl_akhir'       => '2026-08-20', 'lama_cuti' => 9, 'status' => 1,
        ]);
        // Baris yang pernah dihapus keras di legacy: counter melebihi MAX(id)+1 (DBV-011 §2.7).
        $this->src->query('ALTER TABLE riwayat_cuti AUTO_INCREMENT = 500');
        $this->src->query('ALTER TABLE jenis_pegawai AUTO_INCREMENT = 128');

        $lap = $this->jalankanImpor([1, 2, 3, 4, 5, 6]);

        if (! str_contains(strtolower($this->db->getVersion()), 'mariadb')) {
            $this->db->query('SET SESSION information_schema_stats_expiry = 0');
        }

        $ai = $this->db->query(
            'SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('riwayat_cuti')],
        )->getRowArray();
        $this->assertSame(500, (int) $ai['n']);
        $this->assertArrayHasKey('counter AUTO_INCREMENT 21→500', $lap['riwayat_cuti']['koreksi']);
        $this->assertArrayHasKey('PERIKSA counter TINYINT 128 tidak disalin', $lap['jenis_pegawai']['koreksi']);
    }
}
