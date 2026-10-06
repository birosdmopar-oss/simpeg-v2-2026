<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: riwayat jabatan & penugasan Plt/Plh.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `riwayat_mutasi_jabatan` — D1:5713-5810; 65 kolom; 2 FK di CREATE, 9 FK G-02 ditahan, 4 FK ke tabel belum ada
 *     di v2; 1 CHECK [V2].
 *   - `pegawai_plt` — D1:3414-3460; 22 kolom; 1 FK di CREATE, 9 FK G-02 ditahan, 1 FK ke tabel belum ada di v2; 1
 *     CHECK [V2].
 *   - `pegawai_plh` — D1:3363-3409; 22 kolom; 1 FK di CREATE, 9 FK G-02 ditahan, 1 FK ke tabel belum ada di v2; 1
 *     CHECK [V2].
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
 *   - ke `jabatan_koordinasi`/`rumpun_jabatan` (belum ada tabelnya di v2): menunggu DBV master baru (Bagian 7).
 *
 * Kolom audit diisi aplikasi (waktu UTC, `*_by` = id_pengguna tanpa FK); default DB hanya cadangan. Tanpa baris seed.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatJabatan extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['riwayat_mutasi_jabatan', 'pegawai_plt', 'pegawai_plh'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'riwayat_mutasi_jabatan' => <<<'SQL'
            CREATE TABLE {{riwayat_mutasi_jabatan}} (
              `id_riwayat_mutasi_jabatan` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_group_jabatan` int DEFAULT NULL,
              `id_sub_group_jabatan` int DEFAULT NULL,
              `id_unit` int DEFAULT NULL,
              `id_satker` int DEFAULT NULL,
              `id_atasan_es_1` int DEFAULT NULL,
              `id_atasan_es_2` int DEFAULT NULL,
              `id_atasan_es_3` int DEFAULT NULL,
              `id_atasan_es_3_koord` int DEFAULT NULL,
              `id_atasan_es_4` int DEFAULT NULL,
              `id_atasan_es_4_koord` int DEFAULT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_jabatan_koord` int DEFAULT NULL,
              `id_gol_pppk` tinyint DEFAULT NULL,
              `id_rumpun_jabatan` tinyint DEFAULT NULL,
              `jenis_jabatan` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Nomenlaktur, 2: Instansi Lain, 3: PLT',
              `jenis_jabatan_koord` tinyint(1) NOT NULL DEFAULT '3' COMMENT '1: Koordinator, 2: Subkoordinator, 3: Tidak Ada',
              `jenis_mutasi` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Mutasi Jabatan, 2: Mutasi Unor',
              `tmtsk` date NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_sk` date DEFAULT NULL,
              `pmk` year DEFAULT NULL COMMENT 'Tahun Perjanjian Masa Kerja',
              `mhpk_mulai` date DEFAULT NULL COMMENT 'Tgl. Mulai Masa Perjanjian Hubungan Kerja',
              `mhpk_akhir` date DEFAULT NULL COMMENT 'Tgl. Akhir Masa Perjanjian Hubungan Kerja',
              `gol_pppk` varchar(10) DEFAULT NULL,
              `nama_instansi` varchar(255) DEFAULT NULL,
              `group_jabatan` varchar(45) DEFAULT NULL,
              `sub_group_jabatan` varchar(100) DEFAULT NULL,
              `unit` varchar(255) DEFAULT NULL,
              `satker` varchar(255) DEFAULT NULL,
              `atasan_es_1` varchar(255) DEFAULT NULL,
              `atasan_es_2` varchar(255) DEFAULT NULL,
              `atasan_es_3` varchar(255) DEFAULT NULL,
              `atasan_es_3_koord` varchar(255) DEFAULT NULL,
              `atasan_es_4` varchar(255) DEFAULT NULL,
              `atasan_es_4_koord` varchar(255) DEFAULT NULL,
              `jabatan` varchar(255) DEFAULT NULL,
              `jabatan_koord` varchar(255) DEFAULT NULL,
              `jabatan_lain` varchar(255) DEFAULT NULL,
              `rumpun_jabatan` varchar(50) DEFAULT NULL,
              `subrumpun_jabatan` text,
              `kelas_jabatan` tinyint DEFAULT NULL,
              `kredit_jft` int DEFAULT NULL,
              `no_induk_jft` varchar(50) DEFAULT NULL,
              `status_jft` tinyint(1) DEFAULT NULL COMMENT '1: Aktif, 2: Pembebasan Sementara',
              `ser_dosen` varchar(255) DEFAULT NULL COMMENT 'File Sertifikat Dosen',
              `f_spmt` varchar(255) DEFAULT NULL COMMENT 'File SPMT',
              `f_bapel` varchar(255) DEFAULT NULL COMMENT 'File BA Pelantikan',
              `keterangan` mediumtext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0' COMMENT '0: Belum Diupload, 1: Sudah Diupload',
              `siasn_error` text,
              `siasn_lu` datetime DEFAULT NULL COMMENT 'SIASN Last Update',
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
              PRIMARY KEY (`id_riwayat_mutasi_jabatan`),
              KEY `fk_id_group_jabatan_rwymj_to_gj` (`id_group_jabatan`),
              KEY `fk_id_sub_group_jabatan_rwymj_to_sgj` (`id_sub_group_jabatan`),
              KEY `fk_id_unit_rwymj_to_unit` (`id_unit`),
              KEY `fk_id_satker_rwymj_to_satker` (`id_satker`),
              KEY `fk_id_jabatan_rwymj_to_jabatan` (`id_jabatan`),
              KEY `fk_id_atasan_es_1_rwymj_to_jabatan` (`id_atasan_es_1`),
              KEY `fk_id_atasan_es_2_rwymj_to_jabatan` (`id_atasan_es_2`),
              KEY `fk_id_atasan_es_3_rwymj_to_jabatan` (`id_atasan_es_3`),
              KEY `fk_id_atasan_es_4_rwymj_to_jabatan` (`id_atasan_es_4`),
              KEY `fk_nip_rwymutasijabatan_to_pegawai` (`nip`),
              KEY `fk_id_atasan_es_3_koord_rmj_to_jabkoor` (`id_atasan_es_3_koord`),
              KEY `fk_id_atasan_es_4_koord_rmj_to_jabkoor` (`id_atasan_es_4_koord`),
              KEY `fk_id_jabatan_koord_rmj_to_jabkoor` (`id_jabatan_koord`),
              KEY `riwayat_mutasi_jabatan_ibfk_01` (`id_gol_pppk`),
              KEY `riwayat_mutasi_jabatan_ibfk_02` (`id_rumpun_jabatan`),
              CONSTRAINT `fk_nip_rwymutasijabatan_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `riwayat_mutasi_jabatan_ibfk_01` FOREIGN KEY (`id_gol_pppk`) REFERENCES {{gol_pppk}} (`id_gol_pppk`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_mutasi_jabatan_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_plt' => <<<'SQL'
            CREATE TABLE {{pegawai_plt}} (
              `id_pegawai_plt` int NOT NULL AUTO_INCREMENT,
              `jenis_jabatan` tinyint NOT NULL DEFAULT '1' COMMENT '1: Jabatan Normal, 2: Jabatan Koordinasi',
              `jenis_jabatan_koord` tinyint DEFAULT NULL COMMENT '1: Koordinator, 2: Subkoordinator',
              `tgl_mulai` date DEFAULT NULL,
              `tgl_akhir` date DEFAULT NULL,
              `nip_plt` varchar(30) NOT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_jabatan_koord` int DEFAULT NULL,
              `id_group_jabatan` int DEFAULT NULL,
              `id_sub_group_jabatan` int DEFAULT NULL,
              `id_unit` int DEFAULT NULL,
              `id_satker` int DEFAULT NULL,
              `id_group_jabatan_plt` int DEFAULT NULL,
              `id_sub_group_jabatan_plt` int DEFAULT NULL,
              `id_unit_plt` int DEFAULT NULL,
              `id_satker_plt` int DEFAULT NULL,
              `keterangan` tinytext,
              `lampiran` varchar(255) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              PRIMARY KEY (`id_pegawai_plt`),
              KEY `fk_nip_plt_plt_to_pegawai` (`nip_plt`),
              KEY `fk_id_group_jabatan_plt_to_gj` (`id_group_jabatan`),
              KEY `fk_id_sub_group_jabatan_plt_to_sgj` (`id_sub_group_jabatan`),
              KEY `fk_id_unit_plt_to_unit` (`id_unit`),
              KEY `fk_id_satker_plt_to_satker` (`id_satker`),
              KEY `fk_id_group_jabatan_plt_plt_to_gj` (`id_group_jabatan_plt`),
              KEY `fk_id_sub_group_jabatan_plt_plt_to_sgj` (`id_sub_group_jabatan_plt`),
              KEY `fk_id_unit_plt_plt_to_unit` (`id_unit_plt`),
              KEY `fk_id_satker_plt_plt_to_satker` (`id_satker_plt`),
              KEY `fk_id_jabatan_plt_to_jabatan` (`id_jabatan`),
              KEY `fk_id_jabatan_koord_plt_to_jabkoord` (`id_jabatan_koord`),
              CONSTRAINT `fk_nip_plt_plt_to_pegawai` FOREIGN KEY (`nip_plt`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_pegawai_plt_status` CHECK (`status` IN (1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_plh' => <<<'SQL'
            CREATE TABLE {{pegawai_plh}} (
              `id_pegawai_plh` int NOT NULL AUTO_INCREMENT,
              `jenis_jabatan` tinyint NOT NULL DEFAULT '1' COMMENT '1: Jabatan Normal, 2: Jabatan Koordinasi',
              `jenis_jabatan_koord` tinyint DEFAULT NULL COMMENT '1: Koordinator, 2: Subkoordinator',
              `tgl_mulai` date DEFAULT NULL,
              `tgl_akhir` date DEFAULT NULL,
              `nip_plh` varchar(30) NOT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_jabatan_koord` int DEFAULT NULL,
              `id_group_jabatan` int DEFAULT NULL,
              `id_sub_group_jabatan` int DEFAULT NULL,
              `id_unit` int DEFAULT NULL,
              `id_satker` int DEFAULT NULL,
              `id_group_jabatan_plh` int DEFAULT NULL,
              `id_sub_group_jabatan_plh` int DEFAULT NULL,
              `id_unit_plh` int DEFAULT NULL,
              `id_satker_plh` int DEFAULT NULL,
              `keterangan` tinytext,
              `lampiran` varchar(255) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              PRIMARY KEY (`id_pegawai_plh`),
              KEY `fk_pegawai_plh_ibfk_02` (`id_jabatan`),
              KEY `fk_pegawai_plh_ibfk_03` (`id_jabatan_koord`),
              KEY `fk_pegawai_plh_ibfk_04` (`id_group_jabatan`),
              KEY `fk_pegawai_plh_ibfk_05` (`id_sub_group_jabatan`),
              KEY `fk_pegawai_plh_ibfk_06` (`id_unit`),
              KEY `fk_pegawai_plh_ibfk_07` (`id_satker`),
              KEY `fk_pegawai_plh_ibfk_01` (`nip_plh`),
              KEY `fk_pegawai_plh_ibfk_08` (`id_group_jabatan_plh`),
              KEY `fk_pegawai_plh_ibfk_09` (`id_sub_group_jabatan_plh`),
              KEY `fk_pegawai_plh_ibfk_10` (`id_unit_plh`),
              KEY `fk_pegawai_plh_ibfk_11` (`id_satker_plh`),
              CONSTRAINT `fk_pegawai_plh_ibfk_01` FOREIGN KEY (`nip_plh`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_pegawai_plh_status` CHECK (`status` IN (1, 2, 10))
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatJabatan gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
