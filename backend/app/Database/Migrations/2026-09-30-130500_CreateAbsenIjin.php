<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: konket `absen_ijin`.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `absen_ijin` — D1:33-69; 28 kolom; 1 FK di CREATE; 3 CHECK [V2].
 *
 * Deviasi [V2] (rinci per tabel di dokumen Bagian 3):
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE atau SET NULL/CASCADE). Ganti NIP lewat B-06.
 *   - CHECK domain status/flag (D1 tanpa CHECK); nilai legacy di luar domain dinormalkan saat impor (Bagian 6).
 *   - `COLLATE utf8mb4_unicode_ci` per kolom di D1 sama dengan collation tabel, jadi diwarisi dari tabel; nilai
 *     AUTO_INCREMENT awal dan ROW_FORMAT=DYNAMIC (default InnoDB) tidak ditulis.
 *   - Trigger legacy pada tabel ini tidak dibawa; padanannya aturan aplikasi v2 (dokumen Bagian 5).
 *
 * Kolom audit diisi aplikasi (waktu UTC, `*_by` = id_pengguna tanpa FK); default DB hanya cadangan. Tanpa baris seed.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateAbsenIjin extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['absen_ijin'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'absen_ijin' => <<<'SQL'
            CREATE TABLE {{absen_ijin}} (
              `id` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `date_start` datetime NOT NULL,
              `date_end` datetime NOT NULL,
              `kategori` int NOT NULL,
              `jenis_konket` varchar(255) DEFAULT NULL,
              `jenis_dinas` tinyint(1) DEFAULT NULL COMMENT '0: Dinas Pribadi, 1: Dinas Gabungan',
              `affect_tukin` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Yes, 2: No',
              `id_parent` int DEFAULT NULL,
              `alasan` mediumtext NOT NULL,
              `file_bukti` varchar(255) DEFAULT NULL,
              `file_bukti_2` varchar(255) DEFAULT NULL,
              `file_bukti_3` varchar(255) DEFAULT NULL,
              `file_bukti_4` varchar(255) DEFAULT NULL,
              `file_bukti_5` varchar(255) DEFAULT NULL,
              `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `last_updated` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `status` char(2) NOT NULL DEFAULT 'W' COMMENT 'W: Waiting, V: Approved, X: Rejected, 10: Deleted',
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` mediumtext,
              `show_notif` int DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` int DEFAULT '0',
              `show_ua_deputi` int DEFAULT '0',
              `show_ua_biro` int DEFAULT '0',
              PRIMARY KEY (`id`),
              KEY `nip` (`nip`),
              KEY `date_start` (`date_start`,`date_end`),
              KEY `show_notif` (`show_notif`,`notif_date`),
              KEY `status` (`status`),
              KEY `affect_tukin` (`affect_tukin`),
              CONSTRAINT `fk_nip_abijin_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_absen_ijin_status` CHECK (`status` IN ('W', 'V', 'X', '10')),
              CONSTRAINT `chk_absen_ijin_affect_tukin` CHECK (`affect_tukin` IN (1, 2)),
              CONSTRAINT `chk_absen_ijin_jenis_dinas` CHECK (`jenis_dinas` IN (0, 1))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
    ];

    public function up(): void
    {
        $created = [];

        try {
            foreach (self::TABLES as $table) {
                $this->exec($this->ddl($table));
                $created[] = $table;
            }
        } catch (Throwable $e) {
            $this->dropCreated($created);

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}");
        }
    }

    private function ddl(string $table): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z_]+)\}\}/',
            fn (array $m): string => $this->t($m[1]),
            self::DDL[$table],
        );
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan
     * drop tidak menutupi error asli (yang dilempar ulang pemanggil); sisa tabel dibersihkan manual (dokumen
     * Bagian 9.3).
     *
     * @param list<string> $created
     */
    private function dropCreated(array $created): void
    {
        foreach (array_reverse($created) as $table) {
            try {
                $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
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

            throw new RuntimeException('DBV-013: DDL CreateAbsenIjin gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
