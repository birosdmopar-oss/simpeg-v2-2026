<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\CreateDiklatHukdisKonketTandaJasa;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LepasMigrationKepegawaianTrait;

/**
 * DBV-005 — skema G-06 hasil migration 2026-09-25-120000_CreateDiklatHukdisKonketTandaJasa harus sama dengan skema
 * yang diajukan ke DB Validator (backend/docs/db-review/G-06-diklat-hukdis-konket-tanda-jasa-schema.md Bagian 2):
 * DDL legacy `diklat` simpeg_prod.sql:443-452 [K], empat tabel lain [I], plus deviasi v2 (status 10, UNIQUE nama &
 * `old_id`, FK RESTRICT, 3 CHECK, `affect_tukin` & `masa_sanksi_bulan` keputusan K5). Migration tidak menulis baris
 * apa pun dan bisa di-rollback.
 *
 * Migration B-01/B-02 (DBV-012/013) merujuk tabel master ini dengan FK RESTRICT, sehingga down() master di tengah
 * test ditolak MySQL selama migration itu terpasang; setUp() melepasnya dan tearDown() memasangnya ulang
 * (Tests\Support\LepasMigrationKepegawaianTrait). Assertion master tidak berubah.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class DiklatHukdisKonketSchemaTest extends DatabaseTestCase
{
    use LepasMigrationKepegawaianTrait;


    private const TABLES = ['diklat', 'tingkat_hukdis', 'jenis_hukdis', 'jenis_konket', 'tanda_jasa'];

    private const PRIMARY_KEYS = [
        'diklat'         => 'id_diklat',
        'tingkat_hukdis' => 'id_tingkat_hukdis',
        'jenis_hukdis'   => 'id_jenis_hukdis',
        'jenis_konket'   => 'id_jenis_konket',
        'tanda_jasa'     => 'id_tanda_jasa',
    ];

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi (" null" = boleh NULL). Sekaligus
     * membuktikan tidak ada created_at/created_by (pola audit `diklat` [K]).
     */
    private const COLUMNS = [
        'diklat' => [
            'id_diklat' => 'tinyint', 'jenis_diklat' => 'tinyint(1)', 'nama_diklat' => 'varchar(255)', 'order' => 'tinyint',
            'status'    => 'tinyint', 'updated_at' => 'datetime', 'updated_by' => 'int null',
        ],
        'tingkat_hukdis' => [
            'id_tingkat_hukdis' => 'int', 'tingkat_hukdis' => 'varchar(100)', 'bobot_ipasn' => 'int null', 'order' => 'tinyint',
            'status'            => 'tinyint', 'updated_at' => 'datetime', 'updated_by' => 'int null',
        ],
        'jenis_hukdis' => [
            'id_jenis_hukdis'   => 'int', 'id_tingkat_hukdis' => 'int', 'jenis_hukdis' => 'varchar(255)',
            'masa_sanksi_bulan' => 'tinyint unsigned null', 'order' => 'tinyint', 'status' => 'tinyint',
            'updated_at'        => 'datetime', 'updated_by' => 'int null',
        ],
        'jenis_konket' => [
            'id_jenis_konket' => 'int', 'old_id' => 'int', 'jenis_konket' => 'varchar(255)', 'affect_tukin' => 'tinyint',
            'order'           => 'tinyint', 'status' => 'tinyint', 'updated_at' => 'datetime', 'updated_by' => 'int null',
        ],
        'tanda_jasa' => [
            'id_tanda_jasa' => 'int', 'tanda_jasa' => 'varchar(255)', 'order' => 'tinyint', 'status' => 'tinyint',
            'updated_at'    => 'datetime', 'updated_by' => 'int null',
        ],
    ];

    /**
     * Index: tabel => [nama index => [NON_UNIQUE, INDEX_TYPE, kolom berurutan]].
     */
    private const INDEXES = [
        'diklat' => [
            'PRIMARY'        => [0, 'BTREE', ['id_diklat']],
            'uq_diklat_nama' => [0, 'BTREE', ['jenis_diklat', 'nama_diklat']],
        ],
        'tingkat_hukdis' => [
            'PRIMARY'                => [0, 'BTREE', ['id_tingkat_hukdis']],
            'uq_tingkat_hukdis_nama' => [0, 'BTREE', ['tingkat_hukdis']],
        ],
        'jenis_hukdis' => [
            'PRIMARY'                                    => [0, 'BTREE', ['id_jenis_hukdis']],
            'uq_jenis_hukdis_nama'                       => [0, 'BTREE', ['id_tingkat_hukdis', 'jenis_hukdis']],
            'fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis' => [1, 'BTREE', ['id_tingkat_hukdis']],
        ],
        'jenis_konket' => [
            'PRIMARY'                => [0, 'BTREE', ['id_jenis_konket']],
            'uq_jenis_konket_nama'   => [0, 'BTREE', ['jenis_konket']],
            'uq_jenis_konket_old_id' => [0, 'BTREE', ['old_id']],
        ],
        'tanda_jasa' => [
            'PRIMARY'            => [0, 'BTREE', ['id_tanda_jasa']],
            'uq_tanda_jasa_nama' => [0, 'BTREE', ['tanda_jasa']],
        ],
    ];

    /**
     * Seluruh FK dari/ke kelima tabel, urut nama: [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE,
     * DELETE_RULE]. Nama legacy dari ERD simpeg01; aksi RESTRICT/RESTRICT [V2]. `jenis_konket.old_id` tanpa FK fisik.
     */
    private const FOREIGN_KEYS = [
        'fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis' => ['jenis_hukdis', 'id_tingkat_hukdis', 'tingkat_hukdis', 'id_tingkat_hukdis', 'RESTRICT', 'RESTRICT'],
    ];

    /**
     * CHECK constraint per tabel (urut nama). Teks CHECK_CLAUSE tidak dibandingkan karena formatnya beda antar server;
     * perilakunya dibuktikan di testConstraintsAreEnforced().
     */
    private const CHECKS = [
        'diklat'         => ['chk_diklat_jenis_diklat'],
        'tingkat_hukdis' => [],
        'jenis_hukdis'   => ['chk_jenis_hukdis_masa_sanksi_bulan'],
        'jenis_konket'   => ['chk_jenis_konket_affect_tukin'],
        'tanda_jasa'     => [],
    ];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Test rollback/kegagalan memanggil down()/up() di tengah jalan; kalau assertion gagal sebelum skema pulih, tabel
     * G-06 harus dibuat lagi (termasuk membuang tabel penghalang simulasi) agar migrate:refresh test berikutnya
     * konsisten dengan tabel migrations.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->lepasMigrationKepegawaian();
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($table) || $this->columns($table) !== self::COLUMNS[$table]) {
                $migration = $this->migration();
                $migration->down();
                $migration->up();

                break;
            }
        }

        $this->pasangUlangMigrationKepegawaian();

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSchema();
    }

    public function testColumnDefaultsAndComments(): void
    {
        foreach (self::TABLES as $table) {
            $status = $this->columnInfo($table, 'status');
            $this->assertSame('1', $this->unquote($status['COLUMN_DEFAULT']), "{$table}.status default 1 (Aktif)");
            $this->assertSame(self::STATUS_COMMENT, $status['COLUMN_COMMENT'], "{$table}.status COMMENT");
            $this->assertSame('1', $this->unquote($this->columnInfo($table, 'order')['COLUMN_DEFAULT']), "{$table}.order default 1");

            $pk = self::PRIMARY_KEYS[$table];
            $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo($table, $pk)['EXTRA']), "{$table}.{$pk}");

            $updatedAt = $this->columnInfo($table, 'updated_at');
            $this->assertSame('current_timestamp', strtolower(trim((string) $updatedAt['COLUMN_DEFAULT'], "'()")), "{$table}.updated_at default");
            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $updatedAt['EXTRA']), "{$table}.updated_at ON UPDATE");

            $updatedBy = $this->columnInfo($table, 'updated_by');
            $this->assertTrue($this->isNullDefault($updatedBy['COLUMN_DEFAULT']), "{$table}.updated_by default NULL");
            $this->assertSame('id_pengguna yang terakhir mengubah', $updatedBy['COLUMN_COMMENT'], "{$table}.updated_by COMMENT");
        }

        // diklat.jenis_diklat: default & COMMENT legacy verbatim [K] (dasar opsi 1–5, keputusan B1).
        $jenisDiklat = $this->columnInfo('diklat', 'jenis_diklat');
        $this->assertSame('1', $this->unquote($jenisDiklat['COLUMN_DEFAULT']));
        $this->assertSame('1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan,5:Sertifikasi', $jenisDiklat['COLUMN_COMMENT']);

        // tingkat_hukdis.bobot_ipasn [I]: default 5 seperti simpegdev_local, tidak dikelola v2 (B5).
        $bobot = $this->columnInfo('tingkat_hukdis', 'bobot_ipasn');
        $this->assertSame('5', $this->unquote($bobot['COLUMN_DEFAULT']));
        $this->assertSame('bobot skor IPASN dimensi disiplin (legacy); tidak dikelola v2', $bobot['COLUMN_COMMENT']);

        // jenis_hukdis.masa_sanksi_bulan [V2] K5b: nullable, default NULL (MySQL null, MariaDB string 'NULL').
        $masa = $this->columnInfo('jenis_hukdis', 'masa_sanksi_bulan');
        $this->assertTrue($this->isNullDefault($masa['COLUMN_DEFAULT']), 'masa_sanksi_bulan default NULL');
        $this->assertSame('masa sanksi bawaan dalam bulan untuk hitung akhir_hukdis; NULL = tanpa masa', $masa['COLUMN_COMMENT']);

        // jenis_konket.affect_tukin [V2] K5a: default 1 (Ya), nilai 1/2 seperti absen_ijin.affect_tukin.
        $affect = $this->columnInfo('jenis_konket', 'affect_tukin');
        $this->assertSame('1', $this->unquote($affect['COLUMN_DEFAULT']));
        $this->assertSame('1: Ya, 2: Tidak (nilai awal affect_tukin pengajuan konket)', $affect['COLUMN_COMMENT']);

        // jenis_konket.old_id wajib diisi (tanpa default) — B2.
        $oldId = $this->columnInfo('jenis_konket', 'old_id');
        $this->assertNull($oldId['COLUMN_DEFAULT'], 'old_id tanpa default');
        $this->assertSame('kode kategori konket = absen_ijin.kategori', $oldId['COLUMN_COMMENT']);
    }

    /**
     * Tanpa seed/sentinel: baris yang di-hard-code kode legacy datang dari impor ID legacy apa adanya.
     */
    public function testMigrationCreatesNoRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (koneksi test strictOn=true): UNIQUE case-insensitive termasuk baris status 10, CHECK, FK RESTRICT,
     * NOT NULL, dan batas tipe. Hanya huruf ASCII untuk uji case-insensitive.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->assertDiklatConstraints();
        $this->assertHukdisConstraints();
        $this->assertKonketConstraints();
        $this->assertTandaJasaConstraints();
    }

    /**
     * down() menghapus kelima tabel (anak sebelum induk), up() membuatnya kembali persis. Tabel lain tidak tersentuh.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $migration = $this->migration();
        $migration->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        $this->assertTrue($this->tableExists('provinsi'), 'tabel Batch 1 tidak tersentuh');
        $this->assertTrue($this->tableExists('faq_topic'), 'tabel FAQ tidak tersentuh');

        $migration->up();
        $this->assertSchema();
    }

    /**
     * Bila salah satu CREATE gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop (urutan terbalik) lalu
     * error dilempar ulang, sehingga `migrate` bisa langsung diulang tanpa DDL manual. Tabel yang sudah ada sebelum run
     * tidak disentuh. Kegagalan disimulasikan dengan tabel penghalang bernama `jenis_konket` (CREATE ke-4 gagal, 1050).
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $migration = $this->migration();
        $migration->down();

        $blocker = $this->db->escapeIdentifiers($this->db->prefixTable('jenis_konket'));
        $this->db->query("CREATE TABLE {$blocker} (`penghalang` INT NOT NULL) ENGINE=InnoDB");

        $error = null;

        try {
            $migration->up();
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena jenis_konket sudah ada.');
        $this->assertStringContainsString('jenis_konket', $error->getMessage());

        foreach (['diklat', 'tingkat_hukdis', 'jenis_hukdis', 'tanda_jasa'] as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        // Tabel yang sudah ada sebelum run tidak ikut di-drop.
        $this->assertSame(['penghalang' => 'int'], $this->columns('jenis_konket'));

        // Setelah penyebabnya dibereskan, up() bisa langsung diulang.
        $this->db->query("DROP TABLE {$blocker}");
        $migration->up();
        $this->assertSchema();
    }

    private function assertDiklatConstraints(): void
    {
        // ID legacy eksplisit diterima (diklat 8 di-hard-code Siasn.php:1810).
        $this->db->table('diklat')->insert(['id_diklat' => 8, 'jenis_diklat' => 4, 'nama_diklat' => 'Pelatihan Dasar']);
        // Lingkup UNIQUE per jenis: nama sama beda jenis boleh.
        $this->db->table('diklat')->insert(['jenis_diklat' => 2, 'nama_diklat' => 'pelatihan dasar']);
        $this->assertDbWriteFails(fn () => $this->db->table('diklat')->insert(['jenis_diklat' => 4, 'nama_diklat' => 'PELATIHAN DASAR']));

        // CHECK jenis_diklat 1..5.
        $this->db->table('diklat')->insert(['jenis_diklat' => 5, 'nama_diklat' => 'Sertifikasi Uji']);
        $this->assertDbWriteFails(fn () => $this->db->table('diklat')->insert(['jenis_diklat' => 0, 'nama_diklat' => 'Jenis Nol']));
        $this->assertDbWriteFails(fn () => $this->db->table('diklat')->insert(['jenis_diklat' => 6, 'nama_diklat' => 'Jenis Enam']));

        // Baris status 10 (legacy dm_diklat) tetap memblok nama yang sama.
        $this->db->table('diklat')->insert(['jenis_diklat' => 1, 'nama_diklat' => 'Diklatpim Lama', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('diklat')->insert(['jenis_diklat' => 1, 'nama_diklat' => 'DIKLATPIM LAMA']));

        $this->seeInDatabase('diklat', ['id_diklat' => 8, 'jenis_diklat' => 4, 'order' => 1, 'status' => 1, 'updated_by' => null]);

        // PK TINYINT signed: batas 127 (G-06 Bagian 3 #8). Setelah id 127 terpakai, AUTO_INCREMENT pun habis — blok ini
        // harus terakhir untuk diklat.
        $this->db->table('diklat')->insert(['id_diklat' => 127, 'jenis_diklat' => 1, 'nama_diklat' => 'Batas Atas']);
        $this->assertDbWriteFails(fn () => $this->db->table('diklat')->insert(['id_diklat' => 128, 'jenis_diklat' => 1, 'nama_diklat' => 'Lewat Batas']));
        $this->assertDbWriteFails(fn () => $this->db->table('diklat')->insert(['jenis_diklat' => 1, 'nama_diklat' => 'ID Habis']));
    }

    private function assertHukdisConstraints(): void
    {
        $this->db->table('tingkat_hukdis')->insert(['id_tingkat_hukdis' => 1, 'tingkat_hukdis' => 'Ringan']);
        $this->db->table('tingkat_hukdis')->insert(['id_tingkat_hukdis' => 2, 'tingkat_hukdis' => 'Sedang']);
        $this->seeInDatabase('tingkat_hukdis', ['id_tingkat_hukdis' => 1, 'bobot_ipasn' => 5]);
        $this->assertDbWriteFails(fn () => $this->db->table('tingkat_hukdis')->insert(['tingkat_hukdis' => 'RINGAN']));

        // UNIQUE (id_tingkat_hukdis, jenis_hukdis): lingkup per tingkat.
        $this->db->table('jenis_hukdis')->insert(['id_jenis_hukdis' => 1, 'id_tingkat_hukdis' => 1, 'jenis_hukdis' => 'Teguran Lisan']);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 1, 'masa_sanksi_bulan' => null]);
        $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Teguran Lisan']);
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 1, 'jenis_hukdis' => 'teguran lisan']));

        // Baris status 10 (soft delete legacy Lm_hukdis.php:349-354) tetap memblok nama yang sama.
        $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 1, 'jenis_hukdis' => 'Pernyataan Tidak Puas', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 1, 'jenis_hukdis' => 'PERNYATAAN TIDAK PUAS']));

        // FK & NOT NULL id_tingkat_hukdis.
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 99, 'jenis_hukdis' => 'Tanpa Induk']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => null, 'jenis_hukdis' => 'Induk Kosong']));

        // masa_sanksi_bulan: NULL atau 1..255 (CHECK ≥ 1, TINYINT UNSIGNED).
        $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Penundaan Pangkat', 'masa_sanksi_bulan' => 12]);
        $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Batas Atas Masa', 'masa_sanksi_bulan' => 255]);
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Masa Nol', 'masa_sanksi_bulan' => 0]));
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Masa Negatif', 'masa_sanksi_bulan' => -1]));
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_hukdis')->insert(['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Masa Lewat', 'masa_sanksi_bulan' => 256]));

        // FK RESTRICT: induk yang masih punya anak tidak bisa dihapus atau diganti PK-nya.
        $this->assertDbWriteFails(fn () => $this->db->table('tingkat_hukdis')->where('id_tingkat_hukdis', 1)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('tingkat_hukdis')->where('id_tingkat_hukdis', 1)->update(['id_tingkat_hukdis' => 5]));

        $this->seeInDatabase('tingkat_hukdis', ['id_tingkat_hukdis' => 1, 'tingkat_hukdis' => 'Ringan']);
        $this->seeInDatabase('jenis_hukdis', ['id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Penundaan Pangkat', 'masa_sanksi_bulan' => 12]);
    }

    private function assertKonketConstraints(): void
    {
        // ID legacy eksplisit + old_id (kode kategori absen_ijin); affect_tukin default 1 (Ya).
        $this->db->table('jenis_konket')->insert(['id_jenis_konket' => 2, 'old_id' => 8, 'jenis_konket' => 'Dinas']);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 2, 'old_id' => 8, 'affect_tukin' => 1]);
        $this->db->table('jenis_konket')->insert(['id_jenis_konket' => 4, 'old_id' => 10, 'jenis_konket' => 'Izin Sakit', 'affect_tukin' => 2]);

        // old_id wajib & unik.
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_konket')->insert(['old_id' => 8, 'jenis_konket' => 'Cuti']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_konket')->insert(['jenis_konket' => 'Tanpa Kode']));

        // UNIQUE nama case-insensitive.
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_konket')->insert(['old_id' => 99, 'jenis_konket' => 'DINAS']));

        // CHECK affect_tukin 1/2.
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_konket')->insert(['old_id' => 20, 'jenis_konket' => 'Nilai Nol', 'affect_tukin' => 0]));
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_konket')->insert(['old_id' => 21, 'jenis_konket' => 'Nilai Tiga', 'affect_tukin' => 3]));

        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 4, 'old_id' => 10, 'affect_tukin' => 2]);
    }

    private function assertTandaJasaConstraints(): void
    {
        // 44 = LAIN-LAIN (dibandingkan sebagai string oleh kode legacy).
        $this->db->table('tanda_jasa')->insert(['id_tanda_jasa' => 44, 'tanda_jasa' => 'LAIN-LAIN']);
        $this->assertDbWriteFails(fn () => $this->db->table('tanda_jasa')->insert(['tanda_jasa' => 'lain-lain']));

        // Baris status 10 tetap memblok nama yang sama.
        $this->db->table('tanda_jasa')->insert(['id_tanda_jasa' => 28, 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('tanda_jasa')->insert(['tanda_jasa' => 'Satyalancana Karya Satya X Tahun']));

        $this->seeInDatabase('tanda_jasa', ['id_tanda_jasa' => 44, 'tanda_jasa' => 'LAIN-LAIN', 'status' => 1]);
    }

    private function assertSchema(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(self::COLUMNS[$table], $this->columns($table), "kolom {$table}");
            $this->assertSame('utf8mb4_unicode_ci', $this->tableCollation($table), "collation {$table}");
            $this->assertSame('InnoDB', $this->tableEngine($table), "engine {$table}");
            $this->assertSame(self::INDEXES[$table], $this->indexes($table), "index {$table}");

            foreach ($this->stringColumnCollations($table) as $column => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "collation {$table}.{$column}");
            }
        }

        $this->assertSame(self::FOREIGN_KEYS, $this->foreignKeys());
        $this->assertSame([], $this->foreignKeysOn('jenis_konket', 'old_id'), 'jenis_konket.old_id tanpa FK fisik (relasi logis ke absen_ijin.kategori)');
        $this->assertSame(self::CHECKS, $this->checkConstraints());
    }

    private function assertDbWriteFails(callable $write): void
    {
        $failed = false;

        try {
            $failed = $write() === false;
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Constraint DB harus menolak penulisan ini.');
    }

    private function migration(): CreateDiklatHukdisKonketTandaJasa
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-120000_CreateDiklatHukdisKonketTandaJasa.php';

        return new CreateDiklatHukdisKonketTandaJasa();
    }

    private function unquote(mixed $value): string
    {
        return trim((string) $value, "'");
    }

    /**
     * Default NULL: MySQL 8 mengembalikan null, MariaDB ≥ 10.2.7 mengembalikan string 'NULL'.
     */
    private function isNullDefault(mixed $value): bool
    {
        return $value === null || strtoupper((string) $value) === 'NULL';
    }

    private function tableExists(string $table): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray()['n'] > 0;
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
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), tinyint(3) unsigned, int(11)); samakan dengan
            // MySQL 8. Kecuali tinyint(1): kedua server menampilkannya, jadi jenis_diklat TINYINT(1) [K] terbukti persis.
            $type = (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\((?!1\))\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));

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
            'SELECT EXTRA, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, string> kolom string => collation
     */
    private function stringColumnCollations(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'COLLATION_NAME', 'COLUMN_NAME');
    }

    private function tableCollation(string $table): string
    {
        return (string) $this->tableInfo($table)['TABLE_COLLATION'];
    }

    private function tableEngine(string $table): string
    {
        return (string) $this->tableInfo($table)['ENGINE'];
    }

    /**
     * @return array<string, mixed>
     */
    private function tableInfo(string $table): array
    {
        return $this->db->query(
            'SELECT TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: list<string>}> nama index => [NON_UNIQUE, INDEX_TYPE, kolom], urut
     *                                                                  seperti konstanta INDEXES (PRIMARY, UNIQUE, lainnya)
     */
    private function indexes(string $table): array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            $indexes[$name] ??= [(int) $row['NON_UNIQUE'], (string) $row['INDEX_TYPE'], []];
            $indexes[$name][2][] = (string) $row['COLUMN_NAME'];
        }

        // Bandingkan tanpa bergantung urutan kembalian information_schema: ikuti urutan konstanta.
        $ordered = [];

        foreach (array_keys(self::INDEXES[$table]) as $name) {
            if (isset($indexes[$name])) {
                $ordered[$name] = $indexes[$name];
                unset($indexes[$name]);
            }
        }

        return $ordered + $indexes;
    }

    /**
     * Seluruh FK dari/ke tabel G-06: nama => [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE],
     * urut nama.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function foreignKeys(): array
    {
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES);

        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL AND (k.TABLE_NAME IN ? OR k.REFERENCED_TABLE_NAME IN ?)
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), $tables, $tables],
        )->getResultArray();

        $fks = [];

        foreach ($rows as $row) {
            $fks[(string) $row['CONSTRAINT_NAME']] = [
                $this->stripPrefix((string) $row['TABLE_NAME']),
                (string) $row['COLUMN_NAME'],
                $this->stripPrefix((string) $row['REFERENCED_TABLE_NAME']),
                (string) $row['REFERENCED_COLUMN_NAME'],
                (string) $row['UPDATE_RULE'],
                (string) $row['DELETE_RULE'],
            ];
        }

        ksort($fks);

        return $fks;
    }

    /**
     * @return list<string> nama FK yang memakai kolom ini
     */
    private function foreignKeysOn(string $table, string $column): array
    {
        $rows = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['CONSTRAINT_NAME'], $rows);
    }

    /**
     * CHECK constraint kelima tabel: tabel => [nama], urut nama. Memakai TABLE_CONSTRAINTS (ada TABLE_NAME di MySQL 8
     * maupun MariaDB), bukan CHECK_CONSTRAINTS (MySQL 8 tidak punya kolom TABLE_NAME di sana).
     *
     * @return array<string, list<string>>
     */
    private function checkConstraints(): array
    {
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES);

        $rows = $this->db->query(
            "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN ? AND CONSTRAINT_TYPE = 'CHECK'
             ORDER BY TABLE_NAME, CONSTRAINT_NAME",
            [$this->db->getDatabase(), $tables],
        )->getResultArray();

        $checks = array_fill_keys(self::TABLES, []);

        foreach ($rows as $row) {
            $checks[$this->stripPrefix((string) $row['TABLE_NAME'])][] = (string) $row['CONSTRAINT_NAME'];
        }

        return $checks;
    }

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
