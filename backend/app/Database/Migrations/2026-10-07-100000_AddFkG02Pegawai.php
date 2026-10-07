<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DBV-019 (lanjutan DBV-012, B-01): 15 FK snapshot `pegawai_*` → master G-02 (`group_jabatan`, `sub_group_jabatan`,
 * `unit`, `satker`, `jabatan` — DBV-008) dan `jabatan_koordinasi`/`rumpun_jabatan` (DBV-018). Ditahan dari PR #20 sampai
 * migration G-02 ada di `main` (keputusan #12 dokumen B-01/B-02); kini dipasang sebagai unit DBV tersendiri.
 * Review DB Validator: backend/docs/db-review/DBV-019-fk-g02-pegawai-schema.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui DBV-019.
 *
 * Timestamp 2026-10-07 (bukan 2026-09-30-129900 draf) agar berjalan setelah semua migration yang sudah ada di `main`,
 * termasuk di lingkungan yang sudah ter-migrate sampai G-03/G-09 (2026-10-01-*).
 *
 * Nama, kolom, induk FK = dump struktur produksi 01-10-2026 (D1) [K] persis; aksi D1 ON DELETE SET NULL ON UPDATE
 * CASCADE → v2 ON DELETE RESTRICT ON UPDATE RESTRICT [V2] (keputusan #3 DBV-012/013; master memakai soft delete status
 * 10). Index untuk setiap kolom sudah dibuat DDL snapshot `120100` (D1), jadi di sini cukup ADD CONSTRAINT.
 *
 * up() fail-closed: (1) tabel/kolom anak dan induk wajib ada; (2) tidak boleh ada nilai yatim (tidak NULL dan tidak
 * ada di induk, termasuk 0 hasil `addslashes('')` legacy) — pesan menyebut jumlah per FK; keduanya dicek sebelum ALTER
 * pertama, jadi skema tidak berubah bila gagal. FK dipasang satu ALTER per tabel (satu kali rebuild tabel); FK yang
 * sudah ada dilewati; bila satu ALTER gagal, FK yang dipasang PADA RUN ITU dilepas lagi. down() hanya melepas FK
 * (index tetap milik `120100`).
 */
class AddFkG02Pegawai extends Migration
{
    /**
     * nama FK => [tabel snapshot, kolom, tabel induk, kolom PK induk]; urutan = urutan pemasangan.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const FOREIGN_KEYS = [
        // pegawai_mutasi_jabatan (D1:3192-3277) — 13 FK
        'fk_id_group_jabatan_pmj_to_gj'          => ['pegawai_mutasi_jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        'fk_id_sub_group_jabatan_pmj_to_sgj'     => ['pegawai_mutasi_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        'fk_id_unit_pmj_to_unit'                 => ['pegawai_mutasi_jabatan', 'id_unit', 'unit', 'id_unit'],
        'fk_id_satker_pmj_to_satker'             => ['pegawai_mutasi_jabatan', 'id_satker', 'satker', 'id_satker'],
        'fk_id_jabatan_pmj_to_jabatan'           => ['pegawai_mutasi_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_1_pmj_to_jabatan'       => ['pegawai_mutasi_jabatan', 'id_atasan_es_1', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_2_pmj_to_jabatan'       => ['pegawai_mutasi_jabatan', 'id_atasan_es_2', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_3_pmj_to_jabatan'       => ['pegawai_mutasi_jabatan', 'id_atasan_es_3', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_4_pmj_to_jabatan'       => ['pegawai_mutasi_jabatan', 'id_atasan_es_4', 'jabatan', 'id_jabatan'],
        'fk_id_jabatan_koord_pmj_to_jabkoor'     => ['pegawai_mutasi_jabatan', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'fk_id_atasan_es_3_koord_pmj_to_jabkoor' => ['pegawai_mutasi_jabatan', 'id_atasan_es_3_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'fk_id_atasan_es_4_koord_pmj_to_jabkoor' => ['pegawai_mutasi_jabatan', 'id_atasan_es_4_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'pegawai_mutasi_jabatan_ibfk_02'         => ['pegawai_mutasi_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', 'id_rumpun_jabatan'],
        // pegawai_ak (D1:2673-2717), pegawai_ak_siasn (D1:2742-2779)
        'fk_id_jabatan_cak_to_jab'      => ['pegawai_ak', 'id_jabatan', 'jabatan', 'id_jabatan'],
        'fk_id_jabatan_peg_ak_siasn_03' => ['pegawai_ak_siasn', 'id_jabatan', 'jabatan', 'id_jabatan'],
    ];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    private const KEY = 'DBV-019';

    public function up(): void
    {
        $this->assertColumnsExist();
        $this->assertNoOrphans();

        $added = [];

        try {
            foreach ($this->byTable() as $table => $fks) {
                $clauses = [];

                foreach ($fks as $name => [, $column, $parent, $parentColumn]) {
                    if ($this->foreignKeyExists($table, $name)) {
                        continue;
                    }

                    $clauses[$name] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES {$this->t($parent)} (`{$parentColumn}`) " . self::RESTRICT;
                }

                if ($clauses === []) {
                    continue;
                }

                $this->exec("ALTER TABLE {$this->t($table)} " . implode(', ', $clauses));
                $added[$table] = array_keys($clauses);
            }
        } catch (Throwable $e) {
            foreach (array_reverse($added, true) as $table => $names) {
                try {
                    $this->db->query("ALTER TABLE {$this->t($table)} " . $this->dropClauses($names));
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa FK dibersihkan manual (dokumen DBV-019 Bagian 5).
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->byTable(), true) as $table => $fks) {
            $names = array_values(array_filter(array_keys($fks), fn (string $name): bool => $this->foreignKeyExists($table, $name)));

            if ($names !== []) {
                $this->exec("ALTER TABLE {$this->t($table)} " . $this->dropClauses($names));
            }
        }
    }

    /**
     * @return array<string, array<string, array{0: string, 1: string, 2: string, 3: string}>> tabel => [nama FK => FK]
     */
    private function byTable(): array
    {
        $byTable = [];

        foreach (self::FOREIGN_KEYS as $name => $fk) {
            $byTable[$fk[0]][$name] = $fk;
        }

        return $byTable;
    }

    private function assertColumnsExist(): void
    {
        $missing = [];

        foreach (self::FOREIGN_KEYS as [$table, $column, $parent, $parentColumn]) {
            foreach ([[$table, $column], [$parent, $parentColumn]] as [$t, $c]) {
                if (! $this->columnExists($t, $c)) {
                    $missing["{$t}.{$c}"] = true;
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                self::KEY . ': FK snapshot → master G-02 tidak dipasang — kolom belum ada: ' . implode(', ', array_keys($missing))
                . '. Migration ini butuh migration G-02 (2026-09-30-100000, 2026-09-30-100100) dan B-01 (2026-09-30-120100).',
            );
        }
    }

    /**
     * Pra-cek nilai yatim untuk SEMUA FK sebelum ALTER pertama. ADD FOREIGN KEY sendiri juga menolak (1452), tetapi
     * tanpa jumlah per FK dan setelah sebagian tabel di-ALTER.
     */
    private function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::FOREIGN_KEYS as $name => [$table, $column, $parent, $parentColumn]) {
            $row = $this->first(
                "SELECT COUNT(*) AS n FROM {$this->t($table)} c
                 WHERE c.`{$column}` IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM {$this->t($parent)} p WHERE p.`{$parentColumn}` = c.`{$column}`)",
            );
            $orphans = (int) ($row['n'] ?? 0);

            if ($orphans > 0) {
                $problems[] = "{$name} ({$table}.{$column} → {$parent}): {$orphans} baris";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                self::KEY . ': FK snapshot → master G-02 tidak dipasang, ada nilai tanpa induk — ' . implode('; ', $problems)
                . '. Impor master dulu atau normalkan nilainya (0/kode tak dikenal → NULL), lalu jalankan ulang migrate.',
            );
        }
    }

    /**
     * @param list<string> $names
     */
    private function dropClauses(array $names): string
    {
        return implode(', ', array_map(static fn (string $name): string => "DROP FOREIGN KEY `{$name}`", $names));
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = $this->first(
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->connection()->prefixTable($table)}'
               AND COLUMN_NAME = '{$column}'",
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $row = $this->first(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->connection()->prefixTable($table)}'
               AND CONSTRAINT_NAME = '{$name}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function first(string $sql): array
    {
        $result = $this->connection()->query($sql);

        if (! $result instanceof ResultInterface) {
            $error = $this->connection()->error();

            throw new RuntimeException(self::KEY . ': query gagal (' . $error['code'] . '): ' . $error['message']);
        }

        return $result->getRowArray() ?? [];
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        return $this->connection()->escapeIdentifiers($this->connection()->prefixTable($table));
    }

    private function connection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException(self::KEY . ': koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException(self::KEY . ': ALTER FK snapshot → master G-02 gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
