<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\AddFkG02Pegawai;
use App\Database\Migrations\AddFkG02Riwayat;
use App\Database\Migrations\AddFkJabatanJenjangJf;
use App\Database\Migrations\CreateMasterJabatanSisa;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\LepasMigrationKepegawaianTrait;

/**
 * DBV-018 — skema G-02 sisa hasil migration 2026-09-30-100100_CreateMasterJabatanSisa dan 2026-09-30-100200
 * _AddFkJabatanJenjangJf harus sama dengan skema yang diajukan ke DB Validator
 * (backend/docs/db-review/G-02b-jabatan-sisa-schema.md Bagian 2): DDL kedelapan tabel dari dump struktur produksi
 * lengkap D1 `simpeg01_struktur_lengkap_20261001.sql` [K], plus deviasi v2 (status 1/2/10, 6 UNIQUE, FK RESTRICT nama
 * legacy, 4 CHECK, collation utf8mb4_unicode_ci) dan FK `jabatan.id_jenjang_jf` → `jenjang_jf`. Migration tidak menulis
 * baris apa pun dan bisa di-rollback. FK DBV-019 (snapshot/riwayat → `jabatan_koordinasi`/`rumpun_jabatan`) dilepas
 * sebelum down() dan dipasang ulang setelah up() (Tests\Support\LepasMigrationKepegawaianTrait::lepasFkG02()).
 *
 * @internal
 */
