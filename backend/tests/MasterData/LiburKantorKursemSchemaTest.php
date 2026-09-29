<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\CreateHariLibur;
use App\Database\Migrations\CreateKantor;
use App\Database\Migrations\CreateKursem;
use App\Database\Migrations\SeedWilayahLainLain;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;

/**
 * DBV-003 — skema G-07/G-08 hasil migration 2026-09-25-100000_CreateHariLibur, 2026-09-25-100100_CreateKursem,
 * 2026-09-25-100200_SeedWilayahLainLain (baris sentinel LAIN-LAIN di wilayah), dan 2026-09-25-100300_CreateKantor
 * harus sama dengan skema yang diajukan ke DB Validator
 * (backend/docs/db-review/G-07-G-08-kantor-hari-libur-kursem-schema.md): DDL legacy `hari_libur`, `bidang_kursem`,
 * `instansi_kursem` [K], kolom `jenis_libur`/`kantor` dari kode legacy dengan tipe [I], plus deviasi v2 (FK RESTRICT
 * nama legacy, `hari_libur.status`, UNIQUE `tgl_mulai`, CHECK rentang, UNIQUE nama, `order` kursem, status 10), dan
 * bisa di-rollback.
 *
 * Query CHECK sengaja portabel antara MySQL 8 dan MariaDB 10.4: `information_schema.CHECK_CONSTRAINTS` hanya disaring
 * lewat schema + nama (MySQL tidak punya kolom TABLE_NAME di sana), CHECK_CLAUSE dinormalkan (format kurung berbeda),
 * dan kode error pelanggaran CHECK diterima 3819 (MySQL) atau 4025 (MariaDB).
 *
 * @internal
 */
