<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\SkemaD1;
use Tests\Support\Kepegawaian\SkemaD1TestTrait;
use Throwable;

/**
 * DBV-012 — skema B-01 hasil migration 2026-09-30-120000_CreatePegawai dan 120100_CreatePegawaiSnapshot harus sama dengan
 * DDL produksi D1 (dump struktur `simpeg01` 01-10-2026) kecuali deviasi [V2] yang dicatat di
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2-3): kolom (tipe, NULL, urutan), default/ON UPDATE,
 * COMMENT, collation, PRIMARY/index, FK (nama D1, RESTRICT/RESTRICT), CHECK [V2], perilaku constraint, down()/up()
 * bersih, dan pembersihan saat up() gagal di tengah. Ekspektasi: Tests\Support\Kepegawaian\SkemaD1::B01.
 *
 * FK snapshot → riwayat (131000) diuji SnapshotRiwayatFkTest; FK ke G-02 / tabel yang belum ada di v2 ditahan
 * (dokumen Bagian 7) dan di sini hanya boleh muncul bila terdaftar.
 *
 * @internal
 */
final class PegawaiSnapshotSchemaTest extends DatabaseTestCase
{
    use SkemaD1TestTrait;


    private const NIP = '198501012010011001';

    private const NIP_ASING = '199912312099121001';

    private const ERR_CHECK = [3819, 4025];

    private const FIRST_VERSION = '2026-09-30-120000';

    private const SNAPSHOT_VERSION = '2026-09-30-120100';

    /**
     * Tabel penghalang untuk uji up() gagal (CREATE ke-11 migration 120100, error 1050).
     */
    private const BLOCKER = 'pegawai_alamat';

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            if ($this->skemaTableExists(self::BLOCKER) && array_key_exists('penghalang', $this->skemaColumns(self::BLOCKER))) {
                $this->db->query('DROP TABLE ' . $this->skemaQuoted(self::BLOCKER));
            }

            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testSchemaMatchesD1(): void
    {
        $this->assertSkemaD1(SkemaD1::B01);
    }

    /**
     * Ringkasan deviasi [V2] B-01 yang dikunci eksplisit (selain FK RESTRICT dan CHECK yang sudah tercakup
     * assertSkemaD1): NIP VARCHAR(30) dan tipe FK mengikuti PK master di `main`.
     */
    public function testV2DeviationsAreExplicit(): void
    {
        foreach (['pegawai_ak', 'pegawai_ak_siasn'] as $table) {
            $this->assertSame('varchar(30)', SkemaD1::B01[$table]['columns']['nip'], "{$table}.nip = pegawai.nip (D1 varchar(50))");
        }

        $this->assertSame('int null', SkemaD1::B01['pegawai_pendidikan']['columns']['id_jenjang_pendidikan']);
        $this->assertSame('int null', SkemaD1::B01['pegawai_hukdis']['columns']['id_jenis_hukdis']);
        $this->assertSame('int null', SkemaD1::B01['pegawai_hukdis']['columns']['id_tingkat_hukdis']);
        $this->assertSame('int null', SkemaD1::B01['pegawai_tanda_jasa']['columns']['id_tanda_jasa']);

        // pegawai_ak_siasn masuk B-01 (snapshot riwayat_ak_siasn, diisi trigger legacy).
        $this->assertArrayHasKey('pegawai_ak_siasn', SkemaD1::B01);
        $this->assertSame(['fk_id_jabatan_peg_ak_siasn_03', 'fk_id_riwayat_ak_siasn_peg_ak_siasn_02'], array_keys(SkemaD1::B01['pegawai_ak_siasn']['later']));
    }

