<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Throwable;

/**
 * DBV-012 — registry kolom NIP, landasan B-06 (ganti NIP = salin baris pegawai → arahkan ulang setiap kolom yang
 * merujuk NIP lama → hapus baris lama, dalam satu transaksi; K1).
 *
 *   1. Daftar FK yang merujuk `pegawai(nip)` di skema = self::REGISTRY untuk migration yang sudah jalan (per versi, agar
 *      pemecahan PR DBV-012/DBV-013 tidak mengubah isi test). FK baru ke `pegawai` wajib didaftarkan di sini.
 *   2. Setiap FK itu RESTRICT/RESTRICT, kolomnya VARCHAR(30) utf8mb4_unicode_ci, dan NOT NULL kecuali yang tercatat di
 *      self::NULLABLE (akun non-pegawai K2, `nip_atasan` LKH [K] D1).
 *   3. Setiap kolom bernama NIP (`nip`, `NIP`, `nip_*`, `*_nip`, `*_nip_*`) di tabel aplikasi harus tercatat: sebagai
 *      FK di registry atau di self::NON_FK beserta alasannya. Kolom NIP baru tanpa klasifikasi = test gagal, sehingga
 *      B-06 tidak kehilangan kolom yang perlu diarahkan ulang.
 *
 * Ditambah pra-cek orphan fail-closed migration 2026-09-30-120200_AddFkPegawaiDiPenggunaFaqRate.
 *
 * Dokumen: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 4.3, 7).
 *
 * @internal
 */
