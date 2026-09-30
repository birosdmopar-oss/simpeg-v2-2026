<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * DBV-012 — registry kolom NIP, landasan B-06 (ganti NIP = salin baris pegawai → arahkan ulang setiap kolom yang
 * merujuk NIP lama → hapus baris lama, dalam satu transaksi; K1).
 *
 *   1. Daftar FK yang merujuk `pegawai(nip)` di skema = self::REGISTRY untuk migration yang sudah jalan (per versi, agar
 *      pemecahan PR DBV-012/DBV-013 tidak mengubah isi test). FK baru ke `pegawai` wajib didaftarkan di sini.
 *   2. Setiap FK itu RESTRICT/RESTRICT, kolomnya VARCHAR(30) utf8mb4_unicode_ci, dan NOT NULL kecuali yang tercatat di
 *      self::NULLABLE (`nip_atasan` LKH [K-m]; akun non-pegawai K2 saat FK masuk diaktifkan).
 *   3. Setiap kolom bernama NIP (`nip`, `NIP`, `nip_*`, `*_nip`, `*_nip_*`) di tabel aplikasi harus tercatat: sebagai
 *      FK di registry atau di self::NON_FK beserta alasannya. Kolom NIP baru tanpa klasifikasi = test gagal, sehingga
 *      B-06 tidak kehilangan kolom yang perlu diarahkan ulang.
 *
 * FK masuk `pengguna.nip`/`faq_rate.nip` → `pegawai` (migration AddFkPegawaiDiPenggunaFaqRate) DITAHAN di luar folder
 * Migrations sampai prasyaratnya terpenuhi (dokumen Bagian 2.1.5, keputusan B01-1); selama itu kedua kolom tercatat di
 * self::NON_FK. Test pra-cek orphan migration itu ikut disimpan bersama migration yang ditahan.
 *
 * Dokumen: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.0.8 registry NIP).
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
     * FK → `pegawai(nip)` per versi migration: versi => [nama FK => [tabel, kolom]]. DBV-012 = 120000..120100;
     * DBV-013 = 130100..130900 (nama FK [K-erd]).
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
            'fk_nip_pegkeluarga_to_pegawai' => ['pegawai_keluarga', 'nip'],
            'fk_nip_pegalamat_to_pegawai'   => ['pegawai_alamat', 'nip'],
            'pegawai_alamat_kantor_ibfk_6'  => ['pegawai_alamat_kantor', 'nip'],
            'fk_nip_pegtj_to_pegawai'       => ['pegawai_tanda_jasa', 'nip'],
            'fk_nip_pmj_to_pegawai'         => ['pegawai_mutasi_jabatan', 'nip'],
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
        'riwayat_lckh.nip_atasan' => '[K-m] d_lkh.nip_atasan DEFAULT NULL',
    ];

    /**
     * Kolom bernama NIP yang sengaja TANPA FK ke pegawai: tabel.kolom => alasan (perlakuan saat ganti NIP diputuskan
     * B-06). Entri untuk tabel yang belum ada diabaikan.
     */
    private const NON_FK = [
        'pegawai.nip'                             => 'PK induk registry',
        'pegawai.nip_lama'                        => 'NIP lama (data historis), bukan rujukan',
        'pegawai_hist.nip_lama'                   => 'salinan pegawai.nip_lama',
        'pengguna.nip'                            => 'FK fk_id_pegawai_pengguna_to_pegawai DITAHAN (dokumen 2.1.5, B01-1)',
        'faq_rate.nip'                            => 'FK fk_nip_faqrate_to_peg DITAHAN (dokumen 2.1.5, B01-1)',
        'token.nip'                               => 'jejak NIP pemilik saat token terbit (A-01 D-7)',
        'audit_logs.nip_actor'                    => 'jejak NIP pelaku saat kejadian (A-01 D-8)',
        'riwayat_skp.nip_penilai'                 => 'legacy tanpa FK (penilai bisa di luar instansi)',
        'riwayat_skp.nip_atasan_penilai'          => 'legacy tanpa FK (atasan penilai bisa di luar instansi)',
        'riwayat_skp_periodik.nip'                => 'data API BKN e-Kinerja, tanpa FK',
        'riwayat_skp_periodik.pegawai_atasan_nip' => 'data API BKN e-Kinerja, tanpa FK',
    ];

    /**
     * Tabel test (namespace Tests\Support) yang punya kolom nip; bukan tabel aplikasi.
     */
    private const TEST_SUPPORT_TABLES = ['riwayat_dummy', 'pegawai_dummy'];

    public function testForeignKeysToPegawaiMatchRegistry(): void
    {
        $applied  = $this->appliedVersions();
        $expected = [];

        foreach (self::REGISTRY as $version => $fks) {
            if (in_array($version, $applied, true)) {
                $expected += $fks;
            }
        }

        foreach (['2026-09-30-120000', '2026-09-30-120100'] as $version) {
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

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