final class LiburKantorKursemSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const TABLES = ['jenis_libur', 'hari_libur', 'bidang_kursem', 'instansi_kursem', 'kantor'];

    /**
     * Migration DBV-003: file => [kelas, tabel yang dibuat (urut induk → anak)].
     *
     * @var array<string, array{0: class-string<Migration>, 1: list<string>}>
     */
    private const MIGRATIONS = [
        '2026-09-25-100000_CreateHariLibur.php' => [CreateHariLibur::class, ['jenis_libur', 'hari_libur']],
        '2026-09-25-100100_CreateKursem.php'    => [CreateKursem::class, ['bidang_kursem', 'instansi_kursem']],
        '2026-09-25-100300_CreateKantor.php'    => [CreateKantor::class, ['kantor']],
    ];

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi (" null" = boleh NULL).
     */
    private const COLUMNS = [
        'jenis_libur' => [
            'id_jenis_libur' => 'tinyint', 'jenis_libur' => 'varchar(255)', 'order' => 'tinyint', 'status' => 'tinyint',
            'created_at'     => 'datetime', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'hari_libur' => [
            'id_libur'   => 'int', 'id_jenis_libur' => 'tinyint null', 'tgl_mulai' => 'date', 'tgl_akhir' => 'date',
            'nama_libur' => 'varchar(100)', 'keterangan' => 'text null', 'status' => 'tinyint', 'created_at' => 'datetime',
            'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'bidang_kursem' => [
            'id_bidang_kursem' => 'tinyint', 'bidang_kursem' => 'varchar(255)', 'order' => 'tinyint', 'status' => 'tinyint',
            'created_at'       => 'datetime', 'updated_at' => 'datetime null',
        ],
        'instansi_kursem' => [
            'id_instansi_kursem' => 'tinyint', 'instansi_kursem' => 'varchar(255)', 'order' => 'tinyint', 'status' => 'tinyint',
            'created_at'         => 'datetime', 'updated_at' => 'datetime null',
        ],
        'kantor' => [
            'id_kantor'      => 'int', 'order' => 'int', 'nama_kantor' => 'varchar(255)', 'alamat' => 'text',
            'id_provinsi'    => 'char(2)', 'provinsi_lain' => 'varchar(255) null', 'id_kabupaten' => 'char(4)',
            'kabupaten_lain' => 'varchar(255) null', 'id_kecamatan' => 'char(7)', 'kecamatan_lain' => 'varchar(255) null',
            'id_kelurahan'   => 'char(10)', 'kelurahan_lain' => 'varchar(255) null', 'kode_pos' => 'char(5) null',
            'telp'           => 'varchar(50) null', 'faks' => 'varchar(50) null', 'remark' => 'text null', 'status' => 'tinyint',
            'created_at'     => 'datetime', 'created_by' => 'int null', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
    ];

    /**
     * Index: tabel => [nama index => [NON_UNIQUE, INDEX_TYPE, kolom berurutan]].
     */
    private const INDEXES = [
        'jenis_libur' => [
            'PRIMARY'             => [0, 'BTREE', ['id_jenis_libur']],
            'uq_jenis_libur_nama' => [0, 'BTREE', ['jenis_libur']],
        ],
        'hari_libur' => [
            'PRIMARY'                                   => [0, 'BTREE', ['id_libur']],
            'uq_hari_libur_tgl_mulai'                   => [0, 'BTREE', ['tgl_mulai']],
            'fk_id_jenis_libur_harilibur_to_jenislibur' => [1, 'BTREE', ['id_jenis_libur']],
        ],
        'bidang_kursem' => [
            'PRIMARY'               => [0, 'BTREE', ['id_bidang_kursem']],
            'uq_bidang_kursem_nama' => [0, 'BTREE', ['bidang_kursem']],
        ],
        'instansi_kursem' => [
            'PRIMARY'                 => [0, 'BTREE', ['id_instansi_kursem']],
            'uq_instansi_kursem_nama' => [0, 'BTREE', ['instansi_kursem']],
        ],
        'kantor' => [
            'PRIMARY'                       => [0, 'BTREE', ['id_kantor']],
            'uq_kantor_nama'                => [0, 'BTREE', ['nama_kantor']],
            'fk_id_provinsi_kantor_to_prov' => [1, 'BTREE', ['id_provinsi']],
            'fk_id_kabupaten_kantor_to_kab' => [1, 'BTREE', ['id_kabupaten']],
            'fk_id_kecamatan_kantor_to_kec' => [1, 'BTREE', ['id_kecamatan']],
            'fk_id_kelurahan_kantor_to_kel' => [1, 'BTREE', ['id_kelurahan']],
        ],
    ];

    /**
     * FK nama legacy (DDL simpeg_prod.sql:1320 + ERD simpeg01), urut nama: [tabel anak, kolom, tabel induk, kolom induk,
     * UPDATE_RULE, DELETE_RULE]. Kolom induk ikut dicek, termasuk `kantor.id_kabupaten` → `kabupaten_kota.id_kabupaten_kota`
     * (nama kolom anak legacy berbeda dengan induk). Aksi RESTRICT/RESTRICT = deviasi v2.
     */
    private const FOREIGN_KEYS = [
        'fk_id_jenis_libur_harilibur_to_jenislibur' => ['hari_libur', 'id_jenis_libur', 'jenis_libur', 'id_jenis_libur', 'RESTRICT', 'RESTRICT'],
        'fk_id_kabupaten_kantor_to_kab'             => ['kantor', 'id_kabupaten', 'kabupaten_kota', 'id_kabupaten_kota', 'RESTRICT', 'RESTRICT'],
        'fk_id_kecamatan_kantor_to_kec'             => ['kantor', 'id_kecamatan', 'kecamatan', 'id_kecamatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_kelurahan_kantor_to_kel'             => ['kantor', 'id_kelurahan', 'kelurahan', 'id_kelurahan', 'RESTRICT', 'RESTRICT'],
        'fk_id_provinsi_kantor_to_prov'             => ['kantor', 'id_provinsi', 'provinsi', 'id_provinsi', 'RESTRICT', 'RESTRICT'],
    ];

    /**
     * Kolom kode wilayah kantor => [tabel wilayah, PK]: collation kolom FK harus sama dengan PK induk (error 3780).
     */
    private const KANTOR_WILAYAH = [
        'id_provinsi'  => ['provinsi', 'id_provinsi'],
        'id_kabupaten' => ['kabupaten_kota', 'id_kabupaten_kota'],
        'id_kecamatan' => ['kecamatan', 'id_kecamatan'],
        'id_kelurahan' => ['kelurahan', 'id_kelurahan'],
    ];

    private const CHECK_NAME   = 'chk_hari_libur_rentang';
    private const CHECK_CLAUSE = 'tgl_akhir>=tgl_mulai';

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Tabel wilayah => key master di Config\MasterData (systemIds = kode sentinel di aplikasi).
     */
    private const WILAYAH_MASTER = [
        'provinsi'       => 'provinsi',
        'kabupaten_kota' => 'kabupaten-kota',
        'kecamatan'      => 'kecamatan',
        'kelurahan'      => 'kelurahan',
    ];

    /**
     * Kode error MySQL/MariaDB yang diharapkan dari pelanggaran constraint.
     */
    private const ERR_DUPLICATE    = [1062];
    private const ERR_NO_PARENT    = [1452];
    private const ERR_REFERENCED   = [1451];
    private const ERR_CHECK        = [3819, 4025];
    private const ERR_OUT_OF_RANGE = [1264];

    /**
     * Test rollback/kegagalan memanggil down()/up() di tengah jalan; kalau assertion gagal sebelum skema pulih, tabel
     * DBV-003 harus dibuat lagi (termasuk membuang tabel penghalang simulasi) agar migrate:refresh test berikutnya
     * konsisten dengan tabel migrations.
     */
    protected function tearDown(): void
    {
        foreach (self::MIGRATIONS as $file => [, $tables]) {
            foreach ($tables as $table) {
                if (! $this->tableExists($table)) {
                    $migration = $this->migration($file);
                    $migration->down();
                    $migration->up();

                    break;
                }
            }
        }

        // Sentinel yang dilepas/dirusak testWilayahSentinelRows dipasang ulang (tabel migrations mencatatnya sudah jalan).
        if ($this->sentinelCount() !== count(SeedWilayahLainLain::ROWS)) {
            $this->db->table('kantor')->emptyTable();
            $this->sentinelMigration()->down();
            $this->sentinelMigration()->up();
        }

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSchema();
    }

    /**
     * CHECK pertama di repo: terdaftar di information_schema (query portabel) dan tampil di SHOW CREATE TABLE.
     */
    public function testCheckConstraintPresent(): void
    {
        $rows = $this->db->query(
            'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = ?',
            [$this->db->getDatabase(), self::CHECK_NAME],
        )->getResultArray();

        $this->assertCount(1, $rows, 'tepat satu CHECK ' . self::CHECK_NAME);
        $this->assertSame(self::CHECK_CLAUSE, $this->normalizeClause((string) $rows[0]['CHECK_CLAUSE']));

        $create = (string) ($this->db->query('SHOW CREATE TABLE ' . $this->t('hari_libur'))->getRowArray()['Create Table'] ?? '');
        $this->assertStringContainsString('CONSTRAINT `' . self::CHECK_NAME . '` CHECK', $create);

        // Tidak ada CHECK lain di tabel DBV-003.
        foreach (['jenis_libur', 'bidang_kursem', 'instansi_kursem', 'kantor'] as $table) {
            $other = (string) ($this->db->query('SHOW CREATE TABLE ' . $this->t($table))->getRowArray()['Create Table'] ?? '');
            $this->assertStringNotContainsString(' CHECK ', $other, "{$table} tanpa CHECK");
        }
    }

    public function testStatusOrderAuditDetails(): void
    {
        $pks = [
            'jenis_libur'     => 'id_jenis_libur',
            'hari_libur'      => 'id_libur',
            'bidang_kursem'   => 'id_bidang_kursem',
            'instansi_kursem' => 'id_instansi_kursem',
            'kantor'          => 'id_kantor',
        ];

        foreach ($pks as $table => $pk) {
            $status = $this->columnInfo($table, 'status');
            $this->assertSame('1', $this->unquote($status['COLUMN_DEFAULT']), "{$table}.status default 1 (Aktif)");
            $this->assertSame(self::STATUS_COMMENT, $status['COLUMN_COMMENT'], "{$table}.status COMMENT");

            $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo($table, $pk)['EXTRA']), "{$table}.{$pk}");
            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $this->columnInfo($table, 'updated_at')['EXTRA']), "{$table}.updated_at");
            $this->assertSame('current_timestamp', strtolower(trim((string) $this->columnInfo($table, 'created_at')['COLUMN_DEFAULT'], "'()")), "{$table}.created_at");
        }

        foreach (['jenis_libur', 'bidang_kursem', 'instansi_kursem', 'kantor'] as $table) {
            $this->assertSame('1', $this->unquote($this->columnInfo($table, 'order')['COLUMN_DEFAULT']), "{$table}.order default 1");
        }

        foreach (['jenis_libur', 'hari_libur', 'kantor'] as $table) {
            $this->assertSame('id_pengguna yang terakhir mengubah', $this->columnInfo($table, 'updated_by')['COLUMN_COMMENT'], "{$table}.updated_by");
        }

        $this->assertSame('id_pengguna pembuat', $this->columnInfo('kantor', 'created_by')['COLUMN_COMMENT']);

        // Kursem tanpa *_by [K]; hari_libur tanpa order (urut tgl_mulai) dan tanpa created_by [K]; jenis_libur tanpa
        // created_by (pola Batch 1).
        foreach (['bidang_kursem', 'instansi_kursem'] as $table) {
            $this->assertArrayNotHasKey('created_by', $this->columns($table), $table);
            $this->assertArrayNotHasKey('updated_by', $this->columns($table), $table);
        }

        $this->assertArrayNotHasKey('order', $this->columns('hari_libur'));
        $this->assertArrayNotHasKey('created_by', $this->columns('hari_libur'));
        $this->assertArrayNotHasKey('created_by', $this->columns('jenis_libur'));

        // id_jenis_libur boleh NULL di DB (F5), default NULL.
        $this->assertContains($this->columnInfo('hari_libur', 'id_jenis_libur')['COLUMN_DEFAULT'], [null, 'NULL']);
    }

    /**
     * Lapis DB hari libur: CHECK rentang, UNIQUE tgl_mulai lintas status (Paket A), tanpa UNIQUE nama (F4),
     * id_jenis_libur NULL diterima (F5), FK RESTRICT ke jenis_libur.
     */
    public function testHariLiburConstraintsAreEnforced(): void
    {
        $this->db->table('jenis_libur')->insertBatch([
            ['id_jenis_libur' => 1, 'jenis_libur' => 'Libur Nasional', 'order' => 1],
            ['id_jenis_libur' => 2, 'jenis_libur' => 'Cuti Bersama', 'order' => 2],
        ]);

        $libur = static fn (string $mulai, string $akhir, string $nama, ?int $jenis = 1, int $status = 1): array => [
            'id_jenis_libur' => $jenis, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'nama_libur' => $nama, 'status' => $status,
        ];

        // CHECK: akhir < mulai ditolak; tanggal sama diterima.
        $this->assertDbWriteFails(fn () => $this->db->table('hari_libur')->insert($libur('2026-01-02', '2026-01-01', 'Terbalik')), self::ERR_CHECK);
        $this->db->table('hari_libur')->insert($libur('2026-01-01', '2026-01-01', 'Tahun Baru Masehi'));
        $this->seeInDatabase('hari_libur', ['tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-01-01']);

        // CHECK juga menolak UPDATE yang membalik rentang.
        $this->assertDbWriteFails(
            fn () => $this->db->table('hari_libur')->where('tgl_mulai', '2026-01-01')->update(['tgl_akhir' => '2025-12-31']),
            self::ERR_CHECK,
        );

        // UNIQUE tgl_mulai berlaku juga terhadap baris status 10 (Paket A).
        $this->db->table('hari_libur')->insert($libur('2026-03-20', '2026-03-21', 'Idul Fitri (dihapus)', 1, 10));
        $this->assertDbWriteFails(fn () => $this->db->table('hari_libur')->insert($libur('2026-03-20', '2026-03-20', 'Idul Fitri')), self::ERR_DUPLICATE);

        // Tanpa UNIQUE nama: nama sama di tahun berbeda diterima.
        $this->db->table('hari_libur')->insert($libur('2027-01-01', '2027-01-01', 'Tahun Baru Masehi'));
        $this->assertSame(2, $this->db->table('hari_libur')->where('nama_libur', 'Tahun Baru Masehi')->countAllResults());

        // id_jenis_libur NULL diterima DB (wajib di aplikasi); id tak dikenal ditolak FK.
        $this->db->table('hari_libur')->insert($libur('2026-05-01', '2026-05-01', 'Hari Buruh', null));
        $this->seeInDatabase('hari_libur', ['tgl_mulai' => '2026-05-01', 'id_jenis_libur' => null]);
        $this->assertDbWriteFails(fn () => $this->db->table('hari_libur')->insert($libur('2026-06-01', '2026-06-01', 'Tanpa Jenis', 99)), self::ERR_NO_PARENT);

        // FK RESTRICT: hard delete dan ubah PK jenis yang dirujuk ditolak; jenis yang tidak dirujuk boleh dihapus.
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_libur')->where('id_jenis_libur', 1)->delete(), self::ERR_REFERENCED);
        $this->assertDbWriteFails(fn () => $this->db->table('jenis_libur')->where('id_jenis_libur', 1)->update(['id_jenis_libur' => 3]), self::ERR_REFERENCED);
        $this->seeInDatabase('jenis_libur', ['id_jenis_libur' => 1, 'jenis_libur' => 'Libur Nasional']);

        $this->db->table('jenis_libur')->where('id_jenis_libur', 2)->delete();
        $this->dontSeeInDatabase('jenis_libur', ['id_jenis_libur' => 2]);
    }

    /**
     * UNIQUE nama master tidak peka huruf besar/kecil maupun aksen (utf8mb4_unicode_ci), termasuk nama_kantor global.
     */
    public function testMasterNameUniquenessIsCaseInsensitive(): void
    {
        $names = [
            'jenis_libur'     => ['Libur Nasional', 'LIBUR NASIONAL', 'Libur Nasiónal'],
            'bidang_kursem'   => ['Teknologi Informasi', 'teknologi informasi', 'Teknologi Infórmasi'],
            'instansi_kursem' => ['Lembaga Administrasi Negara', 'LEMBAGA administrasi NEGARA', 'Lembaga Administrasi Négara'],
        ];

        foreach ($names as $table => [$name, $upper, $accent]) {
            $this->db->table($table)->insert([$table => $name]);
            $this->assertDbWriteFails(fn () => $this->db->table($table)->insert([$table => $upper]), self::ERR_DUPLICATE, "{$table} huruf besar");
            $this->assertDbWriteFails(fn () => $this->db->table($table)->insert([$table => $accent]), self::ERR_DUPLICATE, "{$table} aksen");
            $this->assertSame(1, $this->db->table($table)->countAllResults(), $table);
        }

        $this->seedWilayahUji();
        $this->db->table('kantor')->insert($this->kantorRow('Kantor Pusat'));

        // Global: nama sama di wilayah lain juga ditolak.
        $this->assertDbWriteFails(fn () => $this->db->table('kantor')->insert($this->kantorRow('KANTOR PUSAT')), self::ERR_DUPLICATE, 'kantor huruf besar');
        $this->assertDbWriteFails(fn () => $this->db->table('kantor')->insert($this->kantorRow('Kantor Púsat', ['id_provinsi' => '32'])), self::ERR_DUPLICATE, 'kantor aksen, wilayah lain');
        $this->assertSame(1, $this->db->table('kantor')->countAllResults());
    }

    /**
     * PK TINYINT signed [K]: maksimal 127 ID (koneksi test strictOn=true menolak 128; soft delete tidak membebaskan ID).
     */
    public function testTinyintPrimaryKeyLimit(): void
    {
        foreach (['jenis_libur', 'bidang_kursem', 'instansi_kursem'] as $table) {
            $this->db->table($table)->insert(["id_{$table}" => 127, $table => 'Batas atas']);
            $this->seeInDatabase($table, ["id_{$table}" => 127]);
            $this->assertDbWriteFails(fn () => $this->db->table($table)->insert(["id_{$table}" => 128, $table => 'Lewat batas']), self::ERR_OUT_OF_RANGE, $table);
        }
    }

    /**
     * FK kantor → wilayah (nama ERD, RESTRICT/RESTRICT). FK per kolom tidak memeriksa rantai: kombinasi wilayah yang
     * tidak konsisten diterima DB, penegakannya di aplikasi (KantorHooks, F8).
     */
    public function testKantorForeignKeysToWilayah(): void
    {
        $this->seedWilayahUji();

        $this->db->table('kantor')->insert($this->kantorRow('Kantor Pusat'));
        $this->seeInDatabase('kantor', ['nama_kantor' => 'Kantor Pusat', 'id_kabupaten' => '3171', 'status' => 1]);

        $unknown = [
            'id_provinsi'  => '33',
            'id_kabupaten' => '3199',
            'id_kecamatan' => '3171099',
            'id_kelurahan' => '3171010099',
        ];

        foreach ($unknown as $column => $code) {
            $this->assertDbWriteFails(
                fn () => $this->db->table('kantor')->insert($this->kantorRow("Kode tak dikenal {$column}", [$column => $code])),
                self::ERR_NO_PARENT,
                $column,
            );
        }

        // Rantai tidak konsisten (provinsi 32, kabupaten 3171 milik 31) tetap diterima DB.
        $this->db->table('kantor')->insert($this->kantorRow('Kantor Rantai Campur', ['id_provinsi' => '32']));
        $this->seeInDatabase('kantor', ['nama_kantor' => 'Kantor Rantai Campur', 'id_provinsi' => '32']);

        // Provinsi 32 dan kelurahan 3171010001 hanya dirujuk kantor: penolakan murni dari FK kantor.
        $this->assertDbWriteFails(fn () => $this->db->table('provinsi')->where('id_provinsi', '32')->delete(), self::ERR_REFERENCED, 'hapus provinsi');
        $this->assertDbWriteFails(fn () => $this->db->table('kelurahan')->where('id_kelurahan', '3171010001')->delete(), self::ERR_REFERENCED, 'hapus kelurahan');
        $this->assertDbWriteFails(fn () => $this->db->table('provinsi')->where('id_provinsi', '32')->update(['id_provinsi' => '39']), self::ERR_REFERENCED, 'ubah PK provinsi');
        $this->assertDbWriteFails(fn () => $this->db->table('kelurahan')->where('id_kelurahan', '3171010001')->update(['id_kelurahan' => '3171010009']), self::ERR_REFERENCED, 'ubah PK kelurahan');

        // Setelah rujukan kantor dihapus, wilayah itu bisa dihapus.
        $this->db->table('kantor')->where('nama_kantor', 'Kantor Rantai Campur')->delete();
        $this->db->table('provinsi')->where('id_provinsi', '32')->delete();
        $this->dontSeeInDatabase('provinsi', ['id_provinsi' => '32']);
    }

    /**
     * Migration 100200: 4 baris sentinel LAIN-LAIN persis G-doc 2.5 (rantai 99 → 9999 → 9999999 → 9999999999, `order` 0,
     * status 1, kolom opsional NULL) dan sama dengan `systemIds` Config\MasterData; up() fail-closed bila satu kode
     * sudah ada (tanpa insert parsial); down() transaksional dan menolak selama sentinel masih dirujuk (FK RESTRICT
     * 1451); kantor berkode LAIN-LAIN + `*_lain` diterima DB.
     */
    public function testWilayahSentinelRows(): void
    {
        foreach (SeedWilayahLainLain::ROWS as $table => [$pk, $code, $parentColumn, $parentCode]) {
            $row = $this->db->table($table)->where($pk, $code)->get()->getRowArray();

            $this->assertNotNull($row, "{$table} {$code}");
            $this->assertSame(SeedWilayahLainLain::NAME, $row[$table], $table);
            $this->assertSame([0, 1], [(int) $row['order'], (int) $row['status']], $table);
            $this->assertNotNull($row['created_at'], $table);
            $this->assertSame([null, null], [$row['updated_at'], $row['updated_by']], $table);

            if ($parentColumn !== null) {
                $this->assertSame($parentCode, $row[$parentColumn], $table);
            }

            // Literal migration = satu-satunya kode sistem master itu di aplikasi.
            $this->assertSame([$code], service('masterRegistry')->get(self::WILAYAH_MASTER[$table])->systemIds, $table);
        }

        $this->assertNull($this->db->table('kabupaten_kota')->where('id_kabupaten_kota', '9999')->get()->getRowArray()['kd_area']);
        $this->assertNull($this->db->table('kelurahan')->where('id_kelurahan', '9999999999')->get()->getRowArray()['kd_pos']);
        $this->assertSame(['99', '9999', '9999999', '9999999999'], array_column(array_values(SeedWilayahLainLain::ROWS), 1));

        // Kantor berkode LAIN-LAIN di keempat level (teks di *_lain) diterima DB.
        $this->db->table('kantor')->insert([
            'nama_kantor'    => 'KBRI Tokyo', 'alamat' => 'Minato-ku, Tokyo',
            'id_provinsi'    => '99', 'provinsi_lain' => 'Jepang', 'id_kabupaten' => '9999', 'kabupaten_lain' => 'Tokyo',
            'id_kecamatan'   => '9999999', 'kecamatan_lain' => 'Minato', 'id_kelurahan' => '9999999999',
            'kelurahan_lain' => 'Higashi-Gotanda', 'kode_pos' => '14100',
        ]);
        $this->seeInDatabase('kantor', ['nama_kantor' => 'KBRI Tokyo', 'id_kelurahan' => '9999999999', 'kelurahan_lain' => 'Higashi-Gotanda']);

        // down() menolak selama dirujuk kantor; seluruh baris tetap utuh.
        $this->assertSentinelDownRefused('kelurahan 9999999999');
        $this->db->table('kantor')->emptyTable();

        // Transaksional: kantor yang hanya merujuk provinsi 99 (level lain riil) membuat DELETE terakhir gagal —
        // tiga DELETE sebelumnya ikut dibatalkan.
        $this->seedWilayahUji();
        $this->db->table('kantor')->insert($this->kantorRow('Kantor Campur', ['id_provinsi' => '99']));
        $this->assertSentinelDownRefused('provinsi 99');
        $this->db->table('kantor')->emptyTable();

        // Tanpa rujukan: down() menghapus keempatnya.
        $this->sentinelMigration()->down();
        $this->assertSame(0, $this->sentinelCount());

        // up() fail-closed: satu kode saja sudah ada → tidak ada yang ditulis, pesan menyebut tabel + kode.
        $this->db->table('provinsi')->insert(['id_provinsi' => '99', 'provinsi' => 'Sisa Impor']);

        try {
            $this->sentinelMigration()->up();
            $this->fail('up() harus menolak bila baris sentinel sudah ada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah ada (provinsi 99)', $e->getMessage());
        }

        $this->assertSame(1, $this->sentinelCount());
        $this->seeInDatabase('provinsi', ['id_provinsi' => '99', 'provinsi' => 'Sisa Impor']);

        $this->db->table('provinsi')->where('id_provinsi', '99')->delete();
        $this->sentinelMigration()->up();
        $this->assertSame(count(SeedWilayahLainLain::ROWS), $this->sentinelCount());
    }

    /**
     * down() tiap migration menghapus tabelnya saja; up() membuatnya kembali persis.
     */
    public function testMigrationsRollBackAndUpAgain(): void
    {
        foreach (self::MIGRATIONS as $file => [, $tables]) {
            $migration = $this->migration($file);
            $migration->down();

            foreach (self::TABLES as $table) {
                $this->assertSame(! in_array($table, $tables, true), $this->tableExists($table), "{$file}: {$table}");
            }

            // Tabel Batch 1 dan FAQ tidak tersentuh.
            $this->assertTrue($this->tableExists('provinsi'), $file);
            $this->assertTrue($this->tableExists('faq_topic'), $file);

            $migration->up();
            $this->assertSchema();
        }
    }

    /**
     * Bila salah satu CREATE gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop lalu error dilempar
     * ulang, sehingga `migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh. Kegagalan
     * disimulasikan dengan tabel penghalang bernama tabel kedua (error 1050).
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $cases = [
            '2026-09-25-100000_CreateHariLibur.php' => ['hari_libur', 'jenis_libur'],
            '2026-09-25-100100_CreateKursem.php'    => ['instansi_kursem', 'bidang_kursem'],
        ];

        foreach ($cases as $file => [$blocked, $createdFirst]) {
            $migration = $this->migration($file);
            $migration->down();

            $blocker = $this->t($blocked);
            $this->db->query("CREATE TABLE {$blocker} (`penghalang` INT NOT NULL) ENGINE=InnoDB");

            $error = null;

            try {
                $migration->up();
            } catch (\Throwable $e) {
                $error = $e;
            }

            $this->assertNotNull($error, "{$file}: up() harus gagal karena {$blocked} sudah ada.");
            $this->assertStringContainsString($blocked, $error->getMessage());
            $this->assertFalse($this->tableExists($createdFirst), "{$file}: {$createdFirst} tidak boleh tertinggal");

            // Tabel yang sudah ada sebelum run tidak ikut di-drop.
            $this->assertSame(['penghalang' => 'int'], $this->columns($blocked));

            // Setelah penyebabnya dibereskan, up() bisa langsung diulang.
            $this->db->query("DROP TABLE {$blocker}");
            $migration->up();
            $this->assertSchema();
        }
    }

    private function assertSchema(): void
    {
        foreach (self::TABLES as $table) {
            $info = $this->tableInfo($table);

            $this->assertSame(self::COLUMNS[$table], $this->columns($table), "kolom {$table}");
            $this->assertSame('utf8mb4_unicode_ci', (string) $info['TABLE_COLLATION'], "collation {$table}");
            $this->assertSame('InnoDB', (string) $info['ENGINE'], "engine {$table}");
            $this->assertSame('dynamic', strtolower((string) $info['ROW_FORMAT']), "row format {$table}");
            $this->assertSame(self::INDEXES[$table], $this->indexes($table), "index {$table}");

            foreach ($this->stringColumnCollations($table) as $column => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "collation {$table}.{$column}");
            }
        }

        $this->assertSame(self::FOREIGN_KEYS, $this->foreignKeys());

        foreach (self::KANTOR_WILAYAH as $column => [$parent, $pk]) {
            $this->assertSame(
                $this->stringColumnCollations($parent)[$pk] ?? null,
                $this->stringColumnCollations('kantor')[$column] ?? null,
                "collation kantor.{$column} = {$parent}.{$pk}",
            );
        }
    }

    /**
     * down() sentinel harus menolak (1451, pesan menyebut baris yang masih dirujuk) dan keempat baris tetap ada.
     */
    private function assertSentinelDownRefused(string $row): void
    {
        try {
            $this->sentinelMigration()->down();
            $this->fail("down() sentinel harus menolak selama {$row} dirujuk.");
        } catch (RuntimeException $e) {
            $this->assertSame(1451, $e->getCode());
            $this->assertStringContainsString("masih dirujuk ({$row})", $e->getMessage());
        }

        $this->assertSame(count(SeedWilayahLainLain::ROWS), $this->sentinelCount(), $row);
    }

    /**
     * Jumlah baris sentinel yang ada di keempat tabel wilayah.
     */
    private function sentinelCount(): int
    {
        $count = 0;

        foreach (SeedWilayahLainLain::ROWS as $table => [$pk, $code]) {
            $count += $this->db->table($table)->where($pk, $code)->countAllResults();
        }

        return $count;
    }

    private function sentinelMigration(): SeedWilayahLainLain
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-100200_SeedWilayahLainLain.php';

        return new SeedWilayahLainLain();
    }

    /**
     * Penulisan harus ditolak DB dengan salah satu kode error yang diharapkan.
     *
     * @param list<int> $codes
     */
    private function assertDbWriteFails(callable $write, array $codes, string $message = ''): void
    {
        $code = null;

        try {
            if ($write() === false) {
                $code = (int) $this->db->error()['code'];
            }
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
        }

        $this->assertNotNull($code, trim("Constraint DB harus menolak penulisan ini. {$message}"));
        $this->assertContains($code, $codes, trim("Kode error tidak sesuai. {$message}"));
    }

    /**
     * Rantai wilayah uji 31/3171/3171010/3171010001 + provinsi 32 tanpa anak (hanya untuk dirujuk kantor).
     */
    private function seedWilayahUji(): void
    {
        $this->db->table('provinsi')->insertBatch([
            ['id_provinsi' => '31', 'provinsi' => 'DKI Jakarta'],
            ['id_provinsi' => '32', 'provinsi' => 'Jawa Barat'],
        ]);
        $this->db->table('kabupaten_kota')->insert(['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Pusat']);
        $this->db->table('kecamatan')->insert(['id_kecamatan' => '3171010', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Gambir']);
        $this->db->table('kelurahan')->insert(['id_kelurahan' => '3171010001', 'id_kecamatan' => '3171010', 'kelurahan' => 'Gambir', 'kd_pos' => '10110']);
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function kantorRow(string $nama, array $override = []): array
    {
        return $override + [
            'nama_kantor'  => $nama,
            'alamat'       => 'Jl. Medan Merdeka Barat No. 17',
            'id_provinsi'  => '31',
            'id_kabupaten' => '3171',
            'id_kecamatan' => '3171010',
            'id_kelurahan' => '3171010001',
            'kode_pos'     => '10110',
        ];
    }

    private function migration(string $file): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file;

        $class = self::MIGRATIONS[$file][0];

        return new $class();
    }

    /**
     * CHECK_CLAUSE MySQL `(`tgl_akhir` >= `tgl_mulai`)` dan MariaDB `` `tgl_akhir` >= `tgl_mulai` `` → `tgl_akhir>=tgl_mulai`.
     */
    private function normalizeClause(string $clause): string
    {
        return strtolower((string) preg_replace('/[`()\s]/', '', $clause));
    }

    private function unquote(mixed $value): string
    {
        return trim((string) $value, "'");
    }

    /**
     * Nama tabel ber-prefix yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
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
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), int(11)); samakan dengan MySQL 8.
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

    /**
     * @return array<string, mixed>
     */
    private function tableInfo(string $table): array
    {
        return $this->db->query(
            'SELECT TABLE_COLLATION, ENGINE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
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
     * Seluruh FK dari/ke tabel DBV-003: nama => [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE],
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

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
