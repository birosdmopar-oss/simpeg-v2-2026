<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-012 — B-01: `pegawai`, `pegawai_hist`, `pegawai_foto`.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-012.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `pegawai` — D1:2497-2550; 40 kolom; 5 FK di CREATE; 3 CHECK [V2].
 *   - `pegawai_hist` — D1:2938-3003; 48 kolom; 6 FK di CREATE; 3 CHECK [V2].
 *   - `pegawai_foto` — D1:2924-2933; 4 kolom; 1 FK di CREATE.
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
class CreatePegawai extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['pegawai', 'pegawai_hist', 'pegawai_foto'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'pegawai' => <<<'SQL'
            CREATE TABLE {{pegawai}} (
              `nip` varchar(30) NOT NULL,
              `id_provinsi_lahir` char(2) DEFAULT NULL,
              `id_kabupaten_kota_lahir` char(4) DEFAULT NULL,
              `id_agama` tinyint DEFAULT NULL,
              `id_jenis_pegawai` tinyint DEFAULT NULL,
              `id_jenis_status` tinyint DEFAULT NULL,
              `nip_lama` varchar(18) DEFAULT NULL,
              `nama` varchar(100) NOT NULL,
              `glr_awal` varchar(50) DEFAULT NULL,
              `glr_akhir` varchar(50) DEFAULT NULL,
              `tgl_lahir` date NOT NULL,
              `provinsi_lahir` varchar(255) DEFAULT NULL,
              `provinsi_lahir_lain` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lahir` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lahir_lain` varchar(255) DEFAULT NULL,
              `agama` varchar(30) DEFAULT NULL,
              `jenis_kelamin` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Laki-laki, 2: Perempuan',
              `npwp` varchar(20) DEFAULT NULL,
              `nik` varchar(20) DEFAULT NULL,
              `bpjs_kes` varchar(50) DEFAULT NULL,
              `bpjs_ket` varchar(50) DEFAULT NULL,
              `no_taspen` varchar(50) DEFAULT NULL,
              `no_hp` varchar(20) DEFAULT NULL,
              `jenis_kerabat` varchar(100) DEFAULT NULL,
              `no_telp_kerabat` varchar(20) DEFAULT NULL,
              `status_pernikahan` tinyint(1) DEFAULT NULL COMMENT '1: Menikah, 2: Tidak Menikah',
              `jenis_pegawai` varchar(255) DEFAULT NULL,
              `jenis_status` varchar(100) DEFAULT NULL,
              `tmt_status` date DEFAULT NULL,
              `foto` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Deleted',
              `flag_update` tinyint NOT NULL DEFAULT '0' COMMENT '0: No Update, 1: Update Exist, 2: Update Rejected, 3: Update Approved',
              `id_pns_siasn` varchar(100) DEFAULT NULL COMMENT 'ID PNS from SIASN',
              `siasn_flag` tinyint DEFAULT '0',
              `siasn_lu` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `deleted_at` datetime DEFAULT NULL,
              PRIMARY KEY (`nip`),
              KEY `fk_id_provinsi_lahir_peg_to_prov` (`id_provinsi_lahir`),
              KEY `fk_id_kabupaten_kota_lahir_peg_to_kab_kota` (`id_kabupaten_kota_lahir`),
              KEY `fk_id_agama_peg_to_agama` (`id_agama`),
              KEY `fk_id_jenis_pegawai_peg_to_jenis_pegawai` (`id_jenis_pegawai`),
              KEY `fk_id_jenis_status_peg_to_jenis_status` (`id_jenis_status`),
              KEY `status` (`status`),
              CONSTRAINT `fk_id_agama_peg_to_agama` FOREIGN KEY (`id_agama`) REFERENCES {{agama}} (`id_agama`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_jenis_pegawai_peg_to_jenis_pegawai` FOREIGN KEY (`id_jenis_pegawai`) REFERENCES {{jenis_pegawai}} (`id_jenis_pegawai`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_jenis_status_peg_to_jenis_status` FOREIGN KEY (`id_jenis_status`) REFERENCES {{jenis_status}} (`id_jenis_status`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_kabupaten_kota_lahir_peg_to_kab_kota` FOREIGN KEY (`id_kabupaten_kota_lahir`) REFERENCES {{kabupaten_kota}} (`id_kabupaten_kota`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_provinsi_lahir_peg_to_prov` FOREIGN KEY (`id_provinsi_lahir`) REFERENCES {{provinsi}} (`id_provinsi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_pegawai_status` CHECK (`status` IN (1, 2, 10)),
              CONSTRAINT `chk_pegawai_flag_update` CHECK (`flag_update` IN (0, 1, 2, 3)),
              CONSTRAINT `chk_pegawai_jenis_kelamin` CHECK (`jenis_kelamin` IN (1, 2))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_hist' => <<<'SQL'
            CREATE TABLE {{pegawai_hist}} (
              `id_pegawai_hist` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_provinsi_lahir` char(2) DEFAULT NULL,
              `id_kabupaten_kota_lahir` char(4) DEFAULT NULL,
              `id_agama` tinyint DEFAULT NULL,
              `id_jenis_pegawai` tinyint DEFAULT NULL,
              `id_jenis_status` tinyint DEFAULT NULL,
              `nip_lama` varchar(18) DEFAULT NULL,
              `nama` varchar(100) NOT NULL,
              `glr_awal` varchar(12) DEFAULT NULL,
              `glr_akhir` varchar(24) DEFAULT NULL,
              `tgl_lahir` date NOT NULL,
              `provinsi_lahir` varchar(255) DEFAULT NULL,
              `provinsi_lahir_lain` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lahir` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lahir_lain` varchar(255) DEFAULT NULL,
              `agama` varchar(30) DEFAULT NULL,
              `jenis_kelamin` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Laki-laki, 2: Perempuan',
              `npwp` varchar(20) DEFAULT NULL,
              `nik` varchar(20) DEFAULT NULL,
              `bpjs_kes` varchar(50) DEFAULT NULL,
              `bpjs_ket` varchar(50) DEFAULT NULL,
              `no_taspen` varchar(50) DEFAULT NULL,
              `no_hp` varchar(20) DEFAULT NULL,
              `jenis_kerabat` varchar(100) DEFAULT NULL,
              `no_telp_kerabat` varchar(20) DEFAULT NULL,
              `status_pernikahan` tinyint(1) DEFAULT NULL COMMENT '1: Menikah, 2: Tidak Menikah',
              `jenis_pegawai` varchar(255) DEFAULT NULL,
              `jenis_status` varchar(100) DEFAULT NULL,
              `tmt_status` date DEFAULT NULL,
              `foto` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `email` varchar(256) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Deleted',
              `flag_update` tinyint NOT NULL DEFAULT '0' COMMENT '0: No Update, 1: Update Exist, 2: Update Rejected, 3: Update Approved',
              `id_pns_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint DEFAULT '0',
              `siasn_lu` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` tinyint NOT NULL DEFAULT '0' COMMENT '0: Not Show, 1: Show, 3: Viewed',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_pegawai_hist`),
              KEY `fk_peg_hist_ibfk_01` (`nip`),
              KEY `fk_peg_hist_ibfk_02` (`id_provinsi_lahir`),
              KEY `fk_peg_hist_ibfk_03` (`id_kabupaten_kota_lahir`),
              KEY `fk_peg_hist_ibfk_04` (`id_agama`),
              KEY `fk_peg_hist_ibfk_05` (`id_jenis_pegawai`),
              KEY `fk_peg_hist_ibfk_06` (`id_jenis_status`),
              KEY `flag_update` (`flag_update`),
              KEY `status` (`status`),
              KEY `show_notif` (`show_notif`),
              CONSTRAINT `fk_peg_hist_ibfk_01` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_peg_hist_ibfk_02` FOREIGN KEY (`id_provinsi_lahir`) REFERENCES {{provinsi}} (`id_provinsi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_peg_hist_ibfk_03` FOREIGN KEY (`id_kabupaten_kota_lahir`) REFERENCES {{kabupaten_kota}} (`id_kabupaten_kota`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_peg_hist_ibfk_04` FOREIGN KEY (`id_agama`) REFERENCES {{agama}} (`id_agama`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_peg_hist_ibfk_05` FOREIGN KEY (`id_jenis_pegawai`) REFERENCES {{jenis_pegawai}} (`id_jenis_pegawai`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_peg_hist_ibfk_06` FOREIGN KEY (`id_jenis_status`) REFERENCES {{jenis_status}} (`id_jenis_status`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_pegawai_hist_status` CHECK (`status` IN (1, 2, 10)),
              CONSTRAINT `chk_pegawai_hist_flag_update` CHECK (`flag_update` IN (0, 1, 2, 3)),
              CONSTRAINT `chk_pegawai_hist_jenis_kelamin` CHECK (`jenis_kelamin` IN (1, 2))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_foto' => <<<'SQL'
            CREATE TABLE {{pegawai_foto}} (
              `id_pegawai_foto` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `foto` varchar(255) NOT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id_pegawai_foto`),
              KEY `fk_nip_pegfoto_to_peg` (`nip`),
              KEY `foto` (`foto`),
              CONSTRAINT `fk_nip_pegfoto_to_peg` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
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
            throw new RuntimeException('DBV-012: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-012: DDL CreatePegawai gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
