<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: SKP, SKP periodik, LKH.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `bkn_periode_ekinper` — D1:270-284; 11 kolom.
 *   - `riwayat_skp` — D1:6324-6366; 37 kolom; 1 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_skp_periodik` — D1:6408-6446; 35 kolom; 1 CHECK [V2].
 *   - `riwayat_lckh` — D1:5455-5495; 27 kolom; 2 FK di CREATE, 2 FK G-02 ditahan; 1 CHECK [V2].
 *
 * Deviasi [V2] (rinci per tabel di dokumen Bagian 3):
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE atau SET NULL/CASCADE). Ganti NIP lewat B-06.
 *   - CHECK domain status/flag (D1 tanpa CHECK); nilai legacy di luar domain dinormalkan saat impor (Bagian 6).
 *   - `COLLATE utf8mb4_unicode_ci` per kolom di D1 sama dengan collation tabel, jadi diwarisi dari tabel; nilai
 *     AUTO_INCREMENT awal dan ROW_FORMAT=DYNAMIC (default InnoDB) tidak ditulis.
 *   - Trigger legacy pada tabel ini tidak dibawa; padanannya aturan aplikasi v2 (dokumen Bagian 5).
 *
 * FK yang TIDAK dipasang di sini (index kolomnya sudah ada di DDL D1, jadi migration penyusul cukup
 * ADD CONSTRAINT):
 *   - ke tabel G-02 (`unit`/`satker`/`jabatan`/`group_jabatan`/`sub_group_jabatan`, PR DBV-008): migration terpisah
 *     yang ditahan sampai DBV-008 merge (dokumen Bagian 7);
 *
 * Kolom audit diisi aplikasi (waktu UTC, `*_by` = id_pengguna tanpa FK); default DB hanya cadangan. Tanpa baris seed.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatSkpLkh extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['bkn_periode_ekinper', 'riwayat_skp', 'riwayat_skp_periodik', 'riwayat_lckh'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'bkn_periode_ekinper' => <<<'SQL'
            CREATE TABLE {{bkn_periode_ekinper}} (
              `id` varchar(150) NOT NULL,
              `nama` varchar(150) NOT NULL,
              `tahun` year NOT NULL,
              `bulan` varchar(5) DEFAULT NULL,
              `periode_awal` varchar(10) DEFAULT NULL,
              `periode_akhir` varchar(10) DEFAULT NULL,
              `batas_pengisian` varchar(10) DEFAULT NULL,
              `jenis_periode` varchar(150) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '1',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `tahun_bulan_jenis_periode_status` (`tahun`,`bulan`,`jenis_periode`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_skp' => <<<'SQL'
            CREATE TABLE {{riwayat_skp}} (
              `id_riwayat_skp` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `tahun` year NOT NULL,
              `tgl_mulai` date DEFAULT NULL,
              `tgl_akhir` date DEFAULT NULL,
              `nip_penilai` varchar(18) DEFAULT NULL COMMENT 'Atasan Langsung, Cth: Staff Atasannya Es.4',
              `nama_penilai` varchar(150) DEFAULT NULL COMMENT 'Atasan Langsung, Cth: Staff Atasannya Es.4',
              `jabatan_penilai` varchar(150) DEFAULT NULL COMMENT 'Atasan Langsung, Cth: Staff Atasannya Es.4',
              `nip_atasan_penilai` varchar(18) DEFAULT NULL COMMENT 'Atasan Penilai, Cth: Atasan Langsung Es.4 -> Atasan Penilao Es.3',
              `nama_atasan_penilai` varchar(150) DEFAULT NULL COMMENT 'Atasan Penilai, Cth: Atasan Langsung Es.4 -> Atasan Penilao Es.3',
              `jabatan_atasan_penilai` varchar(150) DEFAULT NULL COMMENT 'Atasan Penilai, Cth: Atasan Langsung Es.4 -> Atasan Penilao Es.3',
              `nilai_skp` decimal(5,2) DEFAULT NULL,
              `nilai_skp_60_persen` decimal(5,2) DEFAULT NULL COMMENT 'nilai skp x 60%',
              `rating_skp` tinyint DEFAULT NULL,
              `nilai_perilaku` decimal(5,2) DEFAULT NULL,
              `nilai_perilaku_40_persen` decimal(5,2) DEFAULT NULL COMMENT 'nilai perilaku x 40%',
              `rating_perilaku` tinyint DEFAULT NULL,
              `nilai_prestasi_kerja` decimal(5,2) DEFAULT NULL,
              `kategori_nilai_prestasi` varchar(50) DEFAULT NULL,
              `keterangan` mediumtext,
              `reason_note` tinytext,
              `gol_ruang_penilai` varchar(50) DEFAULT NULL,
              `unor_penilai` varchar(255) DEFAULT NULL,
              `status_penilai` varchar(50) DEFAULT NULL,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0' COMMENT '0: Belum Diupload, 1: Sudah Diupload, 2: Error Saat Upload, 3: Data Invalid, 4: Download Dari SIASN',
              `siasn_error` tinytext,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Watinting, 1: Approved, 2: Rejected, 10: Deleted',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_skp`),
              KEY `status` (`status`),
              KEY `fk_nip_rwyskp_to_pegawai` (`nip`),
              CONSTRAINT `fk_nip_rwyskp_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_skp_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_skp_periodik' => <<<'SQL'
            CREATE TABLE {{riwayat_skp_periodik}} (
              `id_riwayat_skp_periodik` bigint NOT NULL AUTO_INCREMENT,
              `id_pns` varchar(150) NOT NULL,
              `periode_id` varchar(150) DEFAULT NULL,
              `skp_id` varchar(150) DEFAULT NULL,
              `skp_penilaian_id` varchar(150) DEFAULT NULL,
              `jenis` int DEFAULT NULL,
              `tahun_skp` year DEFAULT NULL,
              `nip` varchar(50) NOT NULL,
              `nama` varchar(255) NOT NULL,
              `periode_awal_skp` date DEFAULT NULL,
              `periode_akhir_skp` date DEFAULT NULL,
              `skp_unor_id` varchar(150) DEFAULT NULL,
              `skp_unor` varchar(255) DEFAULT NULL,
              `skp_unor_induk` varchar(255) DEFAULT NULL,
              `skp_jabatan` varchar(255) DEFAULT NULL,
              `skp_jenis_jabatan` int DEFAULT NULL,
              `is_skp_plt_plh_pjb` int DEFAULT NULL,
              `hasil_kerja` varchar(50) DEFAULT NULL,
              `perilaku_kerja` varchar(50) DEFAULT NULL,
              `hasil_akhir` varchar(50) DEFAULT NULL,
              `pegawai_atasan_id` varchar(150) DEFAULT NULL,
              `pegawai_atasan_nip` varchar(50) DEFAULT NULL,
              `pegawai_atasan_nama` varchar(255) DEFAULT NULL,
              `pegawai_atasan_unor_id` varchar(150) DEFAULT NULL,
              `pegawai_atasan_unor` varchar(255) DEFAULT NULL,
              `pegawai_atasan_jabatan` varchar(255) DEFAULT NULL,
              `pegawai_atasan_golru` varchar(10) DEFAULT NULL,
              `waktu_dinilai` datetime DEFAULT NULL,
              `pegawai_penilai_id` varchar(150) DEFAULT NULL,
              `golru` varchar(10) DEFAULT NULL,
              `f_arsip_1` varchar(512) DEFAULT NULL,
              `f_arsip_2` varchar(512) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '1',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id_riwayat_skp_periodik`),
              KEY `nip` (`nip`),
              CONSTRAINT `chk_riwayat_skp_periodik_status` CHECK (`status` IN (0, 1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_lckh' => <<<'SQL'
            CREATE TABLE {{riwayat_lckh}} (
              `id_riwayat_lckh` int NOT NULL AUTO_INCREMENT,
              `id_unit` int DEFAULT NULL,
              `id_satker` int DEFAULT NULL,
              `nip` varchar(30) NOT NULL,
              `tgl_laporan` date NOT NULL,
              `nama` varchar(256) NOT NULL,
              `unit` varchar(256) DEFAULT NULL,
              `satker` varchar(256) DEFAULT NULL,
              `nip_atasan` varchar(30) DEFAULT NULL,
              `nama_atasan` varchar(150) NOT NULL,
              `kegiatan` mediumtext NOT NULL,
              `output` mediumtext NOT NULL,
              `jumlah_diselesaikan` mediumtext,
              `jam_mulai` mediumtext,
              `jam_selesai` mediumtext,
              `catatan` text,
              `file_lckh` varchar(250) DEFAULT NULL,
              `status` int NOT NULL DEFAULT '0' COMMENT '0: Wating, 1: Approved, 2: Rejected, 3: Revisi',
              `auto_approval` tinyint(1) DEFAULT '2' COMMENT '1: Yes, 2: No',
              `status_regen` tinyint(1) DEFAULT '0' COMMENT '0: Normal State, 1: Process State',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `show_notif` int NOT NULL DEFAULT '0',
              `show_atasan` int NOT NULL DEFAULT '0',
              `show_history_atasan` int NOT NULL DEFAULT '1',
              PRIMARY KEY (`id_riwayat_lckh`),
              KEY `nip_atasan_status` (`nip_atasan`,`status`),
              KEY `tgl_laporan` (`tgl_laporan`),
              KEY `fk_nip_rwylkh_to_pegawai` (`nip`),
              KEY `tgl_laporan_status` (`tgl_laporan`,`status`),
              KEY `file_lckh` (`file_lckh`),
              KEY `fk_riwayat_lckh_ibfk_03` (`id_unit`),
              KEY `fk_riwayat_lckh_ibfk_04` (`id_satker`),
              CONSTRAINT `fk_riwayat_lckh_ibfk_01` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_riwayat_lckh_ibfk_02` FOREIGN KEY (`nip_atasan`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_lckh_status` CHECK (`status` IN (0, 1, 2, 3, 10))
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatSkpLkh gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
