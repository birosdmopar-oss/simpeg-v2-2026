<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02: hukdis & angka kredit.
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `riwayat_hukdis` — D1:4853-4893; 30 kolom; 4 FK di CREATE; 1 CHECK [V2].
 *   - `riwayat_ak` — D1:4034-4092; 50 kolom; 2 FK di CREATE, 1 FK G-02 ditahan; 1 CHECK [V2].
 *   - `riwayat_ak_siasn` — D1:4174-4212; 29 kolom; 2 FK di CREATE, 1 FK G-02 ditahan; 1 CHECK [V2].
 *   - `konv_ak` — D1:2057-2088; 20 kolom; 2 FK di CREATE, 3 FK G-02 ditahan; 1 CHECK [V2].
 *   - `ignore_konv_ak` — D1:1346-1351; 2 kolom; 1 FK di CREATE.
 *
 * Deviasi [V2] (rinci per tabel di dokumen Bagian 3):
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE atau SET NULL/CASCADE). Ganti NIP lewat B-06.
 *   - CHECK domain status/flag (D1 tanpa CHECK); nilai legacy di luar domain dinormalkan saat impor (Bagian 6).
 *   - `COLLATE utf8mb4_unicode_ci` per kolom di D1 sama dengan collation tabel, jadi diwarisi dari tabel; nilai
 *     AUTO_INCREMENT awal dan ROW_FORMAT=DYNAMIC (default InnoDB) tidak ditulis.
 *   - Trigger legacy pada tabel ini tidak dibawa; padanannya aturan aplikasi v2 (dokumen Bagian 5).
 *   - VARCHAR(30) (D1 VARCHAR(50)) untuk `riwayat_ak.nip`, `riwayat_ak_siasn.nip`, `konv_ak.nip`,
 *     `ignore_konv_ak.nip`: = PK `pegawai.nip` (K1); tanpa kehilangan data karena FK legacy sudah memaksa nilai =
 *     `pegawai.nip`.
 *   - Tipe INT (D1 TINYINT) untuk `riwayat_hukdis.id_tingkat_hukdis`, `riwayat_hukdis.id_jenis_hukdis`: mengikuti PK
 *     master di `main` (`jenjang_pendidikan`/`jenis_hukdis`/`tingkat_hukdis`/`tanda_jasa` INT; FK mensyaratkan tipe
 *     sama). Penyelarasan master dengan D1 diajukan di dokumen Bagian 8.
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
class CreateRiwayatHukdisAk extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['riwayat_hukdis', 'riwayat_ak', 'riwayat_ak_siasn', 'konv_ak', 'ignore_konv_ak'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'riwayat_hukdis' => <<<'SQL'
            CREATE TABLE {{riwayat_hukdis}} (
              `id_riwayat_hukdis` int NOT NULL AUTO_INCREMENT,
              `nip` varchar(30) NOT NULL,
              `id_tingkat_hukdis` int DEFAULT NULL,
              `id_jenis_hukdis` int DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `tmtsk` date NOT NULL,
              `tgl_sk` date DEFAULT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tingkat_hukdis` varchar(100) DEFAULT NULL,
              `jenis_hukdis` varchar(255) DEFAULT NULL,
              `gol` varchar(10) DEFAULT NULL,
              `ruang` varchar(10) DEFAULT NULL,
              `gol_ruang` varchar(10) DEFAULT NULL,
              `pangkat` varchar(50) DEFAULT NULL,
              `masa_hukuman` tinytext,
              `akhir_hukdis` date DEFAULT NULL,
              `aturan_dilanggar` tinytext,
              `alasan_hukuman` tinytext,
              `keterangan` tinytext,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '1: Active, 10: Deleted',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `approved_by` int DEFAULT NULL,
              `reason_note` tinytext,
              `show_notif` tinyint(1) NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint(1) NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_hukdis`),
              KEY `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis` (`id_tingkat_hukdis`),
              KEY `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis` (`id_jenis_hukdis`),
              KEY `fk_id_pangkat_rwyhukdis_to_pangkat` (`id_pangkat`),
              KEY `fk_nip_rwyhukdis_to_pegawai` (`nip`),
              CONSTRAINT `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis` FOREIGN KEY (`id_jenis_hukdis`) REFERENCES {{jenis_hukdis}} (`id_jenis_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_pangkat_rwyhukdis_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis` FOREIGN KEY (`id_tingkat_hukdis`) REFERENCES {{tingkat_hukdis}} (`id_tingkat_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rwyhukdis_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_hukdis_status` CHECK (`status` IN (0, 1, 2, 3, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_ak' => <<<'SQL'
            CREATE TABLE {{riwayat_ak}} (
              `id_riwayat_ak` int NOT NULL AUTO_INCREMENT,
              `id_jabatan` int DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `nip` varchar(30) NOT NULL,
              `jenis_ak` tinyint NOT NULL DEFAULT '2' COMMENT '1: Starting AK, 2: Pengajuan Riwayat AK, 3: Sync From SIASN',
              `nama` varchar(150) DEFAULT NULL,
              `no_induk_jf` varchar(50) DEFAULT NULL,
              `no_hpak` varchar(50) DEFAULT NULL,
              `tgl_pak` date DEFAULT NULL,
              `periode_awal` date DEFAULT NULL,
              `periode_akhir` date DEFAULT NULL,
              `ak_kum_kp` double DEFAULT NULL COMMENT 'Angka Kredit Kumulatif Untuk KP',
              `ak_kum_next_jen` double DEFAULT NULL COMMENT 'Angka Kredit Kumulatif Untuk Jenjang {Selanjutnya}',
              `ak_terakhir` double NOT NULL DEFAULT '0' COMMENT 'Nilai Terakhir',
              `pengajuan_ak` double NOT NULL DEFAULT '0' COMMENT 'Nilai Pengajuan',
              `kredit_utama_baru` double NOT NULL DEFAULT '0',
              `kredit_penunjang_baru` double NOT NULL DEFAULT '0',
              `nilai_ak` double NOT NULL DEFAULT '0' COMMENT 'Nilai Final (Terakhir + Pengajuan)',
              `nama_pym` varchar(256) DEFAULT NULL COMMENT 'Nama Pejabat Yang Menetapkan',
              `jab_pym` varchar(256) DEFAULT NULL COMMENT 'Jabatan Pejabat Yang Menetapkan',
              `flag_instansi_ym` tinyint NOT NULL DEFAULT '0' COMMENT '0: Kemenparekraf, 1: Instansi Lain',
              `instansi_ym` varchar(256) DEFAULT NULL,
              `jabatan` varchar(256) DEFAULT NULL,
              `tmt_jab` date DEFAULT NULL,
              `mker_th_jab` tinyint DEFAULT NULL,
              `mker_bl_jab` tinyint DEFAULT NULL,
              `pangkat` varchar(256) DEFAULT NULL,
              `gol_ruang` varchar(50) DEFAULT NULL,
              `tmt_pang` date DEFAULT NULL,
              `mker_th_pang` tinyint DEFAULT NULL,
              `mker_bl_pang` tinyint DEFAULT NULL,
              `keterangan` tinytext,
              `file_pak` varchar(256) DEFAULT NULL,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `siasn_flag` tinyint NOT NULL DEFAULT '0',
              `siasn_error` text,
              `siasn_lu` datetime DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '0' COMMENT '0: Waiting: 1: Disetujui, 2: Ditolak',
              `reason_note` tinytext,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `created_by` int DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              `appr_at` datetime DEFAULT NULL,
              `appr_by` int DEFAULT NULL,
              `show_notif` tinyint NOT NULL DEFAULT '0',
              `notif_date` datetime DEFAULT NULL,
              `show_ua_upt` tinyint NOT NULL DEFAULT '0',
              `show_ua_deputi` tinyint NOT NULL DEFAULT '0',
              `show_ua_biro` tinyint NOT NULL DEFAULT '0',
              PRIMARY KEY (`id_riwayat_ak`),
              KEY `fk_nip_rak_to_peg` (`nip`),
              KEY `fk_id_jabatan_rak_to_jab` (`id_jabatan`),
              KEY `fk_id_pangkat_rak_to_pangkat` (`id_pangkat`),
              CONSTRAINT `fk_id_pangkat_rak_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rak_to_peg` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_ak_status` CHECK (`status` IN (0, 1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'riwayat_ak_siasn' => <<<'SQL'
            CREATE TABLE {{riwayat_ak_siasn}} (
              `id_riwayat_ak_siasn` bigint NOT NULL AUTO_INCREMENT,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `id_pns` varchar(100) DEFAULT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `nip` varchar(30) NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_sk` date DEFAULT NULL,
              `bulan_mulai_penilaian` tinyint DEFAULT NULL,
              `tahun_mulai_penilaian` year DEFAULT NULL,
              `bulan_selesai_penilaian` tinyint DEFAULT NULL,
              `tahun_selesai_penilaian` year DEFAULT NULL,
              `kredit_utama_baru` decimal(10,3) DEFAULT NULL,
              `kredit_penunjang_baru` decimal(10,3) DEFAULT NULL,
              `kredit_baru_total` decimal(10,3) DEFAULT NULL,
              `id_rw_jabatan_siasn` varchar(100) DEFAULT NULL,
              `nama_jabatan` varchar(255) DEFAULT NULL,
              `is_angka_kredit_pertama` varchar(10) DEFAULT NULL,
              `is_integrasi` varchar(10) DEFAULT NULL,
              `is_konversi` varchar(10) DEFAULT NULL,
              `f_pak_siasn` varchar(255) DEFAULT NULL,
              `f_pak` varchar(255) DEFAULT NULL,
              `sumber` varchar(255) DEFAULT NULL,
              `is_pemenuhan_kp` varchar(10) DEFAULT NULL,
              `keterangan` text,
              `status` tinyint NOT NULL DEFAULT '1',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              PRIMARY KEY (`id_riwayat_ak_siasn`),
              UNIQUE KEY `id_rw_siasn` (`id_rw_siasn`),
              KEY `fk_nip_rw_ak_siasn_01` (`nip`),
              KEY `fk_id_jabatan_rw_ak_siasn_02` (`id_jabatan`),
              KEY `fk_id_pangkat_rw_ak_siasn_03` (`id_pangkat`),
              CONSTRAINT `fk_id_pangkat_rw_ak_siasn_03` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_rw_ak_siasn_01` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_riwayat_ak_siasn_status` CHECK (`status` IN (0, 1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'konv_ak' => <<<'SQL'
            CREATE TABLE {{konv_ak}} (
              `nip` varchar(30) NOT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_unit` int DEFAULT NULL,
              `id_satker` int DEFAULT NULL,
              `pangkat` varchar(255) DEFAULT NULL,
              `gol_ruang` varchar(50) DEFAULT NULL,
              `jabatan` varchar(255) DEFAULT NULL,
              `unit` varchar(255) DEFAULT NULL,
              `satker` varchar(255) DEFAULT NULL,
              `no_pak` varchar(255) DEFAULT NULL,
              `tgl_pak` date DEFAULT NULL,
              `ak_terakhir` double NOT NULL DEFAULT '0' COMMENT 'Nilai Terakhir',
              `file_dupak` varchar(255) DEFAULT NULL,
              `status` tinyint NOT NULL DEFAULT '0',
              `reason_note` tinytext,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `created_by` int DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              PRIMARY KEY (`nip`),
              KEY `fk_id_pangkat_konvak_to_pangkat` (`id_pangkat`),
              KEY `fk_id_jabatan_konvak_to_jabatan` (`id_jabatan`),
              KEY `fk_id_unit_konvak_to_unit` (`id_unit`),
              KEY `fk_id_satker_konvak_to_satker` (`id_satker`),
              CONSTRAINT `fk_id_pangkat_konvak_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_konvak_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `chk_konv_ak_status` CHECK (`status` IN (0, 1, 2, 10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'ignore_konv_ak' => <<<'SQL'
            CREATE TABLE {{ignore_konv_ak}} (
              `nip` varchar(30) NOT NULL,
              `ignore_date` date NOT NULL,
              PRIMARY KEY (`nip`,`ignore_date`),
              CONSTRAINT `fk_nip_ignorekonvak_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
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

            throw new RuntimeException('DBV-013: DDL CreateRiwayatHukdisAk gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
