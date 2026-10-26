<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (1/9) — DRAF, DITAHAN. Berada di app/Database/MigrationsDitahan sehingga TIDAK dijalankan `php spark migrate`
 * dan tidak ikut CI. Dipindah ke app/Database/Migrations (nama tetap) hanya setelah DB Validator menyetujui dokumen
 * backend/docs/db-review/DBV-022-layanan-fase4-schema.md (Bagian 2.3, 2.14, 2.15, 2.18, 7). Isi = usulan coder.
 *
 * Master pendukung Modul C Fase 4 dari dump struktur produksi D1 [K]:
 *   `jenis_cuti` D1:1634-1643, `jenis_layanan` D1:1705-1714, `jenis_pa` D1:1733-1741, `question_category` D1:4008-4017.
 *
 * Deviasi [V2] (alasan di dokumen Bagian 2):
 *   - `jenis_cuti`: PK komposit (id, nama) → PK tunggal `id_jenis_cuti` + UNIQUE `nama_jenis_cuti` (DQ-9); seed 6 baris
 *     dengan id 1..6 dipaksa (Tukin v2 meng-hard-code `StatusHarian::JENIS_CUTI`); CHECK status 1/2/10.
 *   - `jenis_layanan`: CHECK status 1/2/10; seed 5 baris (id 1..5, AI=6 di D1) — nama [I] dari kode legacy,
 *     `url_process`/`url_pic` diisi path v2 (DQ-23; kolom tidak dibaca kode v2).
 *   - `jenis_pa`: kolom typo `udpated_at` → `updated_at` (DQ-24); collation 0900_ai_ci → unicode_ci (G2); CHECK 1/2/10;
 *     seed 1 baris (id 1 "Cuti", AI=2 di D1).
 *   - `question_category`: collation → unicode_ci (G2); `status` tetap domain [K] 0/1 + CHECK (0,1) dan DEFAULT 1 (DQ-5).
 *     Milik Fase 8; dibuat di sini hanya karena dirujuk FK `disiplin_ticket`.
 *   - Konvensi G2: `AUTO_INCREMENT=<n>` tidak ditulis (counter disalin saat impor), collation per kolom tidak ditulis.
 *
 * DDL ter-commit per statement: bila CREATE/INSERT berikutnya gagal, up() men-drop tabel yang sempat dibuat PADA RUN
 * ITU lalu melempar ulang error (pola DBV-020). down() men-drop keempat tabel urutan terbalik; selama tabel anak
 * (`riwayat_cuti`, `inbox`, `petugas_layanan`, `pa_layanan`, `disiplin_ticket`) masih ada, DROP ditolak (3730) —
 * rollback batch menjalankan migration anak lebih dulu.
 */
class CreateMasterLayananFase4 extends Migration
{
    public const TABLES = ['jenis_cuti', 'jenis_layanan', 'jenis_pa', 'question_category'];

    /**
     * Seed `jenis_cuti` [K] id 1..6 (nama = label legacy; urutan tampil = id).
     *
     * @var array<int, string>
     */
    public const JENIS_CUTI = [
        1 => 'Cuti Tahunan',
        2 => 'Cuti Besar',
        3 => 'Cuti Sakit',
        4 => 'Cuti Melahirkan',
        5 => 'Cuti Karena Alasan Penting',
        6 => 'Cuti di Luar Tanggungan Negara',
    ];

