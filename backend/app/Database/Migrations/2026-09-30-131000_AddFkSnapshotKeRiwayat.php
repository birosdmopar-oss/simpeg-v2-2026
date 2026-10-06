<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: 14 FK snapshot `pegawai_*` (DBV-012, 2026-09-30-120100) → tabel riwayat asalnya (DBV-013,
 * 2026-09-30-130100..130800). Dipisah dari CREATE TABLE snapshot karena tabel riwayat dibuat sesudahnya; KEY untuk
 * setiap kolom `id_riwayat_*` sudah ada di DDL snapshot (D1) dengan nama FK yang sama, jadi di sini cukup ADD
 * CONSTRAINT. Nama, kolom, dan induk FK = dump struktur produksi 01-10-2026 (D1) [K]. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di Dev/Production sebelum disetujui
 * DBV-013.
 *
 * Deviasi dari legacy [V2]: ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE, kecuali `pegawai_ak`,
 * `pegawai_ak_siasn`, `pegawai_mutasi_jabatan` SET NULL/CASCADE). Riwayat v2 dihapus lunak (status 10), jadi baris
 * riwayat yang masih menjadi snapshot tidak pernah dihapus keras; penghapusan keras (mis. B-06 atau pembersihan) wajib
 * mengosongkan/menyinkronkan snapshot dulu.
 *
 * up() fail-closed, sebelum ALTER apa pun: (1) tabel & kolom induk wajib ada (migration riwayat DBV-013 sudah jalan);
 * (2) tidak boleh ada nilai `id_riwayat_*` snapshot yang tidak ada di riwayat (orphan) — pesan menyebut jumlah per
 * FK. FK yang sudah ada dilewati; bila salah satu ALTER gagal, FK yang dipasang PADA RUN ITU dilepas lagi (urutan
 * terbalik) sebelum error dilempar ulang. down() hanya melepas FK yang ada; KEY tetap (milik migration snapshot).
 */
class AddFkSnapshotKeRiwayat extends Migration
{
    /**
     * nama FK => [tabel snapshot, kolom, tabel riwayat, kolom PK riwayat].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const FOREIGN_KEYS = [
        'fk_id_riwayat_kp_peg_kp_to_rwy_kp'              => ['pegawai_kp', 'id_riwayat_kp', 'riwayat_kp', 'id_riwayat_kp'],
        'fk_id_riwayat_kp_pcpns_to_rwy_kp'               => ['pegawai_cpns', 'id_riwayat_kp', 'riwayat_kp', 'id_riwayat_kp'],
        'fk_id_riwayat_kp_ppns_to_rwy_kp'                => ['pegawai_pns', 'id_riwayat_kp', 'riwayat_kp', 'id_riwayat_kp'],
        'fk_id_riwayat_kgb_pkgb_to_rkgb'                 => ['pegawai_kgb', 'id_riwayat_kgb', 'riwayat_kgb', 'id_riwayat_kgb'],
        'fk_id_riwayat_pendidikan_pegpend_to_rpend'      => ['pegawai_pendidikan', 'id_riwayat_pendidikan', 'riwayat_pendidikan', 'id_riwayat_pendidikan'],
        'fk_id_riwayat_diklat_pegdiklat_to_rwydiklat'    => ['pegawai_diklat', 'id_riwayat_diklat', 'riwayat_diklat', 'id_riwayat_diklat'],
        'fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis'  => ['pegawai_hukdis', 'id_riwayat_hukdis', 'riwayat_hukdis', 'id_riwayat_hukdis'],
        'fk_id_riwayat_ak_cak_to_rak'                    => ['pegawai_ak', 'id_riwayat_ak', 'riwayat_ak', 'id_riwayat_ak'],
        'fk_id_riwayat_ak_siasn_peg_ak_siasn_02'         => ['pegawai_ak_siasn', 'id_riwayat_ak_siasn', 'riwayat_ak_siasn', 'id_riwayat_ak_siasn'],
        'fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga' => ['pegawai_keluarga', 'id_riwayat_keluarga', 'riwayat_keluarga', 'id_riwayat_keluarga'],
        'fk_id_riwayat_alamat_pegalamat_riwalamat'       => ['pegawai_alamat', 'id_riwayat_alamat', 'riwayat_alamat', 'id_riwayat_alamat'],
        'pegawai_alamat_kantor_ibfk_5'                   => ['pegawai_alamat_kantor', 'id_riwayat_alamat', 'riwayat_alamat', 'id_riwayat_alamat'],
        'fk_id_riwayat_tanda_jasa_ptj_to_rtj'            => ['pegawai_tanda_jasa', 'id_riwayat_tanda_jasa', 'riwayat_tanda_jasa', 'id_riwayat_tanda_jasa'],
        'fk_id_riwayat_mutasi_jabatan_pmj_to_rmj'        => ['pegawai_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', 'riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan'],
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
                    // Error asli tetap dilempar; sisa FK dibersihkan manual (dokumen Bagian 9.3).
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

    /**
     * Tabel snapshot & riwayat beserta kolomnya wajib ada: migration ini bergantung pada DBV-012 (snapshot) dan
     * seluruh migration riwayat DBV-013. Pesan menyebut apa yang belum ada, bukan error 1824/1215 mentah.
     */
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
                'DBV-013: FK snapshot → riwayat tidak dipasang — kolom belum ada: ' . implode(', ', array_keys($missing))
                . '. Jalankan migration snapshot (DBV-012) dan seluruh migration riwayat DBV-013 dulu.',
            );
        }
    }

    /**
     * Pra-cek orphan (fail-closed): `id_riwayat_*` snapshot yang tidak ada di tabel riwayat.
     */
    private function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::FOREIGN_KEYS as $name => [$table, $column, $parent, $parentColumn]) {
            $row = $this->first(
                "SELECT COUNT(*) AS n FROM {$this->t($table)} s
                 WHERE s.`{$column}` IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM {$this->t($parent)} r WHERE r.`{$parentColumn}` = s.`{$column}`)",
            );
            $orphans = (int) ($row['n'] ?? 0);

            if ($orphans > 0) {
                $problems[] = "{$name} ({$table}.{$column} → {$parent}): {$orphans} baris";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-013: FK snapshot → riwayat tidak dipasang — snapshot merujuk riwayat yang tidak ada: '
                . implode('; ', $problems)
                . '. Impor riwayat dulu atau kosongkan id_riwayat_* yang yatim, lalu jalankan ulang migrate.',
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

            throw new RuntimeException('DBV-013: query gagal (' . $error['code'] . '): ' . $error['message']);
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
            throw new RuntimeException('DBV-013: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-013: ALTER FK snapshot → riwayat gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
