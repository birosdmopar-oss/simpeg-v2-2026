<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: riwayat KP & KGB.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `riwayat_kp` — D1:5338-5381; 35 kolom; 3 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_kgb` — D1:5277-5307; 22 kolom; 2 FK di CREATE; 1 CHECK [V2].
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
class CreateRiwayatKpKgb extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['riwayat_kp', 'riwayat_kgb'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'riwayat_kp' => <<<'SQL'
            CREATE TABLE {{riwayat_kp}} (
              `id_riwayat_kp` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_jenis_kp` tinyint DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `tmtsk` date NOT NULL,
              `tgl_sk` date DEFAULT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_pertek_bkn` date DEFAULT NULL,
              `no_pertek_bkn` varchar(100) DEFAULT NULL,
              `jumlah_kredit_utama` double DEFAULT NULL,
              `jumlah_kredit_tambahan` double DEFAULT NULL,
              `jenis_kp` varchar(100) DEFAULT NULL,
              `gol` varchar(10) DEFAULT NULL,
              `ruang` varchar(10) DEFAULT NULL,
              `gol_ruang` varchar(10) DEFAULT NULL,
              `pangkat` varchar(50) DEFAULT NULL,
              `mker_th` tinyint DEFAULT NULL,
              `mker_bl` tinyint DEFAULT NULL,
              `gaji_pokok` double DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0',
              `siasn_error` text,
              `siasn_lu` datetime DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_kp`),
              KEY `fk_id_jenis_kp_rwy_kp_to_jenis_kp` (`id_jenis_kp`),
              KEY `fk_id_pangkat_rwy_kp_to_pangkat` (`id_pangkat`),
              KEY `fk_nip_rwykp_to_pegawai` (`nip`),
              CONSTRAINT `fk_id_jenis_kp_rwy_kp_to_jenis_kp` FOREIGN KEY (`id_jenis_kp`) REFERENCES {{jenis_kp}} (`id_jenis_kp`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_pangkat_rwy_kp_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rwykp_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_kp_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_kgb' => <<<'SQL'
            CREATE TABLE {{riwayat_kgb}} (
              `id_riwayat_kgb` int NOT NULL AUTO_INCREMENT,
              `id_gol_pppk` tinyint DEFAULT NULL,
              `nip` varchar(30) NOT NULL,
              `tmtsk` date NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_sk` date NOT NULL,
              `gol_pppk` varchar(10) DEFAULT NULL,
              `mker_gol_th` tinyint DEFAULT NULL,
              `gaji_pokok` int DEFAULT NULL,
              `jym` varchar(255) DEFAULT NULL COMMENT 'Jabatan Yang Menandatangani',
              `keterangan` tinytext,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_kgb`),
              KEY `status` (`status`),
              KEY `show_notif_notif_date` (`show_notif`,`notif_date`),
              KEY `fk_nip_rwykgb_to_pegawai` (`nip`),
              KEY `riwayat_kgb_ibfk_01` (`id_gol_pppk`),
              CONSTRAINT `fk_nip_rwykgb_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `riwayat_kgb_ibfk_01` FOREIGN KEY (`id_gol_pppk`) REFERENCES {{gol_pppk}} (`id_gol_pppk`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_kgb_status` CHECK (`status` IN (0, 1, 2, 3, 10))
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatKpKgb gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
