<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: karpeg, karis/karsu, tanda jasa, organisasi.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `riwayat_karpeg` — D1:5122-5149; 23 kolom; 1 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_kariskarsu` — D1:5061-5088; 23 kolom; 1 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_tanda_jasa` — D1:6507-6539; 24 kolom; 2 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_organisasi` — D1:6092-6114; 18 kolom; 1 FK di CREATE; 1 CHECK [V2].
 *
 * Deviasi [V2] (rinci per tabel di dokumen Bagian 3):
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE atau SET NULL/CASCADE). Ganti NIP lewat B-06.
 *   - CHECK domain status/flag (D1 tanpa CHECK); nilai legacy di luar domain dinormalkan saat impor (Bagian 6).
 *   - `COLLATE utf8mb4_unicode_ci` per kolom di D1 sama dengan collation tabel, jadi diwarisi dari tabel; nilai
 *     AUTO_INCREMENT awal dan ROW_FORMAT=DYNAMIC (default InnoDB) tidak ditulis.
 *   - Trigger legacy pada tabel ini tidak dibawa; padanannya aturan aplikasi v2 (dokumen Bagian 5).
 *   - Tipe INT (D1 TINYINT) untuk `riwayat_tanda_jasa.id_tanda_jasa`: mengikuti PK master di `main`
 *     (`jenjang_pendidikan`/`jenis_hukdis`/`tingkat_hukdis`/`tanda_jasa` INT; FK mensyaratkan tipe sama).
 *     Penyelarasan master dengan D1 diajukan di dokumen Bagian 8.
 *
 * Kolom audit diisi aplikasi (waktu UTC, `*_by` = id_pengguna tanpa FK); default DB hanya cadangan. Tanpa baris seed.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatKartuTjOrganisasi extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['riwayat_karpeg', 'riwayat_kariskarsu', 'riwayat_tanda_jasa', 'riwayat_organisasi'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'riwayat_karpeg' => <<<'SQL'
            CREATE TABLE {{riwayat_karpeg}} (
              `id_riwayat_karpeg` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `jenis_permohonan` varchar(50) NOT NULL DEFAULT 'Permohonan Pertama' COMMENT 'Permohonan Pertama | Karena Kehilangan',
              `file_surat_hilang` varchar(225) DEFAULT NULL COMMENT 'SURAT HILANG',
              `file_1` varchar(225) NOT NULL COMMENT 'SK CPNS',
              `file_2` varchar(225) NOT NULL COMMENT 'SK PNS',
              `file_3` varchar(225) NOT NULL COMMENT 'SPMT',
              `file_4` varchar(225) NOT NULL COMMENT 'SERTIFIKAT PRAJABATAN',
              `file_5` varchar(225) NOT NULL COMMENT 'PAS FOTO',
              `file_tanda_terima` varchar(225) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting, 1: Disetujui, 2: Ditolak',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` mediumtext,
              `rated` tinyint NOT NULL DEFAULT '2' COMMENT '0: Ignore, 1: Sudah, 2: Belum',
              `show_notif` tinyint NOT NULL DEFAULT '0' COMMENT '1: Show, 3: Viewed',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint NOT NULL DEFAULT '0',
              `show_pl` tinyint NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_karpeg`),
              KEY `nip_status_rated_notif_date` (`nip`,`status`,`rated`,`notif_date`),
              CONSTRAINT `fk_nip_rkarpeg_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_karpeg_status` CHECK (`status` IN (0, 1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_kariskarsu' => <<<'SQL'
            CREATE TABLE {{riwayat_kariskarsu}} (
              `id_riwayat_kariskarsu` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `file_1` varchar(225) NOT NULL COMMENT 'SK CPNS',
              `file_2` varchar(225) NOT NULL COMMENT 'SK PNS',
              `file_3` varchar(225) NOT NULL COMMENT 'BUKU NIKAH',
              `file_4` varchar(225) NOT NULL COMMENT 'LAPORAN PERKAWINAN',
              `file_5` varchar(225) NOT NULL COMMENT 'PAS FOTO SUAMI / ISTRI 3X4',
              `file_surat_hilang` varchar(250) DEFAULT NULL COMMENT 'SURAT HILANG',
              `file_alasan` varchar(225) DEFAULT NULL COMMENT 'AKTA KEMATIAN / SURAT CERAI',
              `file_tanda_terima` varchar(225) DEFAULT NULL COMMENT 'TANDA TERIMA',
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Pengajuan, 1: Disetujui, 2: Ditolak',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` mediumtext,
              `rated` tinyint NOT NULL DEFAULT '2' COMMENT '0: Ignore, 1: Sudah, 2: Belum',
              `show_notif` tinyint NOT NULL DEFAULT '0' COMMENT '1: Show, 3: Viewed',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint NOT NULL DEFAULT '0',
              `show_pl` tinyint NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_kariskarsu`),
              KEY `nip_status_rated_notif_date` (`nip`,`status`,`rated`,`notif_date`),
              CONSTRAINT `fk_nip_rkariskarsu_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_kariskarsu_status` CHECK (`status` IN (0, 1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_tanda_jasa' => <<<'SQL'
            CREATE TABLE {{riwayat_tanda_jasa}} (
              `id_riwayat_tanda_jasa` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_tanda_jasa` int DEFAULT NULL,
              `tgl_sertifikat` date NOT NULL,
              `no_sertifikat` varchar(100) DEFAULT NULL,
              `tanda_jasa` varchar(255) DEFAULT NULL,
              `tanda_jasa_lain` varchar(255) DEFAULT NULL,
              `negara` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0' COMMENT '0: Belum Diupload, 1: Sudah Diupload',
              `siasn_error` tinytext,
              `siasn_lu` datetime DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_tanda_jasa`),
              KEY `fk_id_tanda_jasa_rtj_to_tj` (`id_tanda_jasa`),
              KEY `status` (`status`),
              KEY `show_notif_notif_date` (`show_notif`,`notif_date`),
              KEY `fk_nip_rwytj_to_pegawai` (`nip`),
              CONSTRAINT `fk_id_tanda_jasa_rtj_to_tj` FOREIGN KEY (`id_tanda_jasa`) REFERENCES {{tanda_jasa}} (`id_tanda_jasa`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rwytj_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_tanda_jasa_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_organisasi' => <<<'SQL'
            CREATE TABLE {{riwayat_organisasi}} (
              `id_riwayat_organisasi` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `nama_organisasi` varchar(255) DEFAULT NULL,
              `kedudukan` varchar(255) DEFAULT NULL,
              `tgl_mulai` date DEFAULT NULL,
              `tgl_akhir` date DEFAULT NULL,
              `keterangan` text,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` text,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_organisasi`),
              KEY `fk_nip_rwyorganisasi_to_pegawai` (`nip`),
              CONSTRAINT `fk_nip_rwyorganisasi_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_organisasi_status` CHECK (`status` IN (0, 1, 2, 3, 10))
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatKartuTjOrganisasi gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
