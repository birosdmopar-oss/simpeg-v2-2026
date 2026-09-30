<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 keranjang (d): FK dari tabel riwayat/penugasan B-02 ke master G-02 (`group_jabatan`,
 * `sub_group_jabatan`, `unit`, `satker`, `jabatan`, `jabatan_koordinasi`, `rumpun_jabatan`). Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.2.9, 7) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013
 * dan sebelum migration G-02 (DBV-008) ada di `main`.
 *
 * DITAHAN: file ini TIDAK ikut PR B-01/B-02. Disimpan di branch lokal `dbv-012/fk-ditahan` sampai DBV-008 merge; timestamp
 * ditetapkan ulang saat rebase agar lebih besar dari migration G-02. Semua KEY bernama FK sudah dibuat oleh migration
 * Create B-02 (kelompok 2: `130100`; kelompok 3: `130400`, `130600`), sehingga di sini cukup ADD CONSTRAINT.
 *
 * Nama FK = nama legacy ERD `simpeg01.erd` [K-erd] persis. Aksi legacy [L]/seed ON DELETE SET NULL ON UPDATE CASCADE →
 * v2 RESTRICT/RESTRICT (K1; master G-02 memakai soft delete status 10).
 *
 * Dua kelompok konstanta:
 *   - FK_DRAF_DBV008: induk dibuat draf DBV-008 (`unit`, `satker`, `group_jabatan`, `sub_group_jabatan`, `jabatan`).
 *   - FK_KOORD_RUMPUN: induk `jabatan_koordinasi` / `rumpun_jabatan` BELUM ada di draf DBV-008 (dicek 30-09-2026). Bila
 *     DBV-008 tidak membuat kedua tabel itu, pindahkan konstanta ini ke migration terpisah yang dijalankan setelah
 *     tabelnya ada; jangan dijalankan sebagian.
 *
 * Fail-closed: sebelum ALTER apa pun, up() menghitung baris anak yang nilainya tidak NULL dan tidak punya induk
 * (orphan, termasuk nilai 0 hasil `addslashes('')` legacy) untuk SETIAP FK; bila ada, up() melempar exception tanpa
 * mengubah skema. FK dipasang satu ALTER per tabel; bila satu ALTER gagal, FK tabel yang sudah dipasang PADA RUN ITU
 * di-drop (urutan terbalik) lalu error dilempar ulang.
 */
