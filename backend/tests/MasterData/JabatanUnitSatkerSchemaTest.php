<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\AddFkJabatanJenjangJf;
use App\Database\Migrations\CreateMasterJabatanSisa;
use App\Database\Migrations\CreateMasterJabatanUnitSatker;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\LepasMigrationKepegawaianTrait;

/**
 * DBV-008 — skema G-02 hasil migration 2026-09-30-100000_CreateMasterJabatanUnitSatker harus sama dengan skema yang
 * diajukan ke DB Validator (backend/docs/db-review/G-02-jabatan-unit-satker-schema.md Bagian 2): DDL keenam tabel dari
 * dump struktur produksi lengkap D1 `simpeg01_struktur_lengkap_20261001.sql` [K] (revisi 01-10-2026), plus
 * deviasi v2 (status 10, 5 UNIQUE nama, FK RESTRICT, 5 CHECK). FK `jabatan → jenjang_jf` dibuat migration DBV-018; tabel
 * DBV-018 merujuk tabel G-02, jadi down() G-02 di test ini dijalankan setelah kedua migration DBV-018 di-down. Migration tidak menulis baris apa
 * pun dan bisa di-rollback. FK DBV-019 (snapshot/riwayat → G-02) dilepas lebih dulu dan dipasang ulang paling akhir
 * (Tests\Support\LepasMigrationKepegawaianTrait::lepasFkG02()).
 *
 * @internal
 */