final class JabatanSisaSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use LepasMigrationKepegawaianTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const TABLES = [
        'jenjang_jf', 'rumpun_jabatan', 'subrumpun_jabatan', 'jabatan_akademik', 'periode_struktur_jabatan',
        'struktur_jabatan', 'peta_jabatan', 'jabatan_koordinasi',
    ];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi (" null" = boleh NULL). Urutan & tipe
     * [K] D1; `status` di jenjang_jf/struktur_jabatan [V2].
     */
    private const COLUMNS = [
        'jenjang_jf' => [
            'id_jenjang_jf'  => 'tinyint', 'kategori_jf' => "enum('ahli','terampil')", 'jenjang_jf' => 'varchar(50)',
            'extra_nama_jab' => 'varchar(50)', 'order' => 'tinyint', 'status' => 'tinyint', 'created_at' => 'datetime',
            'updated_at'     => 'datetime null',
        ],
        'rumpun_jabatan' => [
            'id_rumpun_jabatan' => 'tinyint', 'rumpun_jabatan' => 'varchar(50)', 'order' => 'tinyint', 'status' => 'tinyint',
            'created_at'        => 'datetime', 'created_by' => 'int null', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'subrumpun_jabatan' => [
            'id_subrumpun_jabatan' => 'tinyint', 'id_rumpun_jabatan' => 'tinyint null', 'subrumpun_jabatan' => 'varchar(255)',
            'order'                => 'tinyint', 'status' => 'tinyint', 'created_at' => 'datetime', 'created_by' => 'int null',
            'updated_at'           => 'datetime null', 'updated_by' => 'int null',
        ],
        'jabatan_akademik' => [
            'id_jabatan_akademik' => 'int', 'jabatan_akademik' => 'varchar(255)', 'is_atasan' => 'tinyint', 'status' => 'tinyint',
            'created_at'          => 'datetime', 'created_by' => 'int null', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'periode_struktur_jabatan' => [
            'id_periode_struktur_jabatan' => 'int', 'periode_struktur_jabatan' => 'varchar(45)', 'status' => 'tinyint',
            'created_at'                  => 'datetime', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'struktur_jabatan' => [
            'id_struktur_jabatan' => 'int', 'id_periode_struktur_jabatan' => 'int null', 'id_jabatan' => 'int null',
            'id_jabatan_atasan'   => 'int null', 'periode_struktur_jabatan' => 'varchar(45)', 'jabatan' => 'varchar(100)',
            'jabatan_atasan'      => 'varchar(100) null', 'level_struktur' => 'int', 'urutan' => 'int', 'status' => 'tinyint',
            'created_at'          => 'datetime', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'peta_jabatan' => [
            'id_peta_jabatan' => 'int', 'id_satker' => 'int', 'id_jabatan' => 'int', 'id_jabatan_at' => 'int null',
            'kebutuhan'       => 'int', 'urutan' => 'int', 'status' => 'tinyint', 'created_at' => 'datetime', 'created_by' => 'int null',
            'updated_at'      => 'datetime null', 'updated_by' => 'int null',
        ],
        'jabatan_koordinasi' => [
            'id_jabatan_koordinasi' => 'int', 'old_id_jabatan' => 'int null', 'id_unit' => 'int null', 'id_satker' => 'int null',
            'id_atasan_es_1'        => 'int null', 'id_atasan_es_2' => 'int null', 'id_atasan_es_3' => 'int null',
            'id_atasan_es_3_koord'  => 'int null', 'old_id_atasan_es_3' => 'int null', 'jenis' => 'tinyint',
            'jabatan'               => 'varchar(255)', 'kelas_jabatan' => 'tinyint null', 'umur_pensiun' => 'tinyint null', 'status' => 'tinyint',
            'id_rmj'                => 'int null', 'nip' => 'varchar(30) null', 'created_at' => 'datetime', 'updated_at' => 'datetime',
            'updated_by'            => 'int null',
        ],
    ];

    /**
     * Index: tabel => [nama index => [NON_UNIQUE, INDEX_TYPE, kolom berurutan]]. Nama KEY legacy [K] D1.
     */
    private const INDEXES = [
        'jenjang_jf' => [
            'PRIMARY'            => [0, 'BTREE', ['id_jenjang_jf']],
            'uq_jenjang_jf_nama' => [0, 'BTREE', ['kategori_jf', 'jenjang_jf']],
        ],
        'rumpun_jabatan' => [
            'PRIMARY'                => [0, 'BTREE', ['id_rumpun_jabatan']],
            'uq_rumpun_jabatan_nama' => [0, 'BTREE', ['rumpun_jabatan']],
        ],
        'subrumpun_jabatan' => [
            'PRIMARY'                   => [0, 'BTREE', ['id_subrumpun_jabatan']],
            'uq_subrumpun_jabatan_nama' => [0, 'BTREE', ['id_rumpun_jabatan', 'subrumpun_jabatan']],
            'subrumpun_jabatan_ibfk_01' => [1, 'BTREE', ['id_rumpun_jabatan']],
        ],
        'jabatan_akademik' => [
            'PRIMARY'                  => [0, 'BTREE', ['id_jabatan_akademik']],
            'uq_jabatan_akademik_nama' => [0, 'BTREE', ['jabatan_akademik']],
        ],
        'periode_struktur_jabatan' => [
            'PRIMARY'                          => [0, 'BTREE', ['id_periode_struktur_jabatan']],
            'uq_periode_struktur_jabatan_nama' => [0, 'BTREE', ['periode_struktur_jabatan']],
        ],
        'struktur_jabatan' => [
            'PRIMARY'                                        => [0, 'BTREE', ['id_struktur_jabatan']],
            'uq_struktur_jabatan'                            => [0, 'BTREE', ['id_periode_struktur_jabatan', 'id_jabatan', 'id_jabatan_atasan']],
            'fk_id_periode_struktur_jabatan_strukjab_to_psj' => [1, 'BTREE', ['id_periode_struktur_jabatan']],
            'fk_id_jabatan_strukjab_to_jabatan'              => [1, 'BTREE', ['id_jabatan']],
            'fk_id_jabatan_atasan_strukjab_to_jabatan'       => [1, 'BTREE', ['id_jabatan_atasan']],
        ],
        'peta_jabatan' => [
            'PRIMARY'            => [0, 'BTREE', ['id_peta_jabatan']],
            'fk_peta_jabatan_01' => [1, 'BTREE', ['id_jabatan']],
            'fk_peta_jabatan_03' => [1, 'BTREE', ['id_satker']],
            'fk_peta_jabatan_02' => [1, 'BTREE', ['id_jabatan_at']],
        ],
        'jabatan_koordinasi' => [
            'PRIMARY'                                 => [0, 'BTREE', ['id_jabatan_koordinasi']],
            'fk_id_unit_jabkoor_to_unit'              => [1, 'BTREE', ['id_unit']],
            'fk_id_satker_jabkoor_to_satker'          => [1, 'BTREE', ['id_satker']],
            'fk_id_atasan_es_1_jabkoor_to_jab'        => [1, 'BTREE', ['id_atasan_es_1']],
            'fk_id_atasan_es_2_jabkoor_to_jab'        => [1, 'BTREE', ['id_atasan_es_2']],
            'fk_id_atasan_es_3_koord_jabkoor_to_self' => [1, 'BTREE', ['id_atasan_es_3_koord']],
            'fk_id_atasan_es_3_jabkoor_to_jab'        => [1, 'BTREE', ['id_atasan_es_3']],
            'fk_old_id_jabatan_jabkoor_to_jab'        => [1, 'BTREE', ['old_id_jabatan']],
        ],
    ];

    /**
     * Seluruh FK dari/ke kedelapan tabel, urut nama: [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE,
     * DELETE_RULE]. Nama [K] D1; aksi RESTRICT/RESTRICT [V2]. Termasuk FK `jabatan` → `jenjang_jf` (migration 100200).
     */
    private const FOREIGN_KEYS = [
        'fk_id_atasan_es_1_jabkoor_to_jab'               => ['jabatan_koordinasi', 'id_atasan_es_1', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_atasan_es_2_jabkoor_to_jab'               => ['jabatan_koordinasi', 'id_atasan_es_2', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_atasan_es_3_jabkoor_to_jab'               => ['jabatan_koordinasi', 'id_atasan_es_3', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_atasan_es_3_koord_jabkoor_to_self'        => ['jabatan_koordinasi', 'id_atasan_es_3_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi', 'RESTRICT', 'RESTRICT'],
        'fk_id_jabatan_atasan_strukjab_to_jabatan'       => ['struktur_jabatan', 'id_jabatan_atasan', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_jabatan_strukjab_to_jabatan'              => ['struktur_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_jenjang_jf_jab_to_jenjang_jf'             => ['jabatan', 'id_jenjang_jf', 'jenjang_jf', 'id_jenjang_jf', 'RESTRICT', 'RESTRICT'],
        'fk_id_periode_struktur_jabatan_strukjab_to_psj' => ['struktur_jabatan', 'id_periode_struktur_jabatan', 'periode_struktur_jabatan', 'id_periode_struktur_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_id_satker_jabkoor_to_satker'                 => ['jabatan_koordinasi', 'id_satker', 'satker', 'id_satker', 'RESTRICT', 'RESTRICT'],
        'fk_id_unit_jabkoor_to_unit'                     => ['jabatan_koordinasi', 'id_unit', 'unit', 'id_unit', 'RESTRICT', 'RESTRICT'],
        'fk_old_id_jabatan_jabkoor_to_jab'               => ['jabatan_koordinasi', 'old_id_jabatan', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_peta_jabatan_01'                             => ['peta_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_peta_jabatan_02'                             => ['peta_jabatan', 'id_jabatan_at', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT'],
        'fk_peta_jabatan_03'                             => ['peta_jabatan', 'id_satker', 'satker', 'id_satker', 'RESTRICT', 'RESTRICT'],
        'subrumpun_jabatan_ibfk_01'                      => ['subrumpun_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', 'id_rumpun_jabatan', 'RESTRICT', 'RESTRICT'],
    ];

    /**
     * CHECK constraint per tabel [V2] (nama saja; perilaku diuji di testConstraintsAreEnforced()).
     */
    private const CHECKS = [
        'jenjang_jf'               => [],
        'rumpun_jabatan'           => [],
        'subrumpun_jabatan'        => [],
        'jabatan_akademik'         => ['chk_jabatan_akademik_is_atasan'],
        'periode_struktur_jabatan' => [],
        'struktur_jabatan'         => ['chk_struktur_jabatan_level_struktur'],
        'peta_jabatan'             => ['chk_peta_jabatan_kebutuhan'],
        'jabatan_koordinasi'       => ['chk_jabatan_koordinasi_jenis'],
    ];

    /**
     * Test rollback/kegagalan memanggil down()/up() di tengah jalan; bila assertion gagal sebelum skema pulih, kedua
     * migration dijalankan ulang agar migrate:refresh test berikutnya konsisten dengan tabel migrations.
     */
    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($table) || $this->columns($table) !== self::COLUMNS[$table]) {
                foreach ([fn () => $this->lepasFkG02(), fn () => $this->fkMigration()->down(), fn () => $this->migration()->down()] as $step) {
                    try {
                        $step();
                    } catch (\Throwable) {
                        // FK/tabel sudah tidak ada di tengah test: lanjut membangun ulang.
                    }
                }

                $this->dropIfExists('peta_jabatan');
                $this->migration()->up();
                $this->fkMigration()->up();
                $this->pasangUlangMigrationKepegawaian();

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
            $this->assertSame('current_timestamp', strtolower(trim((string) $this->columnInfo($table, 'created_at')['COLUMN_DEFAULT'], "'()")), "{$table}.created_at default");
            $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo($table, (string) array_key_first(self::COLUMNS[$table]))['EXTRA']), "{$table} PK AUTO_INCREMENT");
        }

        // Default & COMMENT legacy [K] D1.
        $this->assertSame('Ahli', $this->unquote($this->columnInfo('jenjang_jf', 'kategori_jf')['COLUMN_DEFAULT']));
        $this->assertSame("enum('Ahli','Terampil')", $this->columnType('jenjang_jf', 'kategori_jf'), 'nilai ENUM peka huruf [K] D1:1789');
        $this->assertSame('1', $this->unquote($this->columnInfo('jenjang_jf', 'order')['COLUMN_DEFAULT']));

        foreach (['rumpun_jabatan', 'subrumpun_jabatan'] as $table) {
            $this->assertSame('0', $this->unquote($this->columnInfo($table, 'order')['COLUMN_DEFAULT']), "{$table}.order default 0 [K]");
        }

        $isAtasan = $this->columnInfo('jabatan_akademik', 'is_atasan');
        $this->assertSame(['2', '1: Ya, 2: Tidak'], [$this->unquote($isAtasan['COLUMN_DEFAULT']), $isAtasan['COLUMN_COMMENT']]);
        $this->assertSame('1: Menteri, 2: Es.I, 3: Es.II, 4: Es.III, 5: Es.IV', $this->columnInfo('struktur_jabatan', 'level_struktur')['COLUMN_COMMENT']);
        $this->assertNull($this->columnInfo('struktur_jabatan', 'level_struktur')['COLUMN_DEFAULT'], 'level_struktur wajib diisi (tanpa default)');
        $this->assertSame('1', $this->unquote($this->columnInfo('struktur_jabatan', 'urutan')['COLUMN_DEFAULT']));
        $this->assertSame(['0', '1'], [$this->unquote($this->columnInfo('peta_jabatan', 'kebutuhan')['COLUMN_DEFAULT']), $this->unquote($this->columnInfo('peta_jabatan', 'urutan')['COLUMN_DEFAULT'])]);

        $jenis = $this->columnInfo('jabatan_koordinasi', 'jenis');
        $this->assertSame(['1', '1: Koordinator, 2: Sub Koordinator'], [$this->unquote($jenis['COLUMN_DEFAULT']), $jenis['COLUMN_COMMENT']]);

        // jabatan_koordinasi.updated_at NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE [K] D1:1528 (beda dari tabel lain).
        $updatedAt = $this->columnInfo('jabatan_koordinasi', 'updated_at');
        $this->assertSame('current_timestamp', strtolower(trim((string) $updatedAt['COLUMN_DEFAULT'], "'()")));
        $this->assertStringContainsString('on update current_timestamp', strtolower((string) $updatedAt['EXTRA']));

        foreach (['rumpun_jabatan', 'subrumpun_jabatan', 'jabatan_akademik', 'peta_jabatan'] as $table) {
            $this->assertSame('id_pengguna pembuat', $this->columnInfo($table, 'created_by')['COLUMN_COMMENT'], "{$table}.created_by COMMENT");
        }
    }

    /**
     * Tanpa seed: data datang dari impor ID legacy apa adanya (G-02b Bagian 6.5).
     */
    public function testMigrationCreatesNoRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (koneksi test strictOn=true): UNIQUE case-insensitive termasuk status 10, CHECK, FK RESTRICT (termasuk FK
     * jabatan → jenjang_jf dan FK ke diri sendiri jabatan_koordinasi), NOT NULL, ENUM.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->seedG02Parents();

        // jenjang_jf: unik per kategori, ENUM hanya Ahli/Terampil, FK dari jabatan.
        $this->db->table('jenjang_jf')->insert(['id_jenjang_jf' => 1, 'kategori_jf' => 'Ahli', 'jenjang_jf' => 'Ahli Pertama', 'extra_nama_jab' => 'Ahli Pertama']);
        $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Terampil', 'jenjang_jf' => 'ahli pertama', 'extra_nama_jab' => 'x']);
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Ahli', 'jenjang_jf' => 'AHLI PERTAMA', 'extra_nama_jab' => 'x']));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Utama', 'jenjang_jf' => 'Lain', 'extra_nama_jab' => 'x']));
        $this->db->table('jabatan')->where('id_jabatan', 1)->update(['id_jenjang_jf' => 1]);
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->where('id_jabatan', 1)->update(['id_jenjang_jf' => 99]));
        $this->assertDbWriteFails(fn () => $this->db->table('jenjang_jf')->where('id_jenjang_jf', 1)->delete());

        // rumpun & subrumpun: unik (status 10 tetap memblok), FK RESTRICT.
        $this->db->table('rumpun_jabatan')->insert(['id_rumpun_jabatan' => 1, 'rumpun_jabatan' => 'Manajemen', 'status' => 10]);
        $this->assertDbWriteFails(fn () => $this->db->table('rumpun_jabatan')->insert(['rumpun_jabatan' => 'MANAJEMEN']));
        $this->db->table('subrumpun_jabatan')->insert(['id_subrumpun_jabatan' => 1, 'id_rumpun_jabatan' => 1, 'subrumpun_jabatan' => 'Keuangan']);
        $this->assertDbWriteFails(fn () => $this->db->table('subrumpun_jabatan')->insert(['id_rumpun_jabatan' => 1, 'subrumpun_jabatan' => 'KEUANGAN']));
        $this->assertDbWriteFails(fn () => $this->db->table('subrumpun_jabatan')->insert(['id_rumpun_jabatan' => 99, 'subrumpun_jabatan' => 'Yatim']));
        $this->assertDbWriteFails(fn () => $this->db->table('rumpun_jabatan')->where('id_rumpun_jabatan', 1)->delete());

        // jabatan_akademik: is_atasan 1/2, nama unik.
        $this->db->table('jabatan_akademik')->insert(['jabatan_akademik' => 'Lektor']);
        $this->seeInDatabase('jabatan_akademik', ['jabatan_akademik' => 'Lektor', 'is_atasan' => 2, 'status' => 1]);
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan_akademik')->insert(['jabatan_akademik' => 'LEKTOR']));
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan_akademik')->insert(['jabatan_akademik' => 'Guru Besar', 'is_atasan' => 3]));

        // periode & struktur: periode unik, struktur unik per (periode, jabatan, atasan), level 1..5, FK RESTRICT.
        $this->db->table('periode_struktur_jabatan')->insert(['id_periode_struktur_jabatan' => 1, 'periode_struktur_jabatan' => '2024']);
        $this->assertDbWriteFails(fn () => $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => '2024']));
        $struktur = ['id_periode_struktur_jabatan' => 1, 'id_jabatan' => 2, 'id_jabatan_atasan' => 1, 'periode_struktur_jabatan' => '2024', 'jabatan' => 'Kepala Bagian', 'level_struktur' => 4];
        $this->db->table('struktur_jabatan')->insert($struktur);
        $this->assertDbWriteFails(fn () => $this->db->table('struktur_jabatan')->insert($struktur));
        $this->assertDbWriteFails(fn () => $this->db->table('struktur_jabatan')->insert(['level_struktur' => 0, 'id_jabatan_atasan' => 2] + $struktur));
        $this->assertDbWriteFails(fn () => $this->db->table('struktur_jabatan')->insert(['level_struktur' => 6, 'id_jabatan_atasan' => 2] + $struktur));
        $this->assertDbWriteFails(fn () => $this->db->table('struktur_jabatan')->insert(['id_jabatan' => 99, 'id_jabatan_atasan' => 2] + $struktur));
        $this->assertDbWriteFails(fn () => $this->db->table('periode_struktur_jabatan')->where('id_periode_struktur_jabatan', 1)->delete());

        // peta_jabatan: satker & jabatan wajib, kebutuhan ≥ 0, FK RESTRICT (satker yang dipetakan tidak bisa dihapus).
        $this->db->table('peta_jabatan')->insert(['id_satker' => 1, 'id_jabatan' => 2, 'id_jabatan_at' => 1, 'kebutuhan' => 3]);
        $this->assertDbWriteFails(fn () => $this->db->table('peta_jabatan')->insert(['id_satker' => 1, 'id_jabatan' => 2, 'kebutuhan' => -1]));
        $this->assertDbWriteFails(fn () => $this->db->table('peta_jabatan')->insert(['id_satker' => null, 'id_jabatan' => 2]));
        $this->assertDbWriteFails(fn () => $this->db->table('peta_jabatan')->insert(['id_satker' => 1, 'id_jabatan' => 99]));
        $this->assertDbWriteFails(fn () => $this->db->table('satker')->where('id_satker', 1)->delete());

        // jabatan_koordinasi: jenis 1/2, FK ke diri sendiri RESTRICT.
        $this->db->table('jabatan_koordinasi')->insert(['id_jabatan_koordinasi' => 1, 'id_unit' => 1, 'id_satker' => 1, 'jenis' => 1, 'jabatan' => 'Koordinator Uji']);
        $this->db->table('jabatan_koordinasi')->insert(['id_jabatan_koordinasi' => 2, 'id_unit' => 1, 'id_satker' => 1, 'jenis' => 2, 'jabatan' => 'Sub Koordinator Uji', 'id_atasan_es_3_koord' => 1]);
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan_koordinasi')->insert(['id_unit' => 1, 'id_satker' => 1, 'jenis' => 3, 'jabatan' => 'Jenis Salah']));
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan_koordinasi')->insert(['id_unit' => 1, 'jenis' => 2, 'jabatan' => 'Atasan Yatim', 'id_atasan_es_3_koord' => 99]));
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan_koordinasi')->where('id_jabatan_koordinasi', 1)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('jabatan')->where('id_jabatan', 1)->delete());
    }

    /**
     * down() kedua migration menghapus FK jabatan → jenjang_jf lalu kedelapan tabel; tabel G-02 (DBV-008) dan tabel lain
     * tidak tersentuh, KEY legacy `fk_id_jenjang_jf_jab_to_jenjang_jf` di jabatan tetap ada. up() mengembalikan skema
     * yang sama.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $this->lepasFkG02();
        $this->fkMigration()->down();
        $this->assertSame([], $this->foreignKeysOn('jabatan', 'id_jenjang_jf'));
        $keyCount = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'fk_id_jenjang_jf_jab_to_jenjang_jf'",
            [$this->db->getDatabase(), $this->db->prefixTable('jabatan')],
        )->getRowArray()['n'];
        $this->assertSame(1, $keyCount, 'KEY legacy jabatan (DBV-008) tetap ada');

        $this->migration()->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        foreach (['unit', 'satker', 'jabatan', 'kelas_jabatan', 'pangkat', 'pengguna'] as $other) {
            $this->assertTrue($this->tableExists($other), "tabel {$other} tidak tersentuh");
        }

        $this->migration()->up();
        $this->fkMigration()->up();
        $this->pasangUlangMigrationKepegawaian();
        $this->assertSchema();
    }

    /**
     * Bila salah satu CREATE gagal di tengah up(), tabel yang dibuat PADA RUN ITU di-drop (urutan terbalik) lalu error
     * dilempar ulang. Disimulasikan dengan tabel penghalang `peta_jabatan` (CREATE ke-7 gagal, 1050).
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->lepasFkG02();
        $this->fkMigration()->down();
        $this->migration()->down();

        $blocker = $this->db->escapeIdentifiers($this->db->prefixTable('peta_jabatan'));
        $this->db->query("CREATE TABLE {$blocker} (`penghalang` INT NOT NULL) ENGINE=InnoDB");

        $error = null;

        try {
            $this->migration()->up();
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena peta_jabatan sudah ada.');
        $this->assertStringContainsString('peta_jabatan', $error->getMessage());

        foreach (['jenjang_jf', 'rumpun_jabatan', 'subrumpun_jabatan', 'jabatan_akademik', 'periode_struktur_jabatan', 'struktur_jabatan', 'jabatan_koordinasi'] as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        $this->assertSame(['penghalang' => 'int'], $this->columns('peta_jabatan'));

        $this->db->query("DROP TABLE {$blocker}");
        $this->migration()->up();
        $this->fkMigration()->up();
        $this->pasangUlangMigrationKepegawaian();
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
        $this->assertSame(self::CHECKS, $this->checkConstraints());
    }

    /**
     * Induk G-02 minimal untuk uji FK (tabel DBV-008).
     */
    private function seedG02Parents(): void
    {
        $this->db->table('unit')->insert(['id_unit' => 1, 'unit' => 'Sekretariat Kementerian']);
        $this->db->table('satker')->insert(['id_satker' => 1, 'id_unit' => 1, 'satker' => 'Biro Umum']);
        $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural']);
        $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => 1, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'Administrator']);
        $this->db->table('jabatan')->insert(['id_jabatan' => 1, 'id_group_jabatan' => 1, 'id_sub_group_jabatan' => 1, 'id_satker' => 1, 'jabatan' => 'Kepala Biro']);
        $this->db->table('jabatan')->insert(['id_jabatan' => 2, 'id_group_jabatan' => 1, 'id_sub_group_jabatan' => 1, 'id_satker' => 1, 'jabatan' => 'Kepala Bagian']);
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

    private function migration(): CreateMasterJabatanSisa
    {
        require_once APPPATH . 'Database/Migrations/2026-09-30-100100_CreateMasterJabatanSisa.php';

        return new CreateMasterJabatanSisa();
    }

    private function fkMigration(): AddFkJabatanJenjangJf
    {
        require_once APPPATH . 'Database/Migrations/2026-09-30-100200_AddFkJabatanJenjangJf.php';

        return new AddFkJabatanJenjangJf();
    }

    private function dropIfExists(string $table): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)));
    }

    private function unquote(mixed $value): string
    {
        return trim((string) $value, "'");
    }

    private function tableExists(string $table): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray()['n'] > 0;
    }

    /**
     * @return array<string, string> kolom => "COLUMN_TYPE[ null]" (huruf kecil), urut posisi
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
            $type = (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\((?!1\))\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));

            $columns[(string) $row['COLUMN_NAME']] = $type . ($row['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }

        return $columns;
    }

    private function columnType(string $table, string $column): string
    {
        return (string) ($this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray()['COLUMN_TYPE'] ?? '');
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
     * @return array<string, array{0: int, 1: string, 2: list<string>}> nama index => [NON_UNIQUE, INDEX_TYPE, kolom]
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
     * Seluruh FK dari kedelapan tabel atau ke kedelapan tabel, urut nama.
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
        // FK masuk dari snapshot/riwayat B-01/B-02 (DBV-019) bukan bagian skema G-02b; dikunci FkG02Test.
        $dbv019 = AddFkG02Pegawai::FOREIGN_KEYS + AddFkG02Riwayat::FOREIGN_KEYS;

        foreach ($rows as $row) {
            if (isset($dbv019[(string) $row['CONSTRAINT_NAME']])) {
                continue;
            }

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
     * @return array<string, list<string>> tabel => nama CHECK, urut nama
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