class AddFkG02Riwayat extends Migration
{
    /**
     * [tabel anak, nama FK (= nama KEY yang sudah ada), kolom anak, tabel induk, kolom induk]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const FK_DRAF_DBV008 = [
        // Kelompok 2 — riwayat_mutasi_jabatan (9 dari 13 FK G-02)
        ['riwayat_mutasi_jabatan', 'fk_id_group_jabatan_rwymj_to_gj', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['riwayat_mutasi_jabatan', 'fk_id_sub_group_jabatan_rwymj_to_sgj', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['riwayat_mutasi_jabatan', 'fk_id_unit_rwymj_to_unit', 'id_unit', 'unit', 'id_unit'],
        ['riwayat_mutasi_jabatan', 'fk_id_satker_rwymj_to_satker', 'id_satker', 'satker', 'id_satker'],
        ['riwayat_mutasi_jabatan', 'fk_id_jabatan_rwymj_to_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['riwayat_mutasi_jabatan', 'fk_id_atasan_es_1_rwymj_to_jabatan', 'id_atasan_es_1', 'jabatan', 'id_jabatan'],
        ['riwayat_mutasi_jabatan', 'fk_id_atasan_es_2_rwymj_to_jabatan', 'id_atasan_es_2', 'jabatan', 'id_jabatan'],
        ['riwayat_mutasi_jabatan', 'fk_id_atasan_es_3_rwymj_to_jabatan', 'id_atasan_es_3', 'jabatan', 'id_jabatan'],
        ['riwayat_mutasi_jabatan', 'fk_id_atasan_es_4_rwymj_to_jabatan', 'id_atasan_es_4', 'jabatan', 'id_jabatan'],
        // Kelompok 2 — pegawai_plt (9 dari 10)
        ['pegawai_plt', 'fk_id_group_jabatan_plt_to_gj', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['pegawai_plt', 'fk_id_group_jabatan_plt_plt_to_gj', 'id_group_jabatan_plt', 'group_jabatan', 'id_group_jabatan'],
        ['pegawai_plt', 'fk_id_sub_group_jabatan_plt_to_sgj', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['pegawai_plt', 'fk_id_sub_group_jabatan_plt_plt_to_sgj', 'id_sub_group_jabatan_plt', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['pegawai_plt', 'fk_id_unit_plt_to_unit', 'id_unit', 'unit', 'id_unit'],
        ['pegawai_plt', 'fk_id_unit_plt_plt_to_unit', 'id_unit_plt', 'unit', 'id_unit'],
        ['pegawai_plt', 'fk_id_satker_plt_to_satker', 'id_satker', 'satker', 'id_satker'],
        ['pegawai_plt', 'fk_id_satker_plt_plt_to_satker', 'id_satker_plt', 'satker', 'id_satker'],
        ['pegawai_plt', 'fk_id_jabatan_plt_to_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        // Kelompok 2 — pegawai_plh (9 dari 10; pemetaan nomor ibfk -> kolom [I], lihat 130100)
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_02', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_04', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_05', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_06', 'id_unit', 'unit', 'id_unit'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_07', 'id_satker', 'satker', 'id_satker'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_08', 'id_group_jabatan_plh', 'group_jabatan', 'id_group_jabatan'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_09', 'id_sub_group_jabatan_plh', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_10', 'id_unit_plh', 'unit', 'id_unit'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_11', 'id_satker_plh', 'satker', 'id_satker'],
        // Kelompok 3 — nama & kolom dari ERD; cocokkan dengan migration 130400/130600 sebelum branch ini di-rebase.
        ['riwayat_lckh', 'fk_riwayat_lckh_ibfk_03', 'id_unit', 'unit', 'id_unit'],
        ['riwayat_lckh', 'fk_riwayat_lckh_ibfk_04', 'id_satker', 'satker', 'id_satker'],
        ['riwayat_ak', 'fk_id_jabatan_rak_to_jab', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['riwayat_ak_siasn', 'fk_id_jabatan_rw_ak_siasn_02', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['konv_ak', 'fk_id_jabatan_konvak_to_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['konv_ak', 'fk_id_unit_konvak_to_unit', 'id_unit', 'unit', 'id_unit'],
        ['konv_ak', 'fk_id_satker_konvak_to_satker', 'id_satker', 'satker', 'id_satker'],
    ];

    /**
     * FK ke `jabatan_koordinasi` (PK `id_jabatan_koordinasi` INT [I `L_jabatan.php:2194, 2273`]) dan `rumpun_jabatan`
     * (PK `id_rumpun_jabatan` TINYINT [L pegawai_mutasi_jabatan]); kedua tabel belum ada di draf DBV-008.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const FK_KOORD_RUMPUN = [
        ['riwayat_mutasi_jabatan', 'fk_id_jabatan_koord_rmj_to_jabkoor', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['riwayat_mutasi_jabatan', 'fk_id_atasan_es_3_koord_rmj_to_jabkoor', 'id_atasan_es_3_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['riwayat_mutasi_jabatan', 'fk_id_atasan_es_4_koord_rmj_to_jabkoor', 'id_atasan_es_4_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['riwayat_mutasi_jabatan', 'riwayat_mutasi_jabatan_ibfk_02', 'id_rumpun_jabatan', 'rumpun_jabatan', 'id_rumpun_jabatan'],
        ['pegawai_plt', 'fk_id_jabatan_koord_plt_to_jabkoord', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['pegawai_plh', 'fk_pegawai_plh_ibfk_03', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
    ];

    public function up(): void
    {
        $byTable = $this->groupByTable();
        $this->assertNoOrphans();

        $altered = [];

        try {
            foreach ($byTable as $table => $fks) {
                $clauses = [];

                foreach ($fks as [, $name, $column, $parent, $parentColumn]) {
                    $clauses[] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES {$this->t($parent)} "
                        . "(`{$parentColumn}`) ON DELETE RESTRICT ON UPDATE RESTRICT";
                }

                $this->exec("ALTER TABLE {$this->t($table)} " . implode(', ', $clauses));
                $altered[] = $table;
            }
        } catch (Throwable $e) {
            $this->dropAdded($altered, $byTable);

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->groupByTable(), true) as $table => $fks) {
            $this->exec("ALTER TABLE {$this->t($table)} " . $this->dropClauses($fks));
        }
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string}>> tabel => FK
     */
    private function groupByTable(): array
    {
        $byTable = [];

        foreach (array_merge(self::FK_DRAF_DBV008, self::FK_KOORD_RUMPUN) as $fk) {
            $byTable[$fk[0]][] = $fk;
        }

        return $byTable;
    }

    /**
     * Pra-cek orphan untuk SEMUA FK sebelum ALTER pertama (fail-closed, skema tidak berubah bila ada orphan).
     */
    private function assertNoOrphans(): void
    {
        $orphans = [];

        foreach (array_merge(self::FK_DRAF_DBV008, self::FK_KOORD_RUMPUN) as [$table, $name, $column, $parent, $parentColumn]) {
            $query = $this->db->query(
                "SELECT COUNT(*) AS `n` FROM {$this->t($table)} AS `c` WHERE `c`.`{$column}` IS NOT NULL AND NOT EXISTS "
                . "(SELECT 1 FROM {$this->t($parent)} AS `p` WHERE `p`.`{$parentColumn}` = `c`.`{$column}`)",
            );

            if ($query === false) {
                $error = $this->db->error();

                throw new RuntimeException("DBV-013: pra-cek orphan {$name} gagal ({$error['code']}): {$error['message']}");
            }

            $count = (int) ($query->getRow()->n ?? 0);

            if ($count > 0) {
                $orphans[] = "{$table}.{$column} ({$name}): {$count} baris";
            }
        }

        if ($orphans !== []) {
            throw new RuntimeException('DBV-013: FK G-02 tidak dipasang, ada nilai tanpa induk — ' . implode('; ', $orphans));
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $fks
     */
    private function dropClauses(array $fks): string
    {
        return implode(', ', array_map(static fn (array $fk): string => "DROP FOREIGN KEY `{$fk[1]}`", $fks));
    }

    /**
     * Pembersihan setelah up() gagal: drop FK tabel yang sudah di-ALTER pada run ini (urutan terbalik). KEY bernama FK
     * tetap ada (milik migration Create). Kegagalan drop tidak menutupi error asli.
     *
     * @param list<string>                                                                          $altered
     * @param array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string}>> $byTable
     */
    private function dropAdded(array $altered, array $byTable): void
    {
        foreach (array_reverse($altered) as $table) {
            try {
                $this->db->query("ALTER TABLE {$this->t($table)} " . $this->dropClauses($byTable[$table]));
            } catch (Throwable) {
                // Lanjut ke tabel berikutnya; error asli tetap dilempar oleh up().
            }
        }
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-013: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-013: DDL FK G-02 gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
