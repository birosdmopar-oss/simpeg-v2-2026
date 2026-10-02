<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: riwayat pendidikan, diklat, seminar.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `riwayat_pendidikan` — D1:6119-6164; 34 kolom; 4 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_diklat` — D1:4714-4755; 33 kolom; 2 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_seminar` — D1:6264-6292; 24 kolom; 1 FK di CREATE; 1 CHECK [V2].
 *
 * Deviasi [V2] (rinci per tabel di dokumen Bagian 3):
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE atau SET NULL/CASCADE). Ganti NIP lewat B-06.
 *   - CHECK domain status/flag (D1 tanpa CHECK); nilai legacy di luar domain dinormalkan saat impor (Bagian 6).
 *   - `COLLATE utf8mb4_unicode_ci` per kolom di D1 sama dengan collation tabel, jadi diwarisi dari tabel; nilai
 *     AUTO_INCREMENT awal dan ROW_FORMAT=DYNAMIC (default InnoDB) tidak ditulis.
 *   - Trigger legacy pada tabel ini tidak dibawa; padanannya aturan aplikasi v2 (dokumen Bagian 5).
 *   - Tipe INT (D1 TINYINT) untuk `riwayat_pendidikan.id_jenjang_pendidikan`: mengikuti PK master di `main`
 *     (`jenjang_pendidikan`/`jenis_hukdis`/`tingkat_hukdis`/`tanda_jasa` INT; FK mensyaratkan tipe sama).
 *     Penyelarasan master dengan D1 diajukan di dokumen Bagian 8.
 *
 * Kolom audit diisi aplikasi (waktu UTC, `*_by` = id_pengguna tanpa FK); default DB hanya cadangan. Tanpa baris seed.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatPendidikanDiklatSeminar extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['riwayat_pendidikan', 'riwayat_diklat', 'riwayat_seminar'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'riwayat_pendidikan' => <<<'SQL'
            CREATE TABLE {{riwayat_pendidikan}} (
              `id_riwayat_pendidikan` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_jenjang_pendidikan` int DEFAULT NULL,
              `id_bidang_pendidikan` tinyint DEFAULT NULL,
              `id_jurusan_pendidikan` int DEFAULT NULL,
              `tgl_lulus` date NOT NULL,
              `no_ijazah` varchar(100) DEFAULT NULL,
              `no_sk_penc_glr` varchar(100) DEFAULT NULL,
              `institusi_pendidikan` varchar(255) DEFAULT NULL,
              `jenjang_pendidikan_singkat` varchar(15) DEFAULT NULL,
              `bidang_pendidikan` varchar(100) DEFAULT NULL,
              `bidang_pendidikan_lain` varchar(100) DEFAULT NULL,
              `jurusan_pendidikan` varchar(255) DEFAULT NULL,
              `jurusan_pendidikan_lain` varchar(255) DEFAULT NULL,
              `nem` decimal(5,2) DEFAULT NULL,
              `ipk` decimal(3,2) DEFAULT NULL,
              `keterangan` tinytext,
              `glr_awal` varchar(50) DEFAULT NULL,
              `glr_akhir` varchar(50) DEFAULT NULL,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0',
              `siasn_error` text,
              `siasn_lu` datetime DEFAULT NULL,
              `status` int DEFAULT '0' COMMENT '0: Waiting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` int DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` int DEFAULT '0',
              `show_ua_deputi` int DEFAULT '0',
              `show_ua_biro` int DEFAULT '0',
              PRIMARY KEY (`id_riwayat_pendidikan`),
              KEY `status` (`status`),
              KEY `fk_id_jenjang_pendidikan_rpend_to_jpend` (`id_jenjang_pendidikan`),
              KEY `fk_id_bidang_pendidikan_rpend_to_bpend` (`id_bidang_pendidikan`),
              KEY `fk_id_jurusan_pendidikan_rpend_jupend` (`id_jurusan_pendidikan`),
              KEY `fk_nip_rwypendidikan_to_pegawai` (`nip`),
              CONSTRAINT `fk_id_bidang_pendidikan_rpend_to_bpend` FOREIGN KEY (`id_bidang_pendidikan`) REFERENCES {{bidang_pendidikan}} (`id_bidang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_jenjang_pendidikan_rpend_to_jpend` FOREIGN KEY (`id_jenjang_pendidikan`) REFERENCES {{jenjang_pendidikan}} (`id_jenjang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_jurusan_pendidikan_rpend_jupend` FOREIGN KEY (`id_jurusan_pendidikan`) REFERENCES {{jurusan_pendidikan}} (`id_jurusan_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rwypendidikan_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_pendidikan_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_diklat' => <<<'SQL'
            CREATE TABLE {{riwayat_diklat}} (
              `id_riwayat_diklat` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_diklat` tinyint DEFAULT NULL,
              `id_sub_group_jabatan` int DEFAULT NULL,
              `id_rumpun_sertifikasi` int DEFAULT NULL,
              `id_lembaga_sertifikasi` int DEFAULT NULL,
              `jenis_diklat` tinyint(1) DEFAULT NULL COMMENT '1: Struktural \r\n2: Teknis \r\n3: Fungsional \r\n4: Prajabatan \r\n5: Sertifikasi',
              `rumpun_sertifikasi` varchar(255) DEFAULT NULL,
              `tgl_sertifikat` date DEFAULT NULL,
              `mb_sertifikat_awal` date DEFAULT NULL,
              `mb_sertifikat_akhir` date DEFAULT NULL,
              `no_sertifikat` varchar(255) DEFAULT NULL,
              `jumlah_jp` int DEFAULT NULL,
              `nama_diklat` varchar(255) DEFAULT NULL,
              `nama_diklat_lain` varchar(255) DEFAULT NULL,
              `sub_group_jabatan` varchar(255) DEFAULT NULL,
              `deskripsi` tinytext,
              `instansi_penyelenggara` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0' COMMENT '0: Belum Diupload, 1: Sudah Diupload',
              `siasn_error` tinytext,
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
              PRIMARY KEY (`id_riwayat_diklat`),
              KEY `fk_id_diklat_rdiklat_to_diklat` (`id_diklat`),
              KEY `show_notif_notif_date` (`show_notif`,`notif_date`),
              KEY `status` (`status`),
              KEY `fk_nip_rwydiklat_to_pegawai` (`nip`),
              CONSTRAINT `fk_id_diklat_rdiklat_to_diklat` FOREIGN KEY (`id_diklat`) REFERENCES {{diklat}} (`id_diklat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rwydiklat_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_diklat_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_seminar' => <<<'SQL'
            CREATE TABLE {{riwayat_seminar}} (
              `id_riwayat_seminar` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `jenis_seminar` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Seminar, 2: Kursus',
              `bidang_seminar` varchar(100) DEFAULT NULL,
              `nama_seminar` varchar(255) NOT NULL,
              `tgl_sertifikat` date DEFAULT NULL,
              `no_sertifikat` varchar(100) DEFAULT NULL,
              `jumlah_jp` int DEFAULT NULL,
              `instansi_penyelenggara` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0' COMMENT '0: Belum Diupload, 1: Sudah Diupload, 2: Error Saat Upload',
              `siasn_error` tinytext,
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
              PRIMARY KEY (`id_riwayat_seminar`),
              KEY `fk_nip_rwyseminar_to_pegawai` (`nip`),
              CONSTRAINT `fk_nip_rwyseminar_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_seminar_status` CHECK (`status` IN (0, 1, 2, 3, 10))
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatPendidikanDiklatSeminar gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
