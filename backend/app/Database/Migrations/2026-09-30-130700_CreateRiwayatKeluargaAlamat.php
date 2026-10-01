<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: keluarga, anak, alamat.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `riwayat_keluarga` — D1:5247-5272; 19 kolom; 1 FK di CREATE; 1 CHECK [V2].
 *   - `detail_anak` — D1:373-386; 9 kolom; 1 FK di CREATE.
 *   - `riwayat_alamat` — D1:4300-4350; 35 kolom; 5 FK di CREATE; 1 CHECK [V2].
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
class CreateRiwayatKeluargaAlamat extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['riwayat_keluarga', 'detail_anak', 'riwayat_alamat'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'riwayat_keluarga' => <<<'SQL'
            CREATE TABLE {{riwayat_keluarga}} (
              `id_riwayat_keluarga` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `urutan_perkawinan` tinyint NOT NULL DEFAULT '1',
              `tgl_perkawinan` date NOT NULL,
              `kota_perkawinan` varchar(255) DEFAULT NULL,
              `nama_pasangan` varchar(255) NOT NULL,
              `jumlah_anak` tinyint NOT NULL DEFAULT '0',
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
              PRIMARY KEY (`id_riwayat_keluarga`),
              KEY `status` (`status`),
              KEY `show_notif_notif_date` (`show_notif`,`notif_date`),
              KEY `fk_nip_rwykeluarga_to_pegawai` (`nip`),
              CONSTRAINT `fk_nip_rwykeluarga_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_keluarga_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'detail_anak' => <<<'SQL'
            CREATE TABLE {{detail_anak}} (
              `id_detail_anak` int NOT NULL AUTO_INCREMENT,
              `id_riwayat_keluarga` int NOT NULL,
              `urutan` tinyint NOT NULL DEFAULT '1',
              `tgl_lahir` date NOT NULL,
              `nama` varchar(255) NOT NULL,
              `tempat_lahir` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id_detail_anak`),
              KEY `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` (`id_riwayat_keluarga`),
              CONSTRAINT `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` FOREIGN KEY (`id_riwayat_keluarga`) REFERENCES {{riwayat_keluarga}} (`id_riwayat_keluarga`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_alamat' => <<<'SQL'
            CREATE TABLE {{riwayat_alamat}} (
              `id_riwayat_alamat` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_provinsi` char(2) DEFAULT NULL,
              `id_kabupaten_kota` char(4) DEFAULT NULL,
              `id_kecamatan` char(7) DEFAULT NULL,
              `id_kelurahan` char(10) DEFAULT NULL,
              `alamat_utama` tinyint(1) NOT NULL DEFAULT '2' COMMENT '1: Ya, 2: Tidak',
              `jenis_alamat` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Alamat KTP, 2: Alamat Domisili, 3: Kantor',
              `provinsi` varchar(255) DEFAULT NULL,
              `provinsi_lain` varchar(255) DEFAULT NULL,
              `kabupaten_kota` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lain` varchar(255) DEFAULT NULL,
              `kecamatan` varchar(255) DEFAULT NULL,
              `kecamatan_lain` varchar(255) DEFAULT NULL,
              `kelurahan` varchar(255) DEFAULT NULL,
              `kelurahan_lain` varchar(255) DEFAULT NULL,
              `kd_pos` varchar(50) DEFAULT NULL,
              `alamat` tinytext,
              `keterangan` tinytext,
              `KdPos` varchar(10) DEFAULT NULL,
              `nama_kantor` varchar(255) DEFAULT NULL,
              `lantai` varchar(50) DEFAULT NULL,
              `telp` varchar(50) DEFAULT NULL,
              `faks` varchar(50) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1: Show, 3: Viewed',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_alamat`) USING BTREE,
              KEY `show_notif` (`show_notif`,`notif_date`) USING BTREE,
              KEY `status` (`status`) USING BTREE,
              KEY `fk_id_provinsi_riwalamat_provinsi` (`id_provinsi`),
              KEY `fk_id_kabupaten_kota_riwalamat_kabkota` (`id_kabupaten_kota`),
              KEY `fk_id_kecamatan_riwalamat_kecamatan` (`id_kecamatan`),
              KEY `fk_id_kelurahan_riwalamat_kelurahan` (`id_kelurahan`),
              KEY `fk_nip_rwyalamat_to_pegawai` (`nip`),
              KEY `alamat_utama_created_at` (`alamat_utama`,`created_at`),
              CONSTRAINT `fk_id_kabupaten_kota_riwalamat_kabkota` FOREIGN KEY (`id_kabupaten_kota`) REFERENCES {{kabupaten_kota}} (`id_kabupaten_kota`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_kecamatan_riwalamat_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES {{kecamatan}} (`id_kecamatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_kelurahan_riwalamat_kelurahan` FOREIGN KEY (`id_kelurahan`) REFERENCES {{kelurahan}} (`id_kelurahan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_provinsi_riwalamat_provinsi` FOREIGN KEY (`id_provinsi`) REFERENCES {{provinsi}} (`id_provinsi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rwyalamat_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_alamat_status` CHECK (`status` IN (0, 1, 2, 10))
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatKeluargaAlamat gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