    public function testMigrationCreatesNoRows(): void
    {
        foreach (array_keys(SkemaD1::B01) as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (strictOn=true): default D1, CHECK [V2], FK anak (1452), FK induk RESTRICT (1451), PK snapshot satu baris
     * per nip.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->db->table('agama')->insert(['id_agama' => 1, 'agama' => 'Islam']);
        $this->insertPegawai(['nip' => self::NIP, 'id_agama' => 1]);
        $this->seeInDatabase('pegawai', ['nip' => self::NIP, 'status' => 1, 'flag_update' => 0, 'jenis_kelamin' => 1, 'siasn_flag' => 0]);

        // CHECK pegawai [V2].
        $this->assertDbError(self::ERR_CHECK, fn () => $this->insertPegawai(['status' => 3]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->insertPegawai(['flag_update' => 4]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->insertPegawai(['jenis_kelamin' => 0]));
        $this->insertPegawai(['status' => 10, 'flag_update' => 3, 'jenis_kelamin' => 2]);

        // FK master (target kosong kecuali agama 1).
        $this->assertDbError([1452], fn () => $this->db->table('pegawai')->update(['id_agama' => 99], ['nip' => self::NIP]));
        $this->assertDbError([1452], fn () => $this->db->table('pegawai')->update(['id_provinsi_lahir' => '31'], ['nip' => self::NIP]));

        // pegawai_hist [K]: default D1 flag_update 0, show_ua_* 0 NOT NULL; CHECK flag_update 0..3.
        $hist = ['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01'];
        $this->db->table('pegawai_hist')->insert($hist);
        $this->seeInDatabase('pegawai_hist', ['nip' => self::NIP, 'flag_update' => 0, 'show_notif' => 0, 'show_ua_upt' => 0, 'show_ua_biro' => 0]);
        $this->db->table('pegawai_hist')->insert([...$hist, 'flag_update' => 1]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'flag_update' => 4]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'status' => 0]));
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'nip' => self::NIP_ASING]));

        // pegawai_foto: banyak baris per nip, nip wajib ada.
        $this->db->table('pegawai_foto')->insert(['nip' => self::NIP, 'foto' => 'foto_1.jpg']);
        $this->db->table('pegawai_foto')->insert(['nip' => self::NIP, 'foto' => 'foto_2.jpg']);
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_foto')->insert(['nip' => self::NIP_ASING, 'foto' => 'x.jpg']));

        // Snapshot: FK nip, satu baris per nip (PK).
        foreach (['pegawai_kp', 'pegawai_kgb', 'pegawai_keluarga', 'pegawai_mutasi_jabatan', 'pegawai_ak_siasn'] as $table) {
            $row = $this->barisMinimal(SkemaD1::B01[$table], ['nip' => self::NIP]);
            $this->assertDbError([1452], fn () => $this->db->table($table)->insert([...$row, 'nip' => self::NIP_ASING]));
            $this->db->table($table)->insert($row);
            $this->assertDbError([1062], fn () => $this->db->table($table)->insert($row));
            $this->assertSame(1, $this->db->table($table)->where('nip', self::NIP)->countAllResults(), "{$table} satu baris per nip");
        }

        // Default D1 snapshot (mis. pegawai_mutasi_jabatan.jenis_jabatan 1, jenis_jabatan_koord 3).
        $this->seeInDatabase('pegawai_mutasi_jabatan', ['nip' => self::NIP, 'jenis_jabatan' => 1, 'jenis_jabatan_koord' => 3]);

        // FK master snapshot (target kosong) ditolak.
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_kp')->update(['id_pangkat' => 33], ['nip' => self::NIP]));
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_mutasi_jabatan')->update(['id_gol_pppk' => 1], ['nip' => self::NIP]));

        // RESTRICT: pegawai yang masih dirujuk tidak bisa dihapus keras maupun diganti NIP-nya (B-06).
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', self::NIP)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->update(['nip' => '198501012010011999'], ['nip' => self::NIP]));
        $this->assertDbError([1451], fn () => $this->db->table('agama')->where('id_agama', 1)->delete());
    }

    /**
     * down() semua migration sejak 120000 (termasuk DBV-013) menghapus ke-17 tabel B-01; up() membuatnya kembali persis.
     */
    #[Group('db-isolasi-penuh')]
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $this->lepasSejak(self::FIRST_VERSION);

        foreach (array_keys(SkemaD1::B01) as $table) {
            $this->assertFalse($this->skemaTableExists($table), "{$table} harus terhapus oleh down()");
        }

        foreach (['pengguna', 'provinsi', 'faq_rate'] as $table) {
            $this->assertTrue($this->skemaTableExists($table), "{$table} tidak tersentuh");
        }

        $this->pasangUlang();
        $this->assertSkemaD1(SkemaD1::B01);
    }

    /**
     * Bila salah satu CREATE snapshot gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop (urutan
     * terbalik) lalu error dilempar ulang; tabel yang sudah ada sebelum run tidak disentuh.
     */
    #[Group('db-isolasi-penuh')]
    public function testFailedSnapshotUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->lepasSejak(self::SNAPSHOT_VERSION);
        $this->db->query('CREATE TABLE ' . $this->skemaQuoted(self::BLOCKER) . ' (`penghalang` INT NOT NULL) ENGINE=InnoDB');

        $error = null;

        try {
            $this->skemaMigration(self::SNAPSHOT_VERSION)->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena pegawai_alamat sudah ada.');
        $this->assertStringContainsString(self::BLOCKER, $error->getMessage());

        foreach (array_keys(SkemaD1::B01) as $table) {
            if (! in_array($table, ['pegawai', 'pegawai_hist', 'pegawai_foto', self::BLOCKER], true)) {
                $this->assertFalse($this->skemaTableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
            }
        }

        $this->assertSame(['penghalang' => 'int'], $this->skemaColumns(self::BLOCKER), 'tabel yang ada sebelum run tidak di-drop');
        $this->assertTrue($this->skemaTableExists('pegawai'), 'pegawai (migration sebelumnya) tidak tersentuh');

        $this->db->query('DROP TABLE ' . $this->skemaQuoted(self::BLOCKER));
        $this->pasangUlang();
        $this->assertSkemaD1(SkemaD1::B01);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPegawai(array $overrides): void
    {
        static $seq = 0;
        $seq++;

        $this->db->table('pegawai')->insert([
            'nip'       => sprintf('19700101200001%04d', $seq),
            'nama'      => "Pegawai Uji {$seq}",
            'tgl_lahir' => '1970-01-01',
            ...$overrides,
        ]);
    }
}