final class NipReferenceRegistryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * FK → `pegawai(nip)` per versi migration: versi => [nama FK => [tabel, kolom]]. DBV-012 = 120000..120200;
     * DBV-013 = 130100..130900 (nama FK = D1 [K]).
     */
    private const REGISTRY = [
        '2026-09-30-120000' => [
            'fk_peg_hist_ibfk_01'   => ['pegawai_hist', 'nip'],
            'fk_nip_pegfoto_to_peg' => ['pegawai_foto', 'nip'],
        ],
        '2026-09-30-120100' => [
            'fk_nip_pegkp_to_pegawai'       => ['pegawai_kp', 'nip'],
            'fk_nip_pcpns_to_pegawai'       => ['pegawai_cpns', 'nip'],
            'fk_nip_ppns_to_pegawai'        => ['pegawai_pns', 'nip'],
            'fk_nip_pegkgb_to_pegawai'      => ['pegawai_kgb', 'nip'],
            'fk_nip_pegpend_to_pegawai'     => ['pegawai_pendidikan', 'nip'],
            'fk_nip_pegdiklat_to_pegawai'   => ['pegawai_diklat', 'nip'],
            'fk_nip_peghukdis_to_pegawai'   => ['pegawai_hukdis', 'nip'],
            'fk_nip_cak_to_peg'             => ['pegawai_ak', 'nip'],
            'fk_nip_peg_ak_siasn_01'        => ['pegawai_ak_siasn', 'nip'],
            'fk_nip_pegkeluarga_to_pegawai' => ['pegawai_keluarga', 'nip'],
            'fk_nip_pegalamat_to_pegawai'   => ['pegawai_alamat', 'nip'],
            'pegawai_alamat_kantor_ibfk_6'  => ['pegawai_alamat_kantor', 'nip'],
            'fk_nip_pegtj_to_pegawai'       => ['pegawai_tanda_jasa', 'nip'],
            'fk_nip_pmj_to_pegawai'         => ['pegawai_mutasi_jabatan', 'nip'],
        ],
        '2026-09-30-120200' => [
            'fk_id_pegawai_pengguna_to_pegawai' => ['pengguna', 'nip'],
            'fk_nip_faqrate_to_peg'             => ['faq_rate', 'nip'],
        ],
        '2026-09-30-130100' => [
            'fk_nip_rwymutasijabatan_to_pegawai' => ['riwayat_mutasi_jabatan', 'nip'],
            'fk_nip_plt_plt_to_pegawai'          => ['pegawai_plt', 'nip_plt'],
            'fk_pegawai_plh_ibfk_01'             => ['pegawai_plh', 'nip_plh'],
        ],
        '2026-09-30-130200' => [
            'fk_nip_rwykp_to_pegawai'  => ['riwayat_kp', 'nip'],
            'fk_nip_rwykgb_to_pegawai' => ['riwayat_kgb', 'nip'],
        ],
        '2026-09-30-130300' => [
            'fk_nip_rwypendidikan_to_pegawai' => ['riwayat_pendidikan', 'nip'],
            'fk_nip_rwydiklat_to_pegawai'     => ['riwayat_diklat', 'nip'],
            'fk_nip_rwyseminar_to_pegawai'    => ['riwayat_seminar', 'nip'],
        ],
        '2026-09-30-130400' => [
            'fk_nip_rwyskp_to_pegawai' => ['riwayat_skp', 'nip'],
            'fk_riwayat_lckh_ibfk_01'  => ['riwayat_lckh', 'nip'],
            'fk_riwayat_lckh_ibfk_02'  => ['riwayat_lckh', 'nip_atasan'],
        ],
        '2026-09-30-130500' => [
            'fk_nip_abijin_to_pegawai' => ['absen_ijin', 'nip'],
        ],
        '2026-09-30-130600' => [
            'fk_nip_rwyhukdis_to_pegawai'    => ['riwayat_hukdis', 'nip'],
            'fk_nip_rak_to_peg'              => ['riwayat_ak', 'nip'],
            'fk_nip_rw_ak_siasn_01'          => ['riwayat_ak_siasn', 'nip'],
            'fk_nip_konvak_to_pegawai'       => ['konv_ak', 'nip'],
            'fk_nip_ignorekonvak_to_pegawai' => ['ignore_konv_ak', 'nip'],
        ],
        '2026-09-30-130700' => [
            'fk_nip_rwykeluarga_to_pegawai' => ['riwayat_keluarga', 'nip'],
            'fk_nip_rwyalamat_to_pegawai'   => ['riwayat_alamat', 'nip'],
        ],
        '2026-09-30-130800' => [
            'fk_nip_rkarpeg_to_pegawai'       => ['riwayat_karpeg', 'nip'],
            'fk_nip_rkariskarsu_to_pegawai'   => ['riwayat_kariskarsu', 'nip'],
            'fk_nip_rwytj_to_pegawai'         => ['riwayat_tanda_jasa', 'nip'],
            'fk_nip_rwyorganisasi_to_pegawai' => ['riwayat_organisasi', 'nip'],
        ],
        '2026-09-30-130900' => [
            'fk_NIP_da_to_pegawai' => ['document_attachment', 'NIP'],
        ],
    ];

    /**
     * Kolom FK ke pegawai yang boleh NULL: tabel.kolom => alasan.
     */
    private const NULLABLE = [
        'pengguna.nip'            => 'K2/DBV-010: akun non-pegawai tanpa NIP',
        'riwayat_lckh.nip_atasan' => '[K] D1 riwayat_lckh.nip_atasan DEFAULT NULL',
    ];

    /**
     * Kolom bernama NIP yang sengaja TANPA FK ke pegawai: tabel.kolom => alasan (perlakuan saat ganti NIP diputuskan
     * B-06). Entri untuk tabel yang belum ada diabaikan.
     */
    private const NON_FK = [
        'pegawai.nip'                             => 'PK induk registry',
        'pegawai.nip_lama'                        => 'NIP lama (data historis), bukan rujukan',
        'pegawai_hist.nip_lama'                   => 'salinan pegawai.nip_lama',
        'token.nip'                               => 'jejak NIP pemilik saat token terbit (A-01 D-7)',
        'audit_logs.nip_actor'                    => 'jejak NIP pelaku saat kejadian (A-01 D-8)',
        'riwayat_skp.nip_penilai'                 => 'legacy tanpa FK (penilai bisa di luar instansi)',
        'riwayat_skp.nip_atasan_penilai'          => 'legacy tanpa FK (atasan penilai bisa di luar instansi)',
        'riwayat_skp_periodik.nip'                => 'data API BKN e-Kinerja, tanpa FK',
        'riwayat_skp_periodik.pegawai_atasan_nip' => 'data API BKN e-Kinerja, tanpa FK',
        'jabatan_koordinasi.nip'                  => 'legacy tanpa FK (D1; DBV-018), pejabat koordinasi — tetap diarahkan ulang di B-06',
    ];

    /**
     * Tabel test (namespace Tests\Support) yang punya kolom nip; bukan tabel aplikasi.
     */
    private const TEST_SUPPORT_TABLES = ['riwayat_dummy', 'pegawai_dummy'];

    private const FK_MASUK_VERSION = '2026-09-30-120200';

    private const NIP_ORPHAN = '199912312099121001';

    private const USERNAME_NON_PEGAWAI = 'nonpegawai.uji';

    /**
     * FK masuk yang dilepas test pra-cek orphan; dipasang ulang di tearDown bila test gagal di tengah.
     */
    private ?Migration $fkMasuk = null;

    /**
     * Akun tanpa NIP ditolak down() DBV-010 saat regress test berikutnya: selalu dibersihkan. Bila test pra-cek gagal
     * di tengah, baris orphan dibuang lalu FK masuk dipasang ulang agar skema sesuai tabel migrations.
     */
    protected function tearDown(): void
    {
        $this->db->table('pengguna')->where('username', self::USERNAME_NON_PEGAWAI)->delete();

        if ($this->fkMasuk !== null) {
            $this->db->table('faq_rate')->where('nip', self::NIP_ORPHAN)->delete();
            $this->db->table('pengguna')->where('nip', self::NIP_ORPHAN)->delete();
            $this->fkMasuk->up();
            $this->fkMasuk = null;
        }

        parent::tearDown();
    }

    public function testForeignKeysToPegawaiMatchRegistry(): void
    {
        $applied  = $this->appliedVersions();
        $expected = [];

        foreach (self::REGISTRY as $version => $fks) {
            if (in_array($version, $applied, true)) {
                $expected += $fks;
            }
        }

        foreach (['2026-09-30-120000', '2026-09-30-120100', '2026-09-30-120200'] as $version) {
            $this->assertContains($version, $applied, "prasyarat: migration DBV-012 {$version} sudah jalan");
        }

        ksort($expected);
        $actual = $this->foreignKeysToPegawai();

        $this->assertSame(
            $expected,
            array_map(static fn (array $fk): array => [$fk['table'], $fk['column']], $actual),
            'FK ke pegawai(nip) harus sama dengan registry (daftarkan FK baru di self::REGISTRY)',
        );

        foreach ($actual as $name => $fk) {
            $key = "{$fk['table']}.{$fk['column']}";
            $this->assertSame(['RESTRICT', 'RESTRICT'], [$fk['update'], $fk['delete']], "{$name} RESTRICT/RESTRICT (K1)");

            $info = $this->columnInfo($fk['table'], $fk['column']);
            $this->assertSame('varchar(30)', strtolower((string) $info['COLUMN_TYPE']), "{$key} VARCHAR(30) = pegawai.nip");
            $this->assertSame('utf8mb4_unicode_ci', $info['COLLATION_NAME'], "{$key} collation");
            $this->assertSame(
                array_key_exists($key, self::NULLABLE) ? 'YES' : 'NO',
                $info['IS_NULLABLE'],
                "{$key} NOT NULL kecuali yang tercatat di NULLABLE",
            );
        }
    }

    public function testEveryNipColumnIsClassified(): void
    {
        $fkColumns = [];

        foreach ($this->foreignKeysToPegawai() as $fk) {
            $fkColumns[strtolower("{$fk['table']}.{$fk['column']}")] = true;
        }

        $nonFk        = array_change_key_case(self::NON_FK, CASE_LOWER);
        $unclassified = [];
        $both         = [];

        foreach ($this->nipColumns() as $key) {
            $lower = strtolower($key);
            $isFk  = isset($fkColumns[$lower]);

            if ($isFk && isset($nonFk[$lower])) {
                $both[] = $key;
            } elseif (! $isFk && ! isset($nonFk[$lower])) {
                $unclassified[] = $key;
            }
        }

        $this->assertSame([], $unclassified, 'kolom NIP tanpa klasifikasi: tambahkan FK (REGISTRY) atau alasan di NON_FK');
        $this->assertSame([], $both, 'kolom NIP tercatat sebagai FK sekaligus NON_FK');

        // Jejak di main (A-01) tetap tanpa FK.
        foreach (['token' => 'nip', 'audit_logs' => 'nip_actor'] as $table => $column) {
            $this->assertArrayNotHasKey("{$table}.{$column}", $fkColumns, "{$table}.{$column} tanpa FK (A-01)");
        }
    }

    /**
     * 120200 menolak jalan (sebelum ALTER apa pun) bila ada NIP yang tidak ada di pegawai, dengan jumlah per tabel;
     * setelah orphan dibereskan, up() memasang kedua FK; up()/down() aman diulang.
     */
    public function testFkMasukRefusesOrphansBeforeAnyAlter(): void
    {
        $migration = $this->migrationInstance(self::FK_MASUK_VERSION);
        $migration->down();
        $this->fkMasuk = $migration;

        $this->assertSame([], $this->fkNamesOn(['pengguna', 'faq_rate']), 'prasyarat: FK masuk terlepas');

        $this->db->table('pengguna')->insert(['nip' => self::NIP_ORPHAN, 'username' => 'orphan.uji', 'user_level' => 2]);
        $this->db->table('faq_topic')->insert(['faq_topic' => 'Topik Uji']);
        $this->db->table('faq_sub_topic')->insert(['id_faq_topic' => $this->db->insertID(), 'faq_sub_topic' => 'Sub Uji']);
        $this->db->table('faq_article')->insert([
            'id_faq_sub_topic' => $this->db->insertID(), 'title' => 'Artikel Uji', 'content' => '<p>x</p>', 'content_stripped' => 'x',
        ]);
        $this->db->table('faq_rate')->insert(['id_faq_article' => $this->db->insertID(), 'nip' => self::NIP_ORPHAN, 'rate' => 1]);

        // Akun non-pegawai (nip NULL, K2) bukan orphan.
        $this->db->table('pengguna')->insert(['nip' => null, 'username' => self::USERNAME_NON_PEGAWAI, 'user_level' => 1]);

        $error = null;

        try {
            $migration->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'up() harus fail-closed');
        $this->assertStringContainsString('pengguna.nip: 1 ', $error->getMessage());
        $this->assertStringContainsString('faq_rate.nip: 1 ', $error->getMessage());
        $this->assertSame([], $this->fkNamesOn(['pengguna', 'faq_rate']), 'tidak ada FK yang terpasang sebagian');

        // Setelah pegawai-nya ada, FK terpasang; akun non-pegawai tetap boleh.
        $this->db->table('pegawai')->insert(['nip' => self::NIP_ORPHAN, 'nama' => 'Pegawai Uji', 'tgl_lahir' => '1999-12-31']);
        $migration->up();
        $migration->up();
        $this->fkMasuk = null;

        $this->assertSame(
            ['fk_id_pegawai_pengguna_to_pegawai', 'fk_nip_faqrate_to_peg'],
            $this->fkNamesOn(['pengguna', 'faq_rate']),
        );

        // FK aktif: pengguna/faq_rate dengan NIP asing ditolak (1452); pegawai yang dirujuk tidak bisa dihapus (1451).
        $this->assertDbError([1452], fn () => $this->db->table('pengguna')->insert(['nip' => '199912312099121002', 'username' => 'asing.uji', 'user_level' => 2]));
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', self::NIP_ORPHAN)->delete());

        // down() melepas FK tanpa membuang index lama (UNIQUE pengguna.nip, KEY faq_rate G-10), aman diulang.
        $migration->down();
        $migration->down();
        $this->assertSame([], $this->fkNamesOn(['pengguna', 'faq_rate']));
        $this->assertTrue($this->indexExists('pengguna', 'nip'), 'UNIQUE pengguna.nip tetap');
        $this->assertTrue($this->indexExists('faq_rate', 'fk_nip_faqrate_to_peg'), 'KEY faq_rate G-10 tetap');
        $migration->up();
    }

    /**
     * @return list<string>
     */
    private function appliedVersions(): array
    {
        $rows = $this->db->table('migrations')->select('version')->where('namespace', 'App')->get()->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['version'], $rows);
    }

    /**
     * FK yang merujuk pegawai(nip), urut nama.
     *
     * @return array<string, array{table: string, column: string, update: string, delete: string}>
     */
    private function foreignKeysToPegawai(): array
    {
        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = ?
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), $this->db->prefixTable('pegawai'), 'nip'],
        )->getResultArray();

        $fks = [];

        foreach ($rows as $row) {
            $fks[(string) $row['CONSTRAINT_NAME']] = [
                'table'  => $this->stripPrefix((string) $row['TABLE_NAME']),
                'column' => (string) $row['COLUMN_NAME'],
                'update' => (string) $row['UPDATE_RULE'],
                'delete' => (string) $row['DELETE_RULE'],
            ];
        }

        ksort($fks);

        return $fks;
    }

    /**
     * Kolom bernama NIP di tabel ber-prefix (tanpa tabel test support): "tabel.kolom".
     *
     * @return list<string>
     */
    private function nipColumns(): array
    {
        $prefix = $this->db->getPrefix();
        $rows   = $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$this->db->getDatabase(), str_replace('_', '\_', $prefix) . '%'],
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            $table = $this->stripPrefix((string) $row['TABLE_NAME']);

            if (in_array($table, self::TEST_SUPPORT_TABLES, true)) {
                continue;
            }

            if (preg_match('/(^|_)nip(_|$)/i', (string) $row['COLUMN_NAME']) === 1) {
                $columns[] = "{$table}.{$row['COLUMN_NAME']}";
            }
        }

        return $columns;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string> nama FK ke pegawai pada tabel-tabel ini, urut nama
     */
    private function fkNamesOn(array $tables): array
    {
        $names = [];

        foreach ($this->foreignKeysToPegawai() as $name => $fk) {
            if (in_array($fk['table'], $tables, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $index],
        )->getRowArray()['n'] > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInfo(string $table, string $column): array
    {
        return $this->db->query(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray() ?? [];
    }

    private function migrationInstance(string $version): Migration
    {
        $files = glob(APPPATH . 'Database/Migrations/' . $version . '_*.php') ?: [];

        if (count($files) !== 1) {
            throw new RuntimeException("File migration {$version} tidak ditemukan (atau lebih dari satu).");
        }

        require_once $files[0];
        $class = 'App\\Database\\Migrations\\' . substr(basename($files[0], '.php'), strlen($version) + 1);

        /** @var Migration $migration */
        $migration = new $class();

        return $migration;
    }

    private function assertDbError(array $codes, callable $write): void
    {
        $code = null;

        try {
            if ($write() === false) {
                $code = (int) ($this->db->error()['code'] ?? 0);
            }
        } catch (Throwable $e) {
            for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                if (in_array((int) $t->getCode(), $codes, true)) {
                    $code = (int) $t->getCode();

                    break;
                }
            }

            $code ??= (int) $e->getCode();
        }

        $this->assertContains($code, $codes, 'kode error DB yang diharapkan: ' . implode('/', $codes));
    }

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
