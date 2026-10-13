<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\CreateMasterKenaikanPangkat;
use App\Database\Migrations\CreateMasterPendidikan;
use CodeIgniter\Database\Migration;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LepasMigrationKepegawaianTrait;

/**
 * DBV-004 — skema G-04 Kenaikan Pangkat (`pangkat`, `jenis_kp`, `gol_pppk`) dan G-05 Pendidikan (`jenjang_pendidikan`,
 * `bidang_pendidikan`, `jurusan_pendidikan`) hasil migration 2026-09-25-110000_CreateMasterKenaikanPangkat dan
 * 2026-09-25-110100_CreateMasterPendidikan harus sama dengan skema yang diajukan ke DB Validator
 * (backend/docs/db-review/G-04-G-05-pangkat-pendidikan-schema.md): DDL legacy `simpeg_prod.sql:257-265, 1277-1289` [K],
 * kolom dari kode legacy + tipe [I], deviasi v2 (status TINYINT gol_pppk, UNIQUE nama, CHECK row_jurusan, FK RESTRICT,
 * `order` bidang/jurusan), dan bisa di-rollback per migration.
 *
 * Migration B-01/B-02 (DBV-012/013) merujuk tabel master ini dengan FK RESTRICT, sehingga down() master di tengah
 * test ditolak MySQL selama migration itu terpasang; setUp() melepasnya dan tearDown() memasangnya ulang
 * (Tests\Support\LepasMigrationKepegawaianTrait). Assertion master tidak berubah.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class PangkatPendidikanSchemaTest extends DatabaseTestCase
{
    use LepasMigrationKepegawaianTrait;


    private const TABLES_KP = ['pangkat', 'jenis_kp', 'gol_pppk'];

    private const TABLES_PENDIDIKAN = ['jenjang_pendidikan', 'bidang_pendidikan', 'jurusan_pendidikan'];

    private const TABLES = [...self::TABLES_KP, ...self::TABLES_PENDIDIKAN];

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi (" null" = boleh NULL). Tanda ikut
     * dibandingkan: PK signed seperti legacy (`tinyint`, bukan `tinyint unsigned`).
     */
    private const COLUMNS = [
        'pangkat' => [
            'id_pangkat' => 'tinyint', 'pangkat' => 'varchar(50)', 'gol' => 'varchar(10)', 'ruang' => 'varchar(10)',
            'gol_ruang'  => 'varchar(10)', 'cpns' => 'tinyint', 'order' => 'tinyint', 'status' => 'tinyint',
            'updated_at' => 'datetime', 'updated_by' => 'int null',
        ],
        'jenis_kp' => [
            'id_jenis_kp' => 'tinyint', 'jenis_kp' => 'varchar(100)', 'order' => 'tinyint', 'status' => 'tinyint',
            'updated_at'  => 'datetime', 'updated_by' => 'int null',
        ],
        'gol_pppk' => [
            'id_gol_pppk' => 'tinyint', 'gol_pppk' => 'varchar(10)', 'uang_makan' => 'double', 'order' => 'tinyint',
            'keterangan'  => 'tinytext null', 'status' => 'tinyint', 'created_at' => 'datetime',
            'updated_at'  => 'datetime null', 'created_by' => 'int null', 'updated_by' => 'int null',
        ],
        'jenjang_pendidikan' => [
            'id_jenjang_pendidikan' => 'int', 'jenjang_pendidikan_singkat' => 'varchar(50)',
            'jenjang_pendidikan'    => 'varchar(100)', 'row_jurusan' => 'varchar(10) null', 'bobot_ipasn' => 'int null',
            'order'                 => 'tinyint', 'status' => 'tinyint', 'updated_at' => 'datetime', 'updated_by' => 'int null',
        ],
        'bidang_pendidikan' => [
            'id_bidang_pendidikan'      => 'tinyint', 'bidang_pendidikan' => 'varchar(100)',
            'bidang_pendidikan_english' => 'varchar(100) null', 'order' => 'int', 'status' => 'tinyint',
            'updated_at'                => 'datetime', 'updated_by' => 'int null',
        ],
        'jurusan_pendidikan' => [
            'id_jurusan_pendidikan'      => 'int', 'id_bidang_pendidikan' => 'tinyint', 'jurusan_pendidikan' => 'varchar(255)',
            'jurusan_pendidikan_english' => 'varchar(255) null', 'gelar' => 'varchar(50) null', 'D_I' => 'tinyint',
            'D_II'                       => 'tinyint', 'D_III' => 'tinyint', 'D_IV' => 'tinyint', 'S_1' => 'tinyint',
            'S_2'                        => 'tinyint', 'S_3' => 'tinyint', 'order' => 'int', 'status' => 'tinyint',
            'updated_at'                 => 'datetime', 'updated_by' => 'int null',
        ],
    ];

    /**
     * Seluruh index: tabel => [nama index => [NON_UNIQUE, INDEX_TYPE, kolom berurutan]]. Tidak ada KEY order/status.
     */
    private const INDEXES = [
        'pangkat' => [
            'PRIMARY'         => [0, 'BTREE', ['id_pangkat']],
            'uq_pangkat_nama' => [0, 'BTREE', ['gol_ruang']],
        ],
        'jenis_kp' => [
            'PRIMARY'          => [0, 'BTREE', ['id_jenis_kp']],
            'uq_jenis_kp_nama' => [0, 'BTREE', ['jenis_kp']],
        ],
        'gol_pppk' => [
            'PRIMARY'          => [0, 'BTREE', ['id_gol_pppk']],
            'uq_gol_pppk_nama' => [0, 'BTREE', ['gol_pppk']],
        ],
        'jenjang_pendidikan' => [
            'PRIMARY'                       => [0, 'BTREE', ['id_jenjang_pendidikan']],
            'uq_jenjang_pendidikan_nama'    => [0, 'BTREE', ['jenjang_pendidikan']],
            'uq_jenjang_pendidikan_singkat' => [0, 'BTREE', ['jenjang_pendidikan_singkat']],
        ],
        'bidang_pendidikan' => [
            'PRIMARY'                   => [0, 'BTREE', ['id_bidang_pendidikan']],
            'uq_bidang_pendidikan_nama' => [0, 'BTREE', ['bidang_pendidikan']],
        ],
        'jurusan_pendidikan' => [
            'PRIMARY'                                => [0, 'BTREE', ['id_jurusan_pendidikan']],
            'uq_jurusan_pendidikan_nama'             => [0, 'BTREE', ['id_bidang_pendidikan', 'jurusan_pendidikan']],
            'fk_id_bidang_pendidikan_jpend_to_bpend' => [1, 'BTREE', ['id_bidang_pendidikan']],
        ],
    ];

    /**
     * Seluruh FK dari/ke keenam tabel: tepat satu, nama legacy (ERD simpeg01), [tabel anak, kolom, tabel induk, kolom
     * induk, UPDATE_RULE, DELETE_RULE]. Aksi RESTRICT/RESTRICT [V2] (aksi legacy tidak diketahui). Tabel perujuk lain
     * (riwayat, dm_ak_jf) dibuat di Fase 3 / DBV-008.
     */
    private const FOREIGN_KEYS = [
        'fk_id_bidang_pendidikan_jpend_to_bpend' => ['jurusan_pendidikan', 'id_bidang_pendidikan', 'bidang_pendidikan', 'id_bidang_pendidikan', 'RESTRICT', 'RESTRICT'],
    ];

    /**
     * Seluruh CHECK constraint di keenam tabel (nama saja; teks klausa berbeda format antara MySQL dan MariaDB, perilaku
     * diuji di testConstraintsAreEnforced).
     */
    private const CHECKS = [
        'jenjang_pendidikan' => ['chk_jenjang_pendidikan_row_jurusan'],
    ];

    /**
     * Kolom flag jenjang di jurusan_pendidikan => label COMMENT; nama kolom = nilai sah jenjang_pendidikan.row_jurusan.
     */
    private const FLAGS = [
        'D_I' => 'D.I', 'D_II' => 'D.II', 'D_III' => 'D.III', 'D_IV' => 'D.IV', 'S_1' => 'S.1', 'S_2' => 'S.2', 'S_3' => 'S.3',
    ];

    /**
     * Tabel dengan kolom audit pola `bidang_pendidikan` [K] (updated_at NOT NULL ON UPDATE + updated_by, tanpa created_*).
     */
    private const TABLES_AUDIT_UPDATED = ['pangkat', 'jenis_kp', 'jenjang_pendidikan', 'bidang_pendidikan', 'jurusan_pendidikan'];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Test rollback/kegagalan memanggil down()/up() di tengah jalan; kalau assertion gagal sebelum skema pulih, tabel
     * grup harus dibuat lagi (termasuk membuang tabel penghalang simulasi) agar migrate:refresh test berikutnya
     * konsisten dengan tabel migrations.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->lepasMigrationKepegawaian();
    }

    protected function tearDown(): void
    {
        foreach ([[self::TABLES_KP, $this->kpMigration()], [self::TABLES_PENDIDIKAN, $this->pendidikanMigration()]] as [$tables, $migration]) {
            foreach ($tables as $table) {
                if (! $this->tableExists($table)) {
                    $migration->down();
                    $migration->up();

                    break;
                }
            }
        }

        $this->pasangUlangMigrationKepegawaian();

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSchema();
    }

    public function testStatusOrderAndAuditDetails(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame('1', $this->defaultOf($table, 'status'), "{$table}.status default 1 (Aktif)");
            $this->assertSame(self::STATUS_COMMENT, $this->commentOf($table, 'status'), "{$table}.status COMMENT");
            $this->assertSame($table === 'gol_pppk' ? '0' : '1', $this->defaultOf($table, 'order'), "{$table}.order default");

            $pk = self::INDEXES[$table]['PRIMARY'][2][0];
            $this->assertStringContainsString('auto_increment', $this->extraOf($table, $pk), "{$table}.{$pk}");

            $this->assertStringContainsString('on update current_timestamp', $this->extraOf($table, 'updated_at'), "{$table}.updated_at");
            $this->assertSame('id_pengguna yang terakhir mengubah', $this->commentOf($table, 'updated_by'), "{$table}.updated_by COMMENT");
        }

        // Pola audit bidang_pendidikan [K]: updated_at NOT NULL DEFAULT CURRENT_TIMESTAMP, tanpa created_*.
        foreach (self::TABLES_AUDIT_UPDATED as $table) {
            $this->assertSame('current_timestamp', $this->defaultOf($table, 'updated_at'), "{$table}.updated_at default");
            $this->assertArrayNotHasKey('created_at', $this->columns($table), "{$table} tanpa created_at");
            $this->assertArrayNotHasKey('created_by', $this->columns($table), "{$table} tanpa created_by");
        }

        // gol_pppk: kolom audit DDL [K] (pola FAQ).
        $this->assertSame('current_timestamp', $this->defaultOf('gol_pppk', 'created_at'));
        $this->assertNull($this->defaultOf('gol_pppk', 'updated_at'), 'gol_pppk.updated_at default NULL');
        $this->assertSame('id_pengguna pembuat', $this->commentOf('gol_pppk', 'created_by'));
        $this->assertSame('0', $this->defaultOf('gol_pppk', 'uang_makan'), 'gol_pppk.uang_makan default 0 [K]');
        $this->assertNull($this->defaultOf('gol_pppk', 'keterangan'));

        $this->assertSame('2', $this->defaultOf('pangkat', 'cpns'), 'pangkat.cpns default 2 (PNS)');
        $this->assertSame('1: CPNS, 2: PNS', $this->commentOf('pangkat', 'cpns'));
        $this->assertSame('level pangkat (KP berikutnya = order + 1), bukan sekadar urutan tampil', $this->commentOf('pangkat', 'order'));

        $this->assertNull($this->defaultOf('jenjang_pendidikan', 'row_jurusan'));
        $this->assertSame(
            'kolom flag jenjang di jurusan_pendidikan (D_I..S_3); NULL = jenjang tanpa jurusan',
            $this->commentOf('jenjang_pendidikan', 'row_jurusan'),
        );
        // Bagian 4 #16 (keputusan DBV): DEFAULT NULL = belum ditetapkan, bukan 25 dari simpegdev_local.
        $this->assertNull($this->defaultOf('jenjang_pendidikan', 'bobot_ipasn'));
        $this->assertSame('skor kualifikasi pendidikan IP ASN', $this->commentOf('jenjang_pendidikan', 'bobot_ipasn'));

        foreach (self::FLAGS as $flag => $label) {
            $this->assertSame('0', $this->defaultOf('jurusan_pendidikan', $flag), "jurusan_pendidikan.{$flag} default 0");
            $this->assertSame("1: tersedia untuk jenjang {$label}, 0: tidak", $this->commentOf('jurusan_pendidikan', $flag));
        }
    }

    /**
     * Deviasi wajib: legacy `gol_pppk.status` ENUM('1','2') tidak bisa menyimpan 10 (strict mati = string kosong, strict
     * hidup = error). Kolom TINYINT v2 menyimpan 1/2/10 apa adanya.
     */
    public function testGolPppkStatusStoresSoftDeleteValue(): void
    {
        $this->db->table('gol_pppk')->insert(['id_gol_pppk' => 7, 'gol_pppk' => 'VII', 'uang_makan' => 37000, 'order' => 7]);
        $this->assertSame(1, $this->golPppkStatus(7), 'default status 1');

        $this->db->table('gol_pppk')->where('id_gol_pppk', 7)->update(['status' => 10]);
        $this->assertSame(10, $this->golPppkStatus(7));

        $this->db->table('gol_pppk')->where('id_gol_pppk', 7)->update(['status' => 2]);
        $this->assertSame(2, $this->golPppkStatus(7));

        $this->db->table('gol_pppk')->insert(['id_gol_pppk' => 9, 'gol_pppk' => 'IX', 'status' => 10]);
        $this->assertSame(10, $this->golPppkStatus(9));
    }

    /**
     * Lapis DB: UNIQUE menolak nama ganda case-insensitive (termasuk baris status 10), FK RESTRICT menolak orphan dan
     * hard delete/ubah PK induk, CHECK menolak row_jurusan di luar daftar, PK TINYINT signed.
     */
    public function testConstraintsAreEnforced(): void
    {
        // pangkat: UNIQUE hanya gol_ruang. Nama pangkat dan order sama antara CPNS/PNS diterima (UNIQUE(cpns, order) ditunda).
        $this->db->table('pangkat')->insert(['id_pangkat' => 9, 'pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'cpns' => 2, 'order' => 9]);
        $this->db->table('pangkat')->insert(['id_pangkat' => 3, 'pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'CPNS III/a', 'cpns' => 1, 'order' => 9]);
        $this->db->table('pangkat')->insert(['id_pangkat' => 4, 'pangkat' => 'Penata Muda Tingkat I', 'gol' => 'III', 'ruang' => 'b', 'gol_ruang' => 'CPNS III/b', 'cpns' => 1, 'order' => 9, 'status' => 10]);
        $this->seeInDatabase('pangkat', ['gol_ruang' => 'CPNS III/b']);
        $this->assertDbWriteFails(fn () => $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'iii/A', 'cpns' => 2, 'order' => 20]));
        $this->assertDbWriteFails(fn () => $this->db->table('pangkat')->insert(['pangkat' => 'X', 'gol' => 'III', 'ruang' => 'b', 'gol_ruang' => 'cpns iii/B', 'cpns' => 1, 'order' => 21]));

        // jenis_kp & gol_pppk: UNIQUE nama global.
        $this->db->table('jenis_kp')->insert(['id_jenis_kp' => 3, 'jenis_kp' => 'Reguler', 'order' => 3]);
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_kp')->insert(['jenis_kp' => 'REGULER']));
        $this->db->table('gol_pppk')->insert(['id_gol_pppk' => 9, 'gol_pppk' => 'IX', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('gol_pppk')->insert(['gol_pppk' => 'ix']));

        // jenjang_pendidikan: UNIQUE nama dan UNIQUE singkatan (masing-masing).
        $this->db->table('jenjang_pendidikan')->insert(['id_jenjang_pendidikan' => 8, 'jenjang_pendidikan_singkat' => 'S.1', 'jenjang_pendidikan' => 'Strata 1', 'row_jurusan' => 'S_1']);
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'S1', 'jenjang_pendidikan' => 'STRATA 1']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 's.1', 'jenjang_pendidikan' => 'Sarjana']));

        // CHECK row_jurusan: NULL dan ketujuh nama kolom flag diterima; nilai lain (termasuk beda huruf dan spasi di akhir,
        // yang lolos perbandingan collation PAD SPACE) ditolak.
        $this->db->table('jenjang_pendidikan')->insert(['id_jenjang_pendidikan' => 1, 'jenjang_pendidikan_singkat' => 'SD', 'jenjang_pendidikan' => 'Sekolah Dasar', 'row_jurusan' => null]);
        $this->seeInDatabase('jenjang_pendidikan', ['id_jenjang_pendidikan' => 1, 'row_jurusan' => null]);

        foreach (array_keys(self::FLAGS) as $i => $flag) {
            $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => "J{$i}", 'jenjang_pendidikan' => "Jenjang {$i}", 'row_jurusan' => $flag]);
            $this->seeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => "J{$i}", 'row_jurusan' => $flag]);
        }
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'X1', 'jenjang_pendidikan' => 'X1', 'row_jurusan' => 'X']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'X2', 'jenjang_pendidikan' => 'X2', 'row_jurusan' => 's_1']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'X3', 'jenjang_pendidikan' => 'X3', 'row_jurusan' => '']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'X4', 'jenjang_pendidikan' => 'X4', 'row_jurusan' => 'S_1 ']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->where('id_jenjang_pendidikan', 8)->update(['row_jurusan' => 'S_1  ']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_pendidikan')->where('id_jenjang_pendidikan', 8)->update(['row_jurusan' => 'S1']));

        // bidang_pendidikan: UNIQUE nama.
        $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 1, 'bidang_pendidikan' => 'Teknik']);
        $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 98, 'bidang_pendidikan' => 'Lainnya']);
        $this->assertDbWriteFails(fn () => $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 2, 'bidang_pendidikan' => 'TEKNIK']));

        // jurusan_pendidikan: UNIQUE (bidang, nama) — nama sama di bidang lain diterima; flag default 0.
        $this->db->table('jurusan_pendidikan')->insert(['id_jurusan_pendidikan' => 1, 'id_bidang_pendidikan' => 1, 'jurusan_pendidikan' => 'Teknik Sipil', 'S_1' => 1]);
        $this->assertDbWriteFails(fn () => $this->db->table('jurusan_pendidikan')->insert(['id_bidang_pendidikan' => 1, 'jurusan_pendidikan' => 'teknik sipil', 'D_III' => 1]));
        $this->db->table('jurusan_pendidikan')->insert(['id_jurusan_pendidikan' => 1185, 'id_bidang_pendidikan' => 98, 'jurusan_pendidikan' => 'Teknik Sipil']);

        $row = $this->db->query(
            'SELECT D_I, D_II, D_III, D_IV, S_1, S_2, S_3 FROM ' . $this->db->prefixTable('jurusan_pendidikan') . ' WHERE id_jurusan_pendidikan = ?',
            [1185],
        )->getRowArray() ?? [];
        $this->assertSame(array_fill_keys(array_keys(self::FLAGS), '0'), $row, 'flag jenjang default 0');

        // FK RESTRICT: orphan, hard delete induk yang masih punya anak, dan ubah PK induk ditolak.
        $this->assertDbWriteFails(fn () => $this->db->table('jurusan_pendidikan')->insert(['id_bidang_pendidikan' => 50, 'jurusan_pendidikan' => 'Yatim', 'S_1' => 1]));
        $this->assertDbWriteFails(fn () => $this->db->table('bidang_pendidikan')->where('id_bidang_pendidikan', 1)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('bidang_pendidikan')->where('id_bidang_pendidikan', 98)->update(['id_bidang_pendidikan' => 99]));
        $this->seeInDatabase('bidang_pendidikan', ['id_bidang_pendidikan' => 1]);
        $this->seeInDatabase('bidang_pendidikan', ['id_bidang_pendidikan' => 98]);

        // Induk tanpa anak boleh di-hard-delete (RESTRICT hanya menjaga anak).
        $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 3, 'bidang_pendidikan' => 'Tanpa Jurusan']);
        $this->db->table('bidang_pendidikan')->where('id_bidang_pendidikan', 3)->delete();
        $this->dontSeeInDatabase('bidang_pendidikan', ['id_bidang_pendidikan' => 3]);

        // PK TINYINT signed (legacy): ID maksimal 127 (bidang AUTO_INCREMENT produksi 100); unsigned akan menerima 128.
        // Dijalankan paling akhir karena ID 127 menghabiskan counter AUTO_INCREMENT bidang.
        $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 127, 'bidang_pendidikan' => 'Batas Atas']);
        $this->assertDbWriteFails(fn () => $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 128, 'bidang_pendidikan' => 'Di Atas Batas']));
        $this->assertDbWriteFails(fn () => $this->db->table('pangkat')->insert(['id_pangkat' => 128, 'pangkat' => 'Di Atas Batas', 'gol' => 'V', 'ruang' => 'a', 'gol_ruang' => 'V/a']));
    }

    /**
     * down() CreateMasterKenaikanPangkat menghapus ketiga tabel KP saja; up() membuatnya kembali persis.
     */
    public function testKenaikanPangkatMigrationRollsBackAndUpAgain(): void
    {
        $migration = $this->kpMigration();
        $migration->down();

        foreach (self::TABLES_KP as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        // Tabel lain (pendidikan, FAQ, Batch 1) tidak tersentuh.
        foreach ([...self::TABLES_PENDIDIKAN, 'faq_topic', 'provinsi'] as $table) {
            $this->assertTrue($this->tableExists($table), "{$table} tidak boleh ikut terhapus");
        }

        $migration->up();
        $this->assertSchema();
    }

    /**
     * down() CreateMasterPendidikan menghapus ketiga tabel pendidikan (anak dulu) saja; up() membuatnya kembali persis.
     */
    public function testPendidikanMigrationRollsBackAndUpAgain(): void
    {
        $migration = $this->pendidikanMigration();
        $migration->down();

        foreach (self::TABLES_PENDIDIKAN as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        foreach ([...self::TABLES_KP, 'faq_topic', 'provinsi'] as $table) {
            $this->assertTrue($this->tableExists($table), "{$table} tidak boleh ikut terhapus");
        }

        $migration->up();
        $this->assertSchema();
    }

    /**
     * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat. Bila CREATE ke-3 gagal (tabel
     * penghalang `gol_pppk`, error 1050), `pangkat` dan `jenis_kp` yang dibuat run itu di-drop lalu error dilempar
     * ulang; tabel yang sudah ada sebelum run tidak disentuh, dan up() bisa langsung diulang.
     */
    public function testFailedKenaikanPangkatUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->assertFailedUpCleansUp($this->kpMigration(), 'gol_pppk', ['pangkat', 'jenis_kp']);
    }

    /**
     * Idem untuk pendidikan: penghalang `jurusan_pendidikan` → `jenjang_pendidikan` dan `bidang_pendidikan` (induk FK)
     * yang dibuat run itu di-drop.
     */
    public function testFailedPendidikanUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->assertFailedUpCleansUp($this->pendidikanMigration(), 'jurusan_pendidikan', ['jenjang_pendidikan', 'bidang_pendidikan']);
    }

    /**
     * @param CreateMasterKenaikanPangkat|CreateMasterPendidikan $migration
     * @param list<string>                                       $createdBefore tabel yang dibuat sebelum penghalang
     */
    private function assertFailedUpCleansUp(Migration $migration, string $blockerTable, array $createdBefore): void
    {
        $migration->down();

        $blocker = $this->db->escapeIdentifiers($this->db->prefixTable($blockerTable));
        $this->db->query("CREATE TABLE {$blocker} (`penghalang` INT NOT NULL) ENGINE=InnoDB");

        $error = null;

        try {
            $migration->up();
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, "up() harus gagal karena {$blockerTable} sudah ada.");
        $this->assertStringContainsString($blockerTable, $error->getMessage());

        foreach ($createdBefore as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        // Tabel yang sudah ada sebelum run tidak ikut di-drop.
        $this->assertSame(['penghalang' => 'int'], $this->columns($blockerTable));

        // Setelah penyebabnya dibereskan, up() bisa langsung diulang.
        $this->db->query("DROP TABLE {$blocker}");
        $migration->up();
        $this->assertSchema();
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
        $this->assertSame(self::CHECKS, $this->checks());
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

    private function kpMigration(): CreateMasterKenaikanPangkat
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-110000_CreateMasterKenaikanPangkat.php';

        return new CreateMasterKenaikanPangkat();
    }

    private function pendidikanMigration(): CreateMasterPendidikan
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-110100_CreateMasterPendidikan.php';

        return new CreateMasterPendidikan();
    }

    private function golPppkStatus(int $id): int
    {
        $row = $this->db->query(
            'SELECT status FROM ' . $this->db->prefixTable('gol_pppk') . ' WHERE id_gol_pppk = ?',
            [$id],
        )->getRowArray() ?? [];

        return (int) ($row['status'] ?? -1);
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
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), int(11)); samakan dengan MySQL 8. Tanda tetap ikut.
            $type = (string) preg_replace('/^(tinyint|int)\(\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));

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
     * Default kolom yang dinormalkan antara MySQL 8 dan MariaDB: null bila tanpa default/DEFAULT NULL (MariaDB menulis
     * string 'NULL'), tanpa kutip, huruf kecil, `current_timestamp()` → `current_timestamp`.
     */
    private function defaultOf(string $table, string $column): ?string
    {
        $raw = $this->columnInfo($table, $column)['COLUMN_DEFAULT'] ?? null;

        if ($raw === null || strtoupper((string) $raw) === 'NULL') {
            return null;
        }

        return strtolower(trim((string) $raw, "'()"));
    }

    private function extraOf(string $table, string $column): string
    {
        return strtolower((string) ($this->columnInfo($table, $column)['EXTRA'] ?? ''));
    }

    private function commentOf(string $table, string $column): string
    {
        return (string) ($this->columnInfo($table, $column)['COLUMN_COMMENT'] ?? '');
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
     * Seluruh FK dari/ke keenam tabel: nama => [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE],
     * urut nama. Kolom induk ikut dicek karena MySQL/MariaDB menerima FK ke kolom induk yang sekadar ber-index.
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
     * Seluruh CHECK constraint di keenam tabel: tabel => [nama CHECK], urut tabel lalu nama.
     *
     * @return array<string, list<string>>
     */
    private function checks(): array
    {
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES);

        $rows = $this->db->query(
            "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE = 'CHECK' AND TABLE_NAME IN ?
             ORDER BY TABLE_NAME, CONSTRAINT_NAME",
            [$this->db->getDatabase(), $tables],
        )->getResultArray();

        $checks = [];

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