final class JabatanUnitSatkerSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use LepasMigrationKepegawaianTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const TABLES = ['unit', 'satker', 'group_jabatan', 'sub_group_jabatan', 'kelas_jabatan', 'jabatan'];

    /**
     * Tabel ber-PK AUTO_INCREMENT (kelas_jabatan = PK alami, bukan AUTO_INCREMENT).
     */
    private const AUTO_INCREMENT_KEYS = [
        'unit'              => 'id_unit',
        'satker'            => 'id_satker',
        'group_jabatan'     => 'id_group_jabatan',
        'sub_group_jabatan' => 'id_sub_group_jabatan',
        'jabatan'           => 'id_jabatan',
    ];

    /**
     * Tabel yang punya kolom `order` (jabatan & kelas_jabatan tidak, G-02 Bagian 4 #7).
     */
    private const ORDER_TABLES = ['unit', 'satker', 'group_jabatan', 'sub_group_jabatan'];

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi (" null" = boleh NULL).
     */
    private const COLUMNS = [
        'unit' => [
            'id_unit'       => 'int', 'unit' => 'varchar(150)', 'is_upt' => 'tinyint(1)', 'alamat_pdf_header' => 'tinytext null',
            'tembusan_kppn' => 'varchar(256) null', 'lokasi_kppn' => 'varchar(50) null', 'order' => 'int', 'status' => 'tinyint',
            'created_at'    => 'datetime', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'satker' => [
            'id_satker'         => 'int', 'id_unit' => 'int null', 'satker' => 'varchar(150)', 'is_upt' => 'tinyint(1)',
            'alamat_pdf_header' => 'text null', 'tembusan_kppn' => 'varchar(256) null', 'lokasi_kppn' => 'varchar(50) null',
            'logo_uns'          => 'varchar(256) null', 'zonasi' => 'int', 'order' => 'smallint', 'status' => 'tinyint',
            'created_at'        => 'datetime', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'group_jabatan' => [
            'id_group_jabatan' => 'int', 'group_jabatan' => 'varchar(45)', 'status' => 'tinyint(1)', 'order' => 'tinyint',
            'created_at'       => 'datetime', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'sub_group_jabatan' => [
            'id_sub_group_jabatan' => 'int', 'id_group_jabatan' => 'int', 'sub_group_jabatan' => 'varchar(100)',
            'need_satker'          => 'tinyint(1)', 'order' => 'tinyint', 'status' => 'tinyint', 'created_at' => 'datetime',
            'updated_at'           => 'datetime null', 'updated_by' => 'int null',
        ],
        'kelas_jabatan' => [
            'kelas_jabatan' => 'tinyint', 'tukin' => 'int', 'status' => 'tinyint', 'created_at' => 'datetime',
            'updated_at'    => 'datetime null', 'updated_by' => 'int null',
        ],
        'jabatan' => [
            'id_jabatan'    => 'int', 'id_group_jabatan' => 'int null', 'id_sub_group_jabatan' => 'int null', 'id_satker' => 'int null',
            'id_jenjang_jf' => 'tinyint null', 'kelas_jabatan' => 'tinyint null', 'jabatan' => 'varchar(250)',
            'umur_pensiun'  => 'int null', 'status' => 'tinyint', 'created_at' => 'datetime', 'updated_at' => 'datetime null',
            'updated_by'    => 'int null',
        ],
    ];

    /**
     * Index: tabel => [nama index => [NON_UNIQUE, INDEX_TYPE, kolom berurutan]].
     */
    private const INDEXES = [
        'unit' => [
            'PRIMARY'      => [0, 'BTREE', ['id_unit']],
            'uq_unit_nama' => [0, 'BTREE', ['unit']],
        ],
        'satker' => [
            'PRIMARY'                   => [0, 'BTREE', ['id_satker']],
            'uq_satker_nama'            => [0, 'BTREE', ['id_unit', 'satker']],
            'fk_id_unit_satker_to_unit' => [1, 'BTREE', ['id_unit']],
        ],
        'group_jabatan' => [
            'PRIMARY'               => [0, 'BTREE', ['id_group_jabatan']],
            'uq_group_jabatan_nama' => [0, 'BTREE', ['group_jabatan']],
        ],
        'sub_group_jabatan' => [
            'PRIMARY'                   => [0, 'BTREE', ['id_sub_group_jabatan']],
            'uq_sub_group_jabatan_nama' => [0, 'BTREE', ['id_group_jabatan', 'sub_group_jabatan']],
            'id_group_jabatan_idx'      => [1, 'BTREE', ['id_group_jabatan']],
        ],
        'kelas_jabatan' => [
            'PRIMARY' => [0, 'BTREE', ['kelas_jabatan']],
        ],
        'jabatan' => [
            'PRIMARY'                                  => [0, 'BTREE', ['id_jabatan']],
            'uq_jabatan_nama'                          => [0, 'BTREE', ['id_sub_group_jabatan', 'id_satker', 'jabatan']],
            'jabatan'                                  => [1, 'BTREE', ['jabatan']],
            'fk_id_group_jabatan_jabatan_to_gj'        => [1, 'BTREE', ['id_group_jabatan']],
            'fk_id_sub_group_jabatan_jabatan_to_sgj'   => [1, 'BTREE', ['id_sub_group_jabatan']],
            'fk_id_satker_jabatan_to_satker'           => [1, 'BTREE', ['id_satker']],
            'fk_kelas_jabatan_jabatan_to_kelasjabatan' => [1, 'BTREE', ['kelas_jabatan']],
            'fk_id_jenjang_jf_jab_to_jenjang_jf'       => [1, 'BTREE', ['id_jenjang_jf']],
        ],
    ];

    /**
     * Seluruh FK dari/ke keenam tabel, urut nama: [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE].
     * Nama legacy (D1:1468, :1470-1472, :6903, :7194); aksi RESTRICT/RESTRICT [V2]. FK `fk_id_jenjang_jf_jab_to_jenjang_jf`
     * bukan bagian migration ini (dibuat migration DBV-018 bersama tabel jenjang_jf, G-02 Bagian 4 #4).
     */
    private const FOREIGN_KEYS = [
        'fk_id_group_jabatan_jabatan_to_gj'        => ['jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_group_jabatan_sgj_to_gj'            => ['sub_group_jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_satker_jabatan_to_satker'           => ['jabatan', 'id_satker', 'satker', 'id_satker', 'RESTRICT', 'RESTRICT'],
        'fk_id_sub_group_jabatan_jabatan_to_sgj'   => ['jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_unit_satker_to_unit'                => ['satker', 'id_unit', 'unit', 'id_unit', 'RESTRICT', 'RESTRICT'],
        'fk_kelas_jabatan_jabatan_to_kelasjabatan' => ['jabatan', 'kelas_jabatan', 'kelas_jabatan', 'kelas_jabatan', 'RESTRICT', 'RESTRICT'],
    ];

    /**
     * CHECK constraint per tabel (urut nama). Teks CHECK_CLAUSE tidak dibandingkan karena formatnya beda antar server;
     * perilakunya dibuktikan di testConstraintsAreEnforced().
     */
    private const CHECKS = [
        'unit'              => ['chk_unit_is_upt'],
        'satker'            => ['chk_satker_is_upt', 'chk_satker_zonasi'],
        'group_jabatan'     => [],
        'sub_group_jabatan' => ['chk_sub_group_jabatan_need_satker'],
        'kelas_jabatan'     => ['chk_kelas_jabatan_kelas_jabatan'],
        'jabatan'           => [],
    ];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Test rollback/kegagalan memanggil down()/up() di tengah jalan; kalau assertion gagal sebelum skema pulih, tabel
     * G-02 dibuat lagi (termasuk membuang tabel penghalang simulasi) agar migrate:refresh test berikutnya konsisten
     * dengan tabel migrations.
     */
    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($table) || $this->columns($table) !== self::COLUMNS[$table]) {
                $migration = $this->migration();
                $this->downDependents();
                $migration->down();
                $migration->up();
                $this->upDependents();

                break;
            }
        }

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

            $createdAt = $this->columnInfo($table, 'created_at');
            $this->assertSame('current_timestamp', strtolower(trim((string) $createdAt['COLUMN_DEFAULT'], "'()")), "{$table}.created_at default");
            $this->assertStringNotContainsString('on update', strtolower((string) $createdAt['EXTRA']), "{$table}.created_at tanpa ON UPDATE");

            $updatedAt = $this->columnInfo($table, 'updated_at');
            $this->assertTrue($this->isNullDefault($updatedAt['COLUMN_DEFAULT']), "{$table}.updated_at default NULL");
            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $updatedAt['EXTRA']), "{$table}.updated_at ON UPDATE");

            $updatedBy = $this->columnInfo($table, 'updated_by');
            $this->assertTrue($this->isNullDefault($updatedBy['COLUMN_DEFAULT']), "{$table}.updated_by default NULL");
            $this->assertSame('id_pengguna yang terakhir mengubah', $updatedBy['COLUMN_COMMENT'], "{$table}.updated_by COMMENT");
        }

        foreach (self::ORDER_TABLES as $table) {
            $this->assertSame('1', $this->unquote($this->columnInfo($table, 'order')['COLUMN_DEFAULT']), "{$table}.order default 1");
        }

        foreach (self::AUTO_INCREMENT_KEYS as $table => $pk) {
            $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo($table, $pk)['EXTRA']), "{$table}.{$pk}");
        }

        // kelas_jabatan: PK alami tanpa AUTO_INCREMENT dan tanpa default [K] D1:2028.
        $kelas = $this->columnInfo('kelas_jabatan', 'kelas_jabatan');
        $this->assertStringNotContainsString('auto_increment', strtolower((string) $kelas['EXTRA']));
        $this->assertNull($kelas['COLUMN_DEFAULT']);
        $this->assertNull($this->columnInfo('kelas_jabatan', 'tukin')['COLUMN_DEFAULT'], 'tukin wajib diisi (tanpa default)');

        foreach (['unit', 'satker'] as $table) {
            $this->assertSame('0', $this->unquote($this->columnInfo($table, 'is_upt')['COLUMN_DEFAULT']), "{$table}.is_upt default 0");
            $this->assertSame('0: Bukan UPT, 1: UPT', $this->columnInfo($table, 'is_upt')['COLUMN_COMMENT'], "{$table}.is_upt COMMENT");
        }

        $zonasi = $this->columnInfo('satker', 'zonasi');
        $this->assertSame('0', $this->unquote($zonasi['COLUMN_DEFAULT']));
        $this->assertSame('offset jam presensi dari WIB dalam menit: 0 WIB, 60 WITA, 120 WIT', $zonasi['COLUMN_COMMENT']);

        $needSatker = $this->columnInfo('sub_group_jabatan', 'need_satker');
        $this->assertSame('2', $this->unquote($needSatker['COLUMN_DEFAULT']), 'need_satker default 2 (Tidak) [K] D1:7187');
        $this->assertSame('1: Ya, 2: Tidak (jabatan dipilih per satuan kerja di riwayat jabatan)', $needSatker['COLUMN_COMMENT']);

        $this->assertTrue($this->isNullDefault($this->columnInfo('satker', 'logo_uns')['COLUMN_DEFAULT']), 'logo_uns default NULL');
        $this->assertTrue($this->isNullDefault($this->columnInfo('jabatan', 'umur_pensiun')['COLUMN_DEFAULT']), 'umur_pensiun default NULL');
    }

    /**
     * Tanpa seed: baris ber-ID hard-coded legacy datang dari impor ID legacy apa adanya (G-02 Bagian 2.8).
     */
    public function testMigrationCreatesNoRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (koneksi test strictOn=true): UNIQUE case-insensitive termasuk baris status 10, CHECK, FK RESTRICT,
     * NOT NULL. Hanya huruf ASCII untuk uji case-insensitive.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->assertUnitSatkerConstraints();
        $this->assertGroupConstraints();
        $this->assertKelasConstraints();
        $this->assertJabatanConstraints();
    }

    /**
     * down() menghapus keenam tabel (anak sebelum induk), up() membuatnya kembali persis. Tabel lain tidak tersentuh.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $migration = $this->migration();
        $this->downDependents();
        $migration->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        foreach (['provinsi', 'faq_topic', 'pangkat', 'diklat', 'pengguna'] as $other) {
            $this->assertTrue($this->tableExists($other), "tabel {$other} tidak tersentuh");
        }

        $migration->up();
        $this->upDependents();
        $this->assertSchema();
    }

    /**
     * Bila salah satu CREATE gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop (urutan terbalik) lalu
     * error dilempar ulang, sehingga `migrate` bisa langsung diulang tanpa DDL manual. Tabel yang sudah ada sebelum run
     * tidak disentuh. Kegagalan disimulasikan dengan tabel penghalang bernama `kelas_jabatan` (CREATE ke-5 gagal, 1050).
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $migration = $this->migration();
        $this->downDependents();
        $migration->down();

        $blocker = $this->db->escapeIdentifiers($this->db->prefixTable('kelas_jabatan'));
        $this->db->query("CREATE TABLE {$blocker} (`penghalang` INT NOT NULL) ENGINE=InnoDB");

        $error = null;

        try {
            $migration->up();
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena kelas_jabatan sudah ada.');
        $this->assertStringContainsString('kelas_jabatan', $error->getMessage());

        foreach (['unit', 'satker', 'group_jabatan', 'sub_group_jabatan', 'jabatan'] as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        // Tabel yang sudah ada sebelum run tidak ikut di-drop.
        $this->assertSame(['penghalang' => 'int'], $this->columns('kelas_jabatan'));

        // Setelah penyebabnya dibereskan, up() bisa langsung diulang.
        $this->db->query("DROP TABLE {$blocker}");
        $migration->up();
        $this->upDependents();
        $this->assertSchema();
    }

    private function assertUnitSatkerConstraints(): void
    {
        // ID legacy eksplisit diterima (unit 6/7 dan satker 37 di-hard-code kode legacy, G-02 Bagian 2.8).
        $this->db->table('unit')->insert(['id_unit' => 7, 'unit' => 'Sekretariat Kementerian']);
        $this->db->table('unit')->insert(['id_unit' => 6, 'unit' => 'Deputi Bidang Uji', 'is_upt' => 1]);
        $this->seeInDatabase('unit', ['id_unit' => 7, 'is_upt' => 0, 'order' => 1, 'status' => 1, 'updated_by' => null]);
        $this->assertDbWriteFails(fn () => $this->db->table('unit')->insert(['unit' => 'SEKRETARIAT KEMENTERIAN']));
        $this->assertDbWriteFails(fn () => $this->db->table('unit')->insert(['unit' => 'UPT Dua', 'is_upt' => 2]));

        // Baris status 10 tetap memblok nama yang sama.
        $this->db->table('unit')->insert(['unit' => 'Unit Lama', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('unit')->insert(['unit' => 'UNIT LAMA']));

        // Satker: FK ke unit, nama unik per unit, zonasi 0..120, is_upt 0/1.
        $this->db->table('satker')->insert(['id_satker' => 37, 'id_unit' => 7, 'satker' => 'Biro Umum']);
        $this->seeInDatabase('satker', ['id_satker' => 37, 'zonasi' => 0, 'is_upt' => 0, 'order' => 1, 'logo_uns' => null]);
        $this->db->table('satker')->insert(['id_unit' => 6, 'satker' => 'biro umum', 'zonasi' => 120]);
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->insert(['id_unit' => 7, 'satker' => 'BIRO UMUM']));
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->insert(['id_unit' => 99, 'satker' => 'Tanpa Unit']));
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->insert(['id_unit' => 7, 'satker' => 'Zona Negatif', 'zonasi' => -1]));
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->insert(['id_unit' => 7, 'satker' => 'Zona Lewat', 'zonasi' => 121]));
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->insert(['id_unit' => 7, 'satker' => 'UPT Dua', 'is_upt' => 2]));

        // id_unit NULL [K] D1:6888: UNIQUE (id_unit, satker) tidak membandingkan NULL — hanya ditegakkan aplikasi (Bagian 4 #6).
        $this->db->table('satker')->insert(['id_unit' => null, 'satker' => 'Satker Yatim']);
        $this->db->table('satker')->insert(['id_unit' => null, 'satker' => 'Satker Yatim']);
        $this->assertSame(2, $this->db->table('satker')->where('satker', 'Satker Yatim')->countAllResults());

        // FK RESTRICT: unit yang masih punya satker tidak bisa dihapus atau diganti PK-nya.
        $this->assertDbWriteFails(fn () => $this->db->table('unit')->where('id_unit', 7)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('unit')->where('id_unit', 7)->update(['id_unit' => 8]));
        $this->seeInDatabase('unit', ['id_unit' => 7]);
    }

    private function assertGroupConstraints(): void
    {
        $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural']);
        $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 2, 'group_jabatan' => 'Fungsional']);
        $this->assertDbWriteFails(fn () => $this->db->table('group_jabatan')->insert(['group_jabatan' => 'STRUKTURAL']));

        // Sub group: FK ke group, nama unik per group, need_satker 1/2.
        $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => 57, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'Tenaga Ahli']);
        $this->seeInDatabase('sub_group_jabatan', ['id_sub_group_jabatan' => 57, 'need_satker' => 2, 'order' => 1, 'status' => 1]);
        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 2, 'sub_group_jabatan' => 'tenaga ahli', 'need_satker' => 2]);
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'TENAGA AHLI']));
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 99, 'sub_group_jabatan' => 'Tanpa Group']));
        // id_group_jabatan NOT NULL [K] D1:7185; nama NOT NULL [V2] (D1:7186 NULL).
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => null, 'sub_group_jabatan' => 'Group Kosong']));
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => null]));
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Butuh Nol', 'need_satker' => 0]));
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Butuh Tiga', 'need_satker' => 3]));

        // Baris status 10 tetap memblok nama yang sama.
        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon Lama', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'ESELON LAMA']));

        $this->assertDbWriteFails(fn () => $this->db->table('group_jabatan')->where('id_group_jabatan', 1)->delete());
    }

    private function assertKelasConstraints(): void
    {
        // PK alami: nomor kelas diisi eksplisit, 1..20.
        $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 7, 'tukin' => 5079200]);
        $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 20, 'tukin' => 0]);
        $this->seeInDatabase('kelas_jabatan', ['kelas_jabatan' => 7, 'tukin' => 5079200, 'status' => 1]);
        $this->assertDbWriteFails(fn () => $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 7, 'tukin' => 1]));
        $this->assertDbWriteFails(fn () => $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 0, 'tukin' => 1]));
        $this->assertDbWriteFails(fn () => $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 21, 'tukin' => 1]));
        $this->assertDbWriteFails(fn () => $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 9]));
    }

    private function assertJabatanConstraints(): void
    {
        $base = ['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 57, 'kelas_jabatan' => 7];

        $this->db->table('jabatan')->insert(['id_jabatan' => 2202, 'id_satker' => 37, 'jabatan' => 'Tenaga Ahli Menteri'] + $base);
        $this->seeInDatabase('jabatan', ['id_jabatan' => 2202, 'status' => 1, 'umur_pensiun' => null, 'id_jenjang_jf' => null]);

        // Lingkup UNIQUE (sub group, satker): nama sama di satker lain boleh, di satker yang sama tidak.
        $this->db->table('jabatan')->insert(['id_satker' => 38, 'jabatan' => 'tenaga ahli menteri'] + $base);
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['id_satker' => 37, 'jabatan' => 'TENAGA AHLI MENTERI'] + $base));

        // Satker NULL (JF/pelaksana): UNIQUE tidak membandingkan NULL — hanya ditegakkan aplikasi (Bagian 4 #6).
        $this->db->table('jabatan')->insert(['id_satker' => null, 'jabatan' => 'Analis Uji'] + $base);
        $this->db->table('jabatan')->insert(['id_satker' => null, 'jabatan' => 'Analis Uji'] + $base);

        // FK RESTRICT ke group, sub group, satker, kelas.
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['id_group_jabatan' => 99, 'jabatan' => 'Group Yatim']));
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['id_sub_group_jabatan' => 99, 'jabatan' => 'Sub Group Yatim']));
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['id_satker' => 99, 'jabatan' => 'Satker Yatim']));
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['kelas_jabatan' => 11, 'jabatan' => 'Kelas Yatim']));

        // id_jenjang_jf: FK ke jenjang_jf (migration DBV-018) menolak nilai yatim; tabel jenjang_jf kosong di test ini.
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['id_jenjang_jf' => 5, 'jabatan' => 'Jenjang Yatim']));

        // Nama wajib.
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->insert(['id_satker' => 37, 'jabatan' => null] + $base));

        // Hapus/ganti PK induk yang dirujuk jabatan ditolak (1451).
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->where('id_satker', 37)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', 57)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('kelas_jabatan')->where('kelas_jabatan', 7)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('kelas_jabatan')->where('kelas_jabatan', 7)->update(['kelas_jabatan' => 8]));

        $this->seeInDatabase('satker', ['id_satker' => 37]);
        $this->seeInDatabase('jabatan', ['id_jabatan' => 2202, 'id_satker' => 37, 'kelas_jabatan' => 7]);
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
        $this->assertSame(['fk_id_jenjang_jf_jab_to_jenjang_jf'], $this->foreignKeysOn('jabatan', 'id_jenjang_jf'), 'FK jabatan → jenjang_jf dibuat DBV-018');
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

    /**
     * DBV-018: migration yang FK-nya merujuk tabel G-02 (urut up). down() G-02 hanya bisa jalan setelah keduanya di-down.
     *
     * @return list<AddFkJabatanJenjangJf|CreateMasterJabatanSisa>
     */
    private function dependents(): array
    {
        require_once APPPATH . 'Database/Migrations/2026-09-30-100100_CreateMasterJabatanSisa.php';
        require_once APPPATH . 'Database/Migrations/2026-09-30-100200_AddFkJabatanJenjangJf.php';

        return [new CreateMasterJabatanSisa(), new AddFkJabatanJenjangJf()];
    }

    private function downDependents(): void
    {
        $this->lepasFkG02();

        foreach (array_reverse($this->dependents()) as $migration) {
            try {
                $migration->down();
            } catch (\Throwable) {
                // Sudah di-down (mis. tearDown setelah test gagal di tengah): lanjut.
            }
        }
    }

    private function upDependents(): void
    {
        foreach ($this->dependents() as $migration) {
            $migration->up();
        }

        $this->pasangUlangMigrationKepegawaian();
    }

    private function migration(): CreateMasterJabatanUnitSatker
    {
        require_once APPPATH . 'Database/Migrations/2026-09-30-100000_CreateMasterJabatanUnitSatker.php';

        return new CreateMasterJabatanUnitSatker();
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
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), smallint(6), int(11)); samakan dengan MySQL 8. Kecuali
            // tinyint(1): kedua server menampilkannya, jadi is_upt/status group_jabatan TINYINT(1) terbukti persis.
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
     *                                                                  seperti konstanta INDEXES
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
     * Seluruh FK di antara keenam tabel G-02 (FK dari/ke tabel DBV-018 diuji JabatanSisaSchemaTest): nama => [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE],
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
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN ? AND k.REFERENCED_TABLE_NAME IN ?
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
     * CHECK constraint keenam tabel: tabel => [nama], urut nama. Memakai TABLE_CONSTRAINTS (ada TABLE_NAME di MySQL 8
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
