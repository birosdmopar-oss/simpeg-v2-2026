<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DITAHAN — JANGAN taruh di app/Database/Migrations sebelum DBV-008 (G-02) merge ke `main`.
 * Disimpan di branch lokal `dbv-012/fk-ditahan` (dokumen Bagian 7); timestamp ditetapkan ulang agar lebih besar dari migration G-02.
 *
 * DBV-012 — B-01: FK snapshot `pegawai_*` (2026-09-30-120100) → tabel master G-02 (DBV-008). Index untuk setiap kolom
 * sudah ada di DDL snapshot (D1), jadi di sini cukup ADD CONSTRAINT. Nama, kolom, induk FK = dump struktur produksi
 * 01-10-2026 (D1) [K]; aksi ON DELETE RESTRICT ON UPDATE RESTRICT [V2] (D1: SET NULL/CASCADE). Review DB
 * Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di Dev/Production sebelum
 * disetujui DBV-012.
 *
 * Cakupan: 11 FK ke tabel yang ada di draf DBV-008 (group_jabatan, sub_group_jabatan, unit, satker, jabatan; PK INT).
 * 4 FK lain [K D1] BELUM bisa dipasang karena tabel induknya belum ada di draf DBV-008 (dicek 30-09-2026) — lihat
 * self::MENUNGGU_TABEL; dipasang migration terpisah setelah tabelnya dibuat (DBV-008 atau DBV lain).
 *
 * up() fail-closed: tabel/kolom induk wajib ada, dan tidak boleh ada orphan (pesan menyebut jumlah per FK). FK yang
 * sudah ada dilewati; bila salah satu ALTER gagal, FK yang dipasang PADA RUN ITU dilepas lagi. down() hanya melepas FK.
 */
class AddFkG02Pegawai extends Migration
{
    /**
     * nama FK => [tabel snapshot, kolom, tabel G-02, kolom PK].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const FOREIGN_KEYS = [
        'fk_id_group_jabatan_pmj_to_gj'      => ['pegawai_mutasi_jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        'fk_id_sub_group_jabatan_pmj_to_sgj' => ['pegawai_mutasi_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        'fk_id_unit_pmj_to_unit'             => ['pegawai_mutasi_jabatan', 'id_unit', 'unit', 'id_unit'],
        'fk_id_satker_pmj_to_satker'         => ['pegawai_mutasi_jabatan', 'id_satker', 'satker', 'id_satker'],
        'fk_id_jabatan_pmj_to_jabatan'       => ['pegawai_mutasi_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_1_pmj_to_jabatan'   => ['pegawai_mutasi_jabatan', 'id_atasan_es_1', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_2_pmj_to_jabatan'   => ['pegawai_mutasi_jabatan', 'id_atasan_es_2', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_3_pmj_to_jabatan'   => ['pegawai_mutasi_jabatan', 'id_atasan_es_3', 'jabatan', 'id_jabatan'],
        'fk_id_atasan_es_4_pmj_to_jabatan'   => ['pegawai_mutasi_jabatan', 'id_atasan_es_4', 'jabatan', 'id_jabatan'],
        'fk_id_jabatan_cak_to_jab'           => ['pegawai_ak', 'id_jabatan', 'jabatan', 'id_jabatan'],
        'fk_id_jabatan_peg_ak_siasn_03'      => ['pegawai_ak_siasn', 'id_jabatan', 'jabatan', 'id_jabatan'],
    ];

    /**
     * FK [K D1] yang tabel induknya belum ada di draf DBV-008 — TIDAK dipasang migration ini.
     * nama FK => [tabel snapshot, kolom, tabel induk, kolom PK; nama/kolom/induk = D1].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const MENUNGGU_TABEL = [
        'fk_id_jabatan_koord_pmj_to_jabkoor'     => ['pegawai_mutasi_jabatan', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'fk_id_atasan_es_3_koord_pmj_to_jabkoor' => ['pegawai_mutasi_jabatan', 'id_atasan_es_3_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'fk_id_atasan_es_4_koord_pmj_to_jabkoor' => ['pegawai_mutasi_jabatan', 'id_atasan_es_4_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'pegawai_mutasi_jabatan_ibfk_02'         => ['pegawai_mutasi_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', 'id_rumpun_jabatan'],
    ];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        $this->assertParentsExist();
        $this->assertNoOrphans();

        $added = [];

        try {
            foreach (self::FOREIGN_KEYS as $name => [$table, $column, $parent, $parentColumn]) {
                if ($this->foreignKeyExists($table, $name)) {
                    continue;
                }

                $this->exec("ALTER TABLE {$this->t($table)} ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`)
                    REFERENCES {$this->t($parent)} (`{$parentColumn}`) " . self::RESTRICT);
                $added[$name] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($added, true) as $name => $table) {
                try {
                    $this->db->query("ALTER TABLE {$this->t($table)} DROP FOREIGN KEY `{$name}`");
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa FK dibersihkan manual.
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::FOREIGN_KEYS, true) as $name => [$table]) {
            if ($this->foreignKeyExists($table, $name)) {
                $this->exec("ALTER TABLE {$this->t($table)} DROP FOREIGN KEY `{$name}`");
            }
        }
    }

    private function assertParentsExist(): void
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
                'DBV-012: FK snapshot → G-02 tidak dipasang — kolom belum ada: ' . implode(', ', array_keys($missing))
                . '. Migration ini baru boleh dijalankan setelah DBV-008 (G-02) merge.',
            );
        }
    }

    private function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::FOREIGN_KEYS as $name => [$table, $column, $parent, $parentColumn]) {
            $row = $this->first(
                "SELECT COUNT(*) AS n FROM {$this->t($table)} s
                 WHERE s.`{$column}` IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM {$this->t($parent)} m WHERE m.`{$parentColumn}` = s.`{$column}`)",
            );
            $orphans = (int) ($row['n'] ?? 0);

            if ($orphans > 0) {
                $problems[] = "{$name} ({$table}.{$column} → {$parent}): {$orphans} baris";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-012: FK snapshot → G-02 tidak dipasang — nilai yatim: ' . implode('; ', $problems)
                . '. Impor master G-02 dulu atau perbaiki nilainya, lalu jalankan ulang migrate.',
            );
        }
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

            throw new RuntimeException('DBV-012: query gagal (' . $error['code'] . '): ' . $error['message']);
        }

        return $result->getRowArray() ?? [];
    }

    private function t(string $table): string
    {
        return $this->connection()->escapeIdentifiers($this->connection()->prefixTable($table));
    }

    private function connection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-012: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-012: ALTER FK snapshot → G-02 gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