    /**
     * Seed `jenis_layanan` id => [jenis_layanan, table, module_name, url_process, url_pic] ([I] nama dari kode legacy;
     * path = rute v2, DQ-23).
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const JENIS_LAYANAN = [
        1 => ['KARPEG', 'riwayat_karpeg', 'karpeg', 'layanan/karpeg', 'layanan/petugas/karpeg'],
        2 => ['KARISKARSU', 'riwayat_kariskarsu', 'kariskarsu', 'layanan/kariskarsu', 'layanan/petugas/kariskarsu'],
        3 => ['Tugas Belajar', 'riwayat_tb', 'tb', 'layanan/tb', 'layanan/petugas/tb'],
        4 => ['Izin Belajar', 'riwayat_ib', 'ib', 'layanan/ib', 'layanan/petugas/ib'],
        5 => ['Peninjauan Masa Kerja', 'riwayat_pmk_req', 'pmk', 'layanan/pmk', 'layanan/petugas/pmk'],
    ];

    private const STATUS_MASTER = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $created = [];

        try {
            foreach (self::TABLES as $table) {
                $this->exec($this->createStatement($table), [], "CREATE TABLE {$table}");
                $created[] = $table;
            }

            $this->seedJenisCuti();
            $this->seedJenisLayanan();
            $this->exec(
                "INSERT INTO {$this->t('jenis_pa')} (`id_jenis_pa`, `jenis_pa`, `order`, `status`, `created_at`) VALUES (1, 'Cuti', 1, 1, UTC_TIMESTAMP())",
                [],
                'seed jenis_pa',
            );
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $table) {
                try {
                    $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa tabel dibersihkan manual (dokumen DBV-022 Bagian 9).
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}", [], "DROP TABLE {$table}");
        }
    }

    private function createStatement(string $table): string
    {
        return match ($table) {
            'jenis_cuti' => "CREATE TABLE {$this->t('jenis_cuti')} (
                `id_jenis_cuti` INT NOT NULL AUTO_INCREMENT,
                `nama_jenis_cuti` VARCHAR(105) NOT NULL,
                `order` INT NOT NULL DEFAULT 0,
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_MASTER . "',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT 0,
                PRIMARY KEY (`id_jenis_cuti`),
                UNIQUE KEY `uq_jenis_cuti_nama` (`nama_jenis_cuti`),
                CONSTRAINT `chk_jenis_cuti_status` CHECK (`status` IN (1, 2, 10))
            ) " . self::TABLE_OPTIONS,
            'jenis_layanan' => "CREATE TABLE {$this->t('jenis_layanan')} (
                `id_jenis_layanan` INT NOT NULL AUTO_INCREMENT,
                `jenis_layanan` VARCHAR(50) NOT NULL,
                `table` VARCHAR(50) NOT NULL,
                `module_name` VARCHAR(50) NOT NULL,
                `url_process` VARCHAR(100) NOT NULL,
                `url_pic` VARCHAR(100) NULL DEFAULT NULL,
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_MASTER . "',
                PRIMARY KEY (`id_jenis_layanan`),
                CONSTRAINT `chk_jenis_layanan_status` CHECK (`status` IN (1, 2, 10))
            ) " . self::TABLE_OPTIONS,
            'jenis_pa' => "CREATE TABLE {$this->t('jenis_pa')} (
                `id_jenis_pa` TINYINT NOT NULL AUTO_INCREMENT COMMENT 'Jenis Layanan Petugas Approval',
                `jenis_pa` VARCHAR(100) NOT NULL,
                `order` TINYINT NOT NULL DEFAULT 1,
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_MASTER . "',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_jenis_pa`),
                CONSTRAINT `chk_jenis_pa_status` CHECK (`status` IN (1, 2, 10))
            ) " . self::TABLE_OPTIONS,
            'question_category' => "CREATE TABLE {$this->t('question_category')} (
                `id_question_category` INT NOT NULL AUTO_INCREMENT,
                `question_category` VARCHAR(250) NOT NULL,
                `status` INT NOT NULL DEFAULT 1 COMMENT '0: Inactive, 1: Active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_question_category`),
                KEY `question_category` (`question_category`),
                KEY `status` (`status`),
                CONSTRAINT `chk_question_category_status` CHECK (`status` IN (0, 1))
            ) " . self::TABLE_OPTIONS,
            default => throw new RuntimeException("DBV-022: tabel {$table} tidak dikenal."),
        };
    }

    private function seedJenisCuti(): void
    {
        $values = implode(', ', array_fill(0, count(self::JENIS_CUTI), '(?, ?, ?, 1, UTC_TIMESTAMP())'));
        $binds  = [];

        foreach (self::JENIS_CUTI as $id => $nama) {
            array_push($binds, $id, $nama, $id);
        }

        $this->exec(
            "INSERT INTO {$this->t('jenis_cuti')} (`id_jenis_cuti`, `nama_jenis_cuti`, `order`, `status`, `created_at`) VALUES {$values}",
            $binds,
            'seed jenis_cuti',
        );
    }

    private function seedJenisLayanan(): void
    {
        $values = implode(', ', array_fill(0, count(self::JENIS_LAYANAN), '(?, ?, ?, ?, ?, ?, 1)'));
        $binds  = [];

        foreach (self::JENIS_LAYANAN as $id => [$nama, $tabel, $modul, $urlProcess, $urlPic]) {
            array_push($binds, $id, $nama, $tabel, $modul, $urlProcess, $urlPic);
        }

        $this->exec(
            "INSERT INTO {$this->t('jenis_layanan')} (`id_jenis_layanan`, `jenis_layanan`, `table`, `module_name`, `url_process`, `url_pic`, `status`) VALUES {$values}",
            $binds,
            'seed jenis_layanan',
        );
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-022: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    /**
     * @param list<int|string|null> $binds
     */
    private function exec(string $sql, array $binds, string $what): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL/seed gagal diam-diam.
        $result = $binds === [] ? $this->db->query($sql) : $this->db->query($sql, $binds);

        if ($result === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-022: {$what} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
