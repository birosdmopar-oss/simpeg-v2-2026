<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Kepegawaian\SkemaD1;
use Tests\Support\Kepegawaian\SkemaD1TestTrait;
use Throwable;

/**
 * DBV-013 — skema B-02 hasil migration 2026-09-30-130000..130900 harus sama dengan DDL produksi D1 (dump struktur
 * `simpeg01` 01-10-2026) kecuali deviasi [V2] yang dicatat di backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md
 * (Bagian 2-3), ditambah lookup `jenis_rwy` [V2] + seed 21 baris = konstanta legacy. Mengunci kolom, default/ON UPDATE,
 * COMMENT, collation, PRIMARY/UNIQUE/index, FK (nama D1, RESTRICT/RESTRICT), CHECK [V2], perilaku constraint,
 * down()/up() bersih, dan pembersihan saat up() gagal di tengah. Ekspektasi D1: Tests\Support\Kepegawaian\SkemaD1::B02.
 *
 * @internal
 */
final class RiwayatSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use SkemaD1TestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const NIP = '198501012010011001';

    private const NIP_ASING = '199912312099121001';

    private const ERR_CHECK = [3819, 4025];

    private const FIRST_VERSION = '2026-09-30-130000';

    private const BLOCKER_VERSION = '2026-09-30-130700';

    /**
     * Tabel penghalang (CREATE ke-2 migration 130700) dan tabel lain migration itu yang tidak boleh tertinggal.
     */
    private const BLOCKER = 'detail_anak';

    private const BLOCKER_SIBLINGS = ['riwayat_keluarga', 'riwayat_alamat'];

    /**
     * Lookup [V2] `jenis_rwy` (tidak ada di D1) dalam format ekspektasi SkemaD1.
     */
    private const JENIS_RWY = [
        'jenis_rwy' => [
            'columns' => [
                'id_jenis_rwy' => 'int',
                'kode'         => 'varchar(30)',
                'jenis_rwy'    => 'varchar(100)',
                'tabel_entri'  => 'varchar(64) null',
                'order'        => 'tinyint',
                'status'       => 'tinyint',
                'updated_at'   => 'datetime',
                'updated_by'   => 'int null',
            ],
            'defaults' => ['order' => '1', 'status' => '1', 'updated_at' => 'CURRENT_TIMESTAMP on update'],
            'comments' => [
                'id_jenis_rwy' => 'kode jenis arsip legacy (ARSIP_RWY) = document_attachment.id_riwayat; bukan AUTO_INCREMENT',
                'kode'         => 'kunci ARSIP_RWY legacy',
                'jenis_rwy'    => 'label JENIS_RWY_ARSIP legacy',
                'tabel_entri'  => 'tabel yang dirujuk document_attachment.id_entri; NULL = tanpa entri',
                'status'       => '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
                'updated_by'   => 'id_pengguna yang terakhir mengubah',
            ],
            'primary' => ['id_jenis_rwy'],
            'indexes' => ['uq_jenis_rwy_kode' => ['kode'], 'uq_jenis_rwy_nama' => ['jenis_rwy']],
            'uniques' => ['uq_jenis_rwy_kode', 'uq_jenis_rwy_nama'],
            'fks'     => [],
            'later'   => [],
            'checks'  => ['chk_jenis_rwy_status' => ['jenis_rwy', 'statusin1,2,10']],
        ],
    ];

    /**
     * Konstanta legacy `config/constants.php:193-238`, ditulis literal: ARSIP_RWY (kunci => kode) dan JENIS_RWY_ARSIP
     * (kode => label, urutan elemen = urutan tampil).
     */
    private const LEGACY_ARSIP_RWY = [
        'alamat'           => 1, 'anak' => 3, 'diklat' => 5, 'hukdis' => 7, 'jabatan' => 9, 'kgb' => 10, 'kp' => 11,
        'organisasi'       => 13, 'pendidikan' => 14, 'keluarga' => 20, 'seminar' => 22, 'tj' => 23, 'skp' => 32,
        'jabatan_pjft'     => 36, 'status_du' => 37, 'arsip_du' => 38, 'pencantuman_gelar' => 39, 'transkrip_nilai' => 40,
        'perjanjian_kerja' => 41, 'pmk' => 42,
    ];

    private const LEGACY_JENIS_RWY_ARSIP = [
        1  => 'Alamat', 3 => 'Anak', 38 => 'Data Umum', 5 => 'Pelatihan', 7 => 'Hukuman Disiplin', 9 => 'Jabatan',
        20 => 'Keluarga', 11 => 'Kenaikan Pangkat', 10 => 'KGB', 22 => 'Kursus / Seminar', 13 => 'Organisasi',
        36 => 'Pemberhentian Sementara JFT', 14 => 'Pendidikan', 32 => 'Sasaran Kerja', 37 => 'Status Data Umum',
        23 => 'Tanda Jasa', 39 => 'Arsip Pencantuman Gelar', 40 => 'Transkrip Nilai', 41 => 'Perjanjian Kerja',
        42 => 'Penyesuaian Masa Kerja',
    ];

    /**
     * Tabel tujuan `document_attachment.id_entri` per kode; NULL = tanpa entri.
     */
    private const TABEL_ENTRI = [
        0  => null, 1 => 'riwayat_alamat', 3 => 'detail_anak', 5 => 'riwayat_diklat', 7 => 'riwayat_hukdis',
        9  => 'riwayat_mutasi_jabatan', 10 => 'riwayat_kgb', 11 => 'riwayat_kp', 13 => 'riwayat_organisasi',
        14 => 'riwayat_pendidikan', 20 => 'riwayat_keluarga', 22 => 'riwayat_seminar', 23 => 'riwayat_tanda_jasa',
        32 => 'riwayat_skp', 36 => 'riwayat_mutasi_jabatan', 37 => null, 38 => null, 39 => 'riwayat_pendidikan',
        40 => 'riwayat_pendidikan', 41 => 'riwayat_mutasi_jabatan', 42 => 'riwayat_pmk',
    ];

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
        $this->assertSkemaD1(SkemaD1::B02, self::JENIS_RWY);
    }

    /**
     * Deviasi [V2] B-02 yang dikunci eksplisit selain FK RESTRICT/CHECK: NIP VARCHAR(30), tipe FK mengikuti PK master di
     * `main`, dan FK [V2] lampiran → `jenis_rwy`. Status `riwayat_pendidikan` tetap [K] INT NULL.
     */
    public function testV2DeviationsAreExplicit(): void
    {
        foreach (['riwayat_ak', 'riwayat_ak_siasn', 'konv_ak', 'ignore_konv_ak'] as $table) {
            $this->assertSame('varchar(30)', SkemaD1::B02[$table]['columns']['nip'], "{$table}.nip = pegawai.nip (D1 varchar(50))");
        }

        $this->assertSame('int null', SkemaD1::B02['riwayat_pendidikan']['columns']['id_jenjang_pendidikan']);
        $this->assertSame('int null', SkemaD1::B02['riwayat_hukdis']['columns']['id_jenis_hukdis']);
        $this->assertSame('int null', SkemaD1::B02['riwayat_tanda_jasa']['columns']['id_tanda_jasa']);
        $this->assertSame('int null', SkemaD1::B02['riwayat_pendidikan']['columns']['status'], 'status riwayat_pendidikan [K] INT NULL');
        $this->assertSame(['document_attachment', 'id_riwayat', 'jenis_rwy', 'id_jenis_rwy'], SkemaD1::B02['document_attachment']['fks']['fk_id_riwayat_da_to_jenis_rwy']);
        $this->assertSame(['nip'], SkemaD1::B02['konv_ak']['primary'], 'konv_ak PK = nip [K]');
        $this->assertSame(['id_rw_siasn' => ['id_rw_siasn']], array_intersect_key(SkemaD1::B02['riwayat_ak_siasn']['indexes'], ['id_rw_siasn' => true]));
    }

    public function testMigrationCreatesNoRowsExceptJenisRwySeed(): void
    {
        foreach (array_keys(SkemaD1::B02) as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }

        $this->assertSame(21, $this->db->table('jenis_rwy')->countAllResults());
    }

    /**
     * Seed `jenis_rwy` = konstanta legacy: kode & kunci (ARSIP_RWY), label & urutan (JENIS_RWY_ARSIP), ditambah baris 0
     * "Belum Terhubung" [V2] untuk `document_attachment.id_riwayat = 0` legacy. Semua aktif.
     */
    public function testJenisRwySeedMatchesLegacyConstants(): void
    {
        $rows = $this->db->query('SELECT `id_jenis_rwy`, `kode`, `jenis_rwy`, `tabel_entri`, `order`, `status`, `updated_by` FROM '
            . $this->skemaQuoted('jenis_rwy') . ' ORDER BY `order`, `id_jenis_rwy`')->getResultArray();

        $expected = [[0, 'belum_terhubung', 'Belum Terhubung', null, 0]];
        $kodeById = array_flip(self::LEGACY_ARSIP_RWY);
        $order    = 0;

        foreach (self::LEGACY_JENIS_RWY_ARSIP as $id => $label) {
            $expected[] = [$id, $kodeById[$id], $label, self::TABEL_ENTRI[$id], ++$order];
        }

        $this->assertCount(21, $rows);
        $this->assertSame(
            $expected,
            array_map(static fn (array $r): array => [
                (int) $r['id_jenis_rwy'], (string) $r['kode'], (string) $r['jenis_rwy'], $r['tabel_entri'], (int) $r['order'],
            ], $rows),
        );
        $this->assertSame(['1'], array_values(array_unique(array_map(static fn (array $r): string => (string) $r['status'], $rows))));
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'updated_by'))));
    }

    /**
     * Lapis DB (strictOn=true): default D1, CHECK status [V2] per tabel, FK anak (1452), RESTRICT (1451), UNIQUE/PK D1.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01']);
        $b02 = SkemaD1::B02;

        // Riwayat ber-workflow: default status 0 [K]; 3 "Diproses" diterima di tabel yang form admin legacy-nya
        // menulis 3; nilai di luar domain ditolak.
        foreach (['riwayat_kp', 'riwayat_kgb', 'riwayat_mutasi_jabatan', 'riwayat_keluarga', 'riwayat_hukdis'] as $table) {
            $row = $this->barisMinimal($b02[$table], ['nip' => self::NIP]);
            $this->db->table($table)->insert($row);
            $this->seeInDatabase($table, ['nip' => self::NIP, 'status' => 0]);
            $this->db->table($table)->insert([...$row, 'status' => 3]);
            $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table($table)->insert([...$row, 'status' => 4]));
            $this->assertDbError([1452], fn () => $this->db->table($table)->insert([...$row, 'nip' => self::NIP_ASING]));
        }

        foreach (['riwayat_alamat', 'riwayat_karpeg', 'riwayat_ak'] as $table) {
            $row = $this->barisMinimal($b02[$table], ['nip' => self::NIP]);
            $this->db->table($table)->insert([...$row, 'status' => 10]);
            $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table($table)->insert([...$row, 'status' => 3]));
        }

        // riwayat_pendidikan.status [K] INT NULL DEFAULT 0: NULL diterima, domain tetap dicek.
        $pend = $this->barisMinimal($b02['riwayat_pendidikan'], ['nip' => self::NIP]);
        $this->db->table('riwayat_pendidikan')->insert([...$pend, 'status' => null]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_pendidikan')->insert([...$pend, 'status' => 5]));

        // LKH: 3 = Revisi [K]; nip_atasan nullable FK.
        $lckh = $this->barisMinimal($b02['riwayat_lckh'], ['nip' => self::NIP]);
        $this->db->table('riwayat_lckh')->insert([...$lckh, 'status' => 3, 'nip_atasan' => null]);
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_lckh')->insert([...$lckh, 'nip_atasan' => '']));

        // absen_ijin [K] CHAR(2) W/V/X/10.
        $ijin = $this->barisMinimal($b02['absen_ijin'], ['nip' => self::NIP]);
        $this->db->table('absen_ijin')->insert($ijin);
        $this->seeInDatabase('absen_ijin', ['nip' => self::NIP, 'status' => 'W', 'affect_tukin' => 1]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('absen_ijin')->insert([...$ijin, 'status' => '1']));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('absen_ijin')->insert([...$ijin, 'affect_tukin' => 0]));

        // UNIQUE / PK D1: riwayat_ak_siasn.id_rw_siasn, konv_ak PK nip, ignore_konv_ak PK (nip, ignore_date).
        $siasn = $this->barisMinimal($b02['riwayat_ak_siasn'], ['nip' => self::NIP, 'id_rw_siasn' => 'abc']);
        $this->db->table('riwayat_ak_siasn')->insert($siasn);
        $this->assertDbError([1062], fn () => $this->db->table('riwayat_ak_siasn')->insert($siasn));
        $konv = $this->barisMinimal($b02['konv_ak'], ['nip' => self::NIP]);
        $this->db->table('konv_ak')->insert($konv);
        $this->assertDbError([1062], fn () => $this->db->table('konv_ak')->insert($konv));
        $this->db->table('ignore_konv_ak')->insert(['nip' => self::NIP, 'ignore_date' => '2024-01-01']);
        $this->assertDbError([1062], fn () => $this->db->table('ignore_konv_ak')->insert(['nip' => self::NIP, 'ignore_date' => '2024-01-01']));

        // detail_anak → riwayat_keluarga: anak wajib punya induk; induk yang punya anak tidak bisa dihapus keras.
        $idKeluarga = (int) $this->db->table('riwayat_keluarga')->select('id_riwayat_keluarga')->where('nip', self::NIP)->get()->getRowArray()['id_riwayat_keluarga'];
        $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => $idKeluarga, 'tgl_lahir' => '2015-06-01', 'nama' => 'Anak Uji']);
        $this->assertDbError([1452], fn () => $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => 424242, 'tgl_lahir' => '2015-06-01', 'nama' => 'Yatim']));
        $this->assertDbError([1451], fn () => $this->db->table('riwayat_keluarga')->where('id_riwayat_keluarga', $idKeluarga)->delete());

        // document_attachment [K] + FK [V2] ke jenis_rwy (0 & NULL diterima, kode di luar seed ditolak).
        $da = ['NIP' => self::NIP, 'filename' => 'sk.pdf', 'id_entri' => '1'];
        $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => 0]);
        $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => null]);
        $this->assertDbError([1452], fn () => $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => 33]));
        $this->assertDbError([1452], fn () => $this->db->table('document_attachment')->insert([...$da, 'NIP' => self::NIP_ASING, 'id_riwayat' => 1]));
        $this->assertDbError([1451], fn () => $this->db->table('jenis_rwy')->where('id_jenis_rwy', 0)->delete());

        // jenis_rwy: UNIQUE kode & label, CHECK status 1/2/10.
        $this->assertDbError([1062], fn () => $this->db->table('jenis_rwy')->insert(['id_jenis_rwy' => 99, 'kode' => 'alamat', 'jenis_rwy' => 'Lain']));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('jenis_rwy')->insert(['id_jenis_rwy' => 99, 'kode' => 'lain', 'jenis_rwy' => 'Lain', 'status' => 3]));

        // Master (target kosong) ditolak; RESTRICT ke pegawai (K1).
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_kp')->update(['id_pangkat' => 33], ['nip' => self::NIP]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_tanda_jasa')->insert($this->barisMinimal($b02['riwayat_tanda_jasa'], ['nip' => self::NIP, 'id_tanda_jasa' => 99])));
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', self::NIP)->delete());
    }

    /**
     * down() semua migration sejak 130000 (termasuk 131000) menghapus tabel B-02 tanpa menyentuh tabel B-01/master;
     * up() membuatnya kembali persis.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $this->lepasSejak(self::FIRST_VERSION);

        foreach ([...array_keys(SkemaD1::B02), 'jenis_rwy'] as $table) {
            $this->assertFalse($this->skemaTableExists($table), "{$table} harus terhapus oleh down()");
        }

        foreach (['pegawai', 'pegawai_kp', 'provinsi', 'pangkat'] as $table) {
            $this->assertTrue($this->skemaTableExists($table), "{$table} (migration sebelumnya) tidak tersentuh");
        }

        $this->pasangUlang();
        $this->assertSkemaD1(SkemaD1::B02, self::JENIS_RWY);
    }

    /**
     * Bila salah satu CREATE gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop lalu error dilempar
     * ulang; tabel yang sudah ada sebelum run tidak disentuh.
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->lepasSejak(self::BLOCKER_VERSION);
        $this->db->query('CREATE TABLE ' . $this->skemaQuoted(self::BLOCKER) . ' (`penghalang` INT NOT NULL) ENGINE=InnoDB');

        $error = null;

        try {
            $this->skemaMigration(self::BLOCKER_VERSION)->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena tabel penghalang sudah ada.');
        $this->assertStringContainsString(self::BLOCKER, $error->getMessage());

        foreach (self::BLOCKER_SIBLINGS as $table) {
            $this->assertFalse($this->skemaTableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        $this->assertSame(['penghalang' => 'int'], $this->skemaColumns(self::BLOCKER), 'tabel yang ada sebelum run tidak di-drop');
        $this->assertTrue($this->skemaTableExists('pegawai'), 'pegawai (migration sebelumnya) tidak tersentuh');

        $this->db->query('DROP TABLE ' . $this->skemaQuoted(self::BLOCKER));
        $this->pasangUlang();
        $this->assertSkemaD1(SkemaD1::B02, self::JENIS_RWY);
    }
}
