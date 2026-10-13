<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use App\Database\Migrations\AddFkSertifikasiDiRiwayatDiklat;
use App\Database\Migrations\AlterPenggunaPegawaiHistFase3;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Kepegawaian\SkemaD1;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-020 — skema tambahan Fase 3 (backend/docs/db-review/DBV-020-skema-tambahan-fase3.md):
 *   - 2026-10-13-100000_CreateMasterSertifikasi: `rumpun_sertifikasi`, `lembaga_sertifikasi` [K] D1;
 *   - 2026-10-13-100100_CreateDmAkJf: `dm_ak_jf` [K] D1, FK RESTRICT ke `jabatan`/`pangkat`;
 *   - 2026-10-13-100200_AlterPenggunaPegawaiHistFase3: `pengguna.must_change_password` [V2], gelar `pegawai_hist`
 *     VARCHAR(50) [V2];
 *   - app/Database/MigrationsDitahan/AddFkSertifikasiDiRiwayatDiklat (DITAHAN): dipasang sementara di test lalu dilepas.
 * Diperiksa lewat information_schema (kolom, tipe, NULL, default, PK, index, FK + aksi, collation, engine) dan siklus
 * down()/up().
 *
 * @internal
 */
final class SkemaTambahanFase3Test extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    private const FIRST_VERSION = '2026-10-13-100000';

    private const ALTER_VERSION = '2026-10-13-100200';

    private const MASTER_COLUMNS = [
        'int', 'varchar(255)', 'tinyint', 'datetime', 'int null', 'datetime null', 'int null',
    ];

    private const MASTER_DEFAULTS = ['status' => '1', 'created_at' => 'CURRENT_TIMESTAMP', 'updated_at' => 'NULL on update'];

    private const DM_AK_JF_COLUMNS = [
        'id_jabatan' => 'int', 'id_pangkat' => 'tinyint', 'jenjang' => 'varchar(50) null', 'ak' => 'double',
        'created_at' => 'datetime', 'updated_by' => 'int null', 'updated_at' => 'datetime null',
    ];

    private const DM_AK_JF_FKS = [
        'fk_dm_ak_jf_ibfk_01' => ['dm_ak_jf', 'id_jabatan', 'jabatan', 'id_jabatan'],
        'fk_dm_ak_jf_ibfk_02' => ['dm_ak_jf', 'id_pangkat', 'pangkat', 'id_pangkat'],
    ];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const MUST_CHANGE_COMMENT = '1: wajib ganti password saat login berikutnya (reset admin / Koreksi NIP), 0: tidak';

    private ?AddFkSertifikasiDiRiwayatDiklat $fkDitahan = null;

    protected function tearDown(): void
    {
        if ($this->fkDitahan !== null) {
            $this->db->table('riwayat_diklat')->where('id_rumpun_sertifikasi IS NOT NULL', null, false)->delete();
            $this->fkDitahan->down();
            $this->fkDitahan = null;
        }

        if ($this->adaYangDilepas()) {
            $this->db->table('pegawai_hist')->where('CHAR_LENGTH(glr_awal) > 12', null, false)->delete();
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testSkemaTabelBaru(): void
    {
        $this->assertSkemaBaru();
    }

    /**
     * Tipe kolom FK `dm_ak_jf` = PK induk di `main`; kolom `riwayat_diklat` yang akan dirujuk master sertifikasi = PK
     * master (FK ditahan).
     */
    public function testTipeKolomRujukanSamaDenganPkInduk(): void
    {
        $this->assertSame($this->skemaColumns('jabatan')['id_jabatan'], self::DM_AK_JF_COLUMNS['id_jabatan']);
        $this->assertSame($this->skemaColumns('pangkat')['id_pangkat'], self::DM_AK_JF_COLUMNS['id_pangkat']);

        $riwayat = $this->skemaColumns('riwayat_diklat');
        $this->assertSame('int null', $riwayat['id_rumpun_sertifikasi']);
        $this->assertSame('int null', $riwayat['id_lembaga_sertifikasi']);
        $this->assertSame('int', $this->skemaColumns('rumpun_sertifikasi')['id_rumpun_sertifikasi']);
        $this->assertSame('int', $this->skemaColumns('lembaga_sertifikasi')['id_lembaga_sertifikasi']);
    }

    public function testKolomPenggunaDanGelarPegawaiHist(): void
    {
        $pengguna = $this->skemaColumns('pengguna');
        $posisi   = array_keys($pengguna);
        $this->assertSame('tinyint(1)', $pengguna['must_change_password']);
        $this->assertSame(array_search('expired_at', $posisi, true) + 1, array_search('must_change_password', $posisi, true), 'sesudah expired_at');
        $this->assertSame('0', $this->skemaDefaults('pengguna')['must_change_password'] ?? null);
        $this->assertSame(self::MUST_CHANGE_COMMENT, $this->skemaComments('pengguna')['must_change_password'] ?? null);

        $hist    = $this->skemaColumns('pegawai_hist');
        $pegawai = $this->skemaColumns('pegawai');

        foreach (['glr_awal', 'glr_akhir'] as $kolom) {
            $this->assertSame('varchar(50) null', $hist[$kolom], "pegawai_hist.{$kolom}");
            $this->assertSame($pegawai[$kolom], $hist[$kolom], "pegawai_hist.{$kolom} = pegawai.{$kolom}");
            $this->assertSame('utf8mb4_unicode_ci', $this->skemaStringCollations('pegawai_hist')[$kolom]);
            $this->assertArrayNotHasKey($kolom, $this->skemaDefaults('pegawai_hist'), 'DEFAULT NULL');
        }

        $this->assertSame(['varchar(12) null', 'varchar(24) null'], [SkemaD1::B01['pegawai_hist']['columns']['glr_awal'], SkemaD1::B01['pegawai_hist']['columns']['glr_akhir']], 'D1 tetap');
    }

    /**
     * Lapis DB: default must_change_password 0, gelar 50 karakter tersimpan, FK dm_ak_jf menolak induk asing (1452) dan
     * menahan hapus induk (1451), PK (id_jabatan, id_pangkat), master sertifikasi tanpa AUTO_INCREMENT.
     */
    public function testConstraintsDitegakkan(): void
    {
        $this->db->table('pengguna')->insert(['nip' => null, 'username' => 'dbv020.uji', 'name' => 'Uji DBV-020', 'user_level' => 1, 'status' => '1']);
        $this->seeInDatabase('pengguna', ['username' => 'dbv020.uji', 'must_change_password' => 0]);

        $nip   = $this->buatPegawai();
        $gelar = str_repeat('g', 50);
        $this->db->table('pegawai_hist')->insert(['nip' => $nip, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'glr_awal' => $gelar, 'glr_akhir' => $gelar]);
        $this->seeInDatabase('pegawai_hist', ['nip' => $nip, 'glr_awal' => $gelar, 'glr_akhir' => $gelar]);

        $idJabatan = $this->buatJabatan()['id_jabatan'];
        $this->db->table('pangkat')->insert(['pangkat' => 'Pangkat Uji DBV-020', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'order' => 9, 'status' => 1]);
        $idPangkat = (int) $this->db->insertID();

        $this->db->table('dm_ak_jf')->insert(['id_jabatan' => $idJabatan, 'id_pangkat' => $idPangkat, 'ak' => 12.5]);
        $this->seeInDatabase('dm_ak_jf', ['id_jabatan' => $idJabatan, 'id_pangkat' => $idPangkat, 'jenjang' => null, 'updated_by' => null]);
        $this->assertDbError([1062], fn () => $this->db->table('dm_ak_jf')->insert(['id_jabatan' => $idJabatan, 'id_pangkat' => $idPangkat, 'ak' => 1]));
        $this->assertDbError([1452], fn () => $this->db->table('dm_ak_jf')->insert(['id_jabatan' => 99_999_999, 'id_pangkat' => $idPangkat, 'ak' => 1]));
        $this->assertDbError([1452], fn () => $this->db->table('dm_ak_jf')->insert(['id_jabatan' => $idJabatan, 'id_pangkat' => 127, 'ak' => 1]));
        $this->assertDbError([1451], fn () => $this->db->table('pangkat')->where('id_pangkat', $idPangkat)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('jabatan')->where('id_jabatan', $idJabatan)->delete());

        foreach (['rumpun_sertifikasi', 'lembaga_sertifikasi'] as $table) {
            $this->db->table($table)->insert(['id_' . $table => 77, $table => 'Uji ' . $table]);
            $this->seeInDatabase($table, ['id_' . $table => 77, 'status' => 1]);
            $this->assertDbError([1364], fn () => $this->db->table($table)->insert([$table => 'Tanpa id']));
        }
    }

    /**
     * down() ketiga migration (urut versi menurun) melepas seluruh perubahan; up() memasangnya lagi persis.
     */
    #[Group('db-isolasi-penuh')]
    public function testDownLaluUpKembaliSama(): void
    {
        $this->lepasSejak(self::FIRST_VERSION);

        foreach (['rumpun_sertifikasi', 'lembaga_sertifikasi', 'dm_ak_jf'] as $table) {
            $this->assertFalse($this->skemaTableExists($table), "{$table} harus terhapus oleh down()");
        }

        $this->assertArrayNotHasKey('must_change_password', $this->skemaColumns('pengguna'));
        $hist = $this->skemaColumns('pegawai_hist');
        $this->assertSame(['varchar(12) null', 'varchar(24) null'], [$hist['glr_awal'], $hist['glr_akhir']]);
        $this->assertSame('utf8mb4_unicode_ci', $this->skemaStringCollations('pegawai_hist')['glr_awal']);

        $this->pasangUlang();
        $this->assertSkemaBaru();
        $this->testKolomPenggunaDanGelarPegawaiHist();
    }

    /**
     * down() migration ALTER menolak (sebelum ALTER apa pun) gelar `pegawai_hist` yang lebih panjang dari lebar lama.
     */
    #[Group('db-isolasi-penuh')]
    public function testDownDitolakBilaGelarTerlaluPanjang(): void
    {
        $nip = $this->buatPegawai();
        $this->db->table('pegawai_hist')->insert(['nip' => $nip, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'glr_awal' => str_repeat('a', 13)]);

        $migration = $this->skemaMigration(self::ALTER_VERSION);
        $this->assertInstanceOf(AlterPenggunaPegawaiHistFase3::class, $migration);

        try {
            $migration->down();
            $this->fail('down() harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pegawai_hist.glr_awal: 1 baris lebih dari 12 karakter', $e->getMessage());
        }

        $this->assertArrayHasKey('must_change_password', $this->skemaColumns('pengguna'), 'skema tidak berubah');
        $this->assertSame('varchar(50) null', $this->skemaColumns('pegawai_hist')['glr_awal']);
    }

    /**
     * Migration FK sertifikasi DITAHAN: tidak dijalankan migrate; dipasang sementara → pra-cek yatim fail-closed,
     * FK RESTRICT/RESTRICT + KEY, 1452/1451, down() mengembalikan skema riwayat_diklat (= D1), up()/down() aman diulang.
     */
    #[Group('db-isolasi-penuh')]
    public function testFkSertifikasiDitahan(): void
    {
        $this->assertSame([], glob(APPPATH . 'Database/Migrations/*_AddFkSertifikasiDiRiwayatDiklat.php') ?: [], 'migration ditahan tidak boleh ada di folder Migrations');
        $this->assertSame([], $this->fkSertifikasi());

        $indexSebelum = $this->skemaIndexes('riwayat_diklat');
        $nip          = $this->buatPegawai();
        $this->db->table('riwayat_diklat')->insert(['nip' => $nip, 'jenis_diklat' => 5, 'id_rumpun_sertifikasi' => 5, 'id_lembaga_sertifikasi' => 6]);
        $idRiwayat = (int) $this->db->insertID();

        $this->fkDitahan = $this->fkDitahanInstance();

        try {
            $this->fkDitahan->up();
            $this->fail('up() harus ditolak karena id yatim');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('riwayat_diklat.id_rumpun_sertifikasi: 1 baris', $e->getMessage());
            $this->assertStringContainsString('riwayat_diklat.id_lembaga_sertifikasi: 1 baris', $e->getMessage());
        }

        $this->assertSame([], $this->fkSertifikasi(), 'tidak ada FK terpasang sebagian');
        $this->assertSame($indexSebelum, $this->skemaIndexes('riwayat_diklat'), 'tidak ada KEY baru');

        $this->db->table('rumpun_sertifikasi')->insert(['id_rumpun_sertifikasi' => 5, 'rumpun_sertifikasi' => 'Rumpun Uji']);
        $this->db->table('lembaga_sertifikasi')->insert(['id_lembaga_sertifikasi' => 6, 'lembaga_sertifikasi' => 'Lembaga Uji']);

        $this->fkDitahan->up();
        $this->fkDitahan->up();

        $this->assertSame([
            'fk_id_lembaga_sertifikasi_rdiklat_to_ls' => ['riwayat_diklat', 'id_lembaga_sertifikasi', 'lembaga_sertifikasi', 'id_lembaga_sertifikasi', 'RESTRICT', 'RESTRICT'],
            'fk_id_rumpun_sertifikasi_rdiklat_to_rs'  => ['riwayat_diklat', 'id_rumpun_sertifikasi', 'rumpun_sertifikasi', 'id_rumpun_sertifikasi', 'RESTRICT', 'RESTRICT'],
        ], $this->fkSertifikasi());

        $index = $this->skemaIndexes('riwayat_diklat')[1];
        $this->assertSame(['id_rumpun_sertifikasi'], $index['fk_id_rumpun_sertifikasi_rdiklat_to_rs'] ?? null);
        $this->assertSame(['id_lembaga_sertifikasi'], $index['fk_id_lembaga_sertifikasi_rdiklat_to_ls'] ?? null);

        $this->assertDbError([1452], fn () => $this->db->table('riwayat_diklat')->where('id_riwayat_diklat', $idRiwayat)->update(['id_rumpun_sertifikasi' => 999]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_diklat')->where('id_riwayat_diklat', $idRiwayat)->update(['id_lembaga_sertifikasi' => 999]));
        $this->assertDbError([1451], fn () => $this->db->table('rumpun_sertifikasi')->where('id_rumpun_sertifikasi', 5)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('lembaga_sertifikasi')->where('id_lembaga_sertifikasi', 6)->delete());

        $this->fkDitahan->down();
        $this->fkDitahan->down();

        $this->assertSame([], $this->fkSertifikasi());
        $this->assertSame($indexSebelum, $this->skemaIndexes('riwayat_diklat'), 'skema riwayat_diklat kembali = D1');

        $this->db->table('riwayat_diklat')->where('id_riwayat_diklat', $idRiwayat)->delete();
        $this->fkDitahan = null;
    }

    private function assertSkemaBaru(): void
    {
        $tables  = ['rumpun_sertifikasi', 'lembaga_sertifikasi', 'dm_ak_jf'];
        $columns = $primary = $defaults = [];

        foreach (['rumpun_sertifikasi', 'lembaga_sertifikasi'] as $table) {
            $columns[$table] = array_combine(
                ['id_' . $table, $table, 'status', 'created_at', 'created_by', 'updated_at', 'updated_by'],
                self::MASTER_COLUMNS,
            );
            $primary[$table]  = ['id_' . $table];
            $defaults[$table] = self::MASTER_DEFAULTS;
            $this->assertSame(['status' => self::STATUS_COMMENT], $this->skemaComments($table), "COMMENT {$table}");
        }

        $columns['dm_ak_jf']  = self::DM_AK_JF_COLUMNS;
        $primary['dm_ak_jf']  = ['id_jabatan', 'id_pangkat'];
        $defaults['dm_ak_jf'] = ['created_at' => 'CURRENT_TIMESTAMP', 'updated_at' => 'NULL on update'];
        $this->assertSame([], $this->skemaComments('dm_ak_jf'));

        $this->assertSkema(
            $tables,
            $columns,
            $primary,
            ['dm_ak_jf' => ['fk_dm_ak_jf_ibfk_02' => ['id_pangkat']]],
            [],
            self::DM_AK_JF_FKS,
            [],
            [],
            $defaults,
        );
    }

    /**
     * FK `riwayat_diklat` → master sertifikasi: nama => [tabel, kolom, induk, kolom induk, UPDATE_RULE, DELETE_RULE].
     *
     * @return array<string, list<string>>
     */
    private function fkSertifikasi(): array
    {
        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IN ?
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), $this->db->prefixTable('riwayat_diklat'), [$this->db->prefixTable('rumpun_sertifikasi'), $this->db->prefixTable('lembaga_sertifikasi')]],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['CONSTRAINT_NAME']] = [
                $this->skemaStripPrefix((string) $row['TABLE_NAME']), (string) $row['COLUMN_NAME'],
                $this->skemaStripPrefix((string) $row['REFERENCED_TABLE_NAME']), (string) $row['REFERENCED_COLUMN_NAME'],
                (string) $row['UPDATE_RULE'], (string) $row['DELETE_RULE'],
            ];
        }

        return $out;
    }

    /**
     * Migration FK yang ditahan (di luar app/Database/Migrations, jadi dimuat manual).
     */
    private function fkDitahanInstance(): AddFkSertifikasiDiRiwayatDiklat
    {
        $file = APPPATH . 'Database/MigrationsDitahan/AddFkSertifikasiDiRiwayatDiklat.php';

        if (! is_file($file)) {
            throw new RuntimeException("File migration ditahan {$file} tidak ditemukan.");
        }

        require_once $file;

        return new AddFkSertifikasiDiRiwayatDiklat();
    }

    /**
     * @return array<string, string>
     */
    private function skemaComments(string $table): array
    {
        $rows = $this->db->query(
            "SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_COMMENT <> '' ORDER BY ORDINAL_POSITION",
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'COLUMN_COMMENT', 'COLUMN_NAME');
    }
}
