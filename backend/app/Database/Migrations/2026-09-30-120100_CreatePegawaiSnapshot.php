<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-012 — B-01: 14 tabel snapshot `pegawai_*` (satu baris per `nip`).
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-012.
 *
 * Sumber [K]: dump struktur produksi `simpeg01` 01-10-2026 (D1, `simpeg01_struktur_lengkap_20261001.sql`,
 * di luar repo). Kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY,
 * dan nama FK disalin persis dari D1:
 *   - `pegawai_kp` — D1:3131-3162; 22 kolom; 3 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_cpns` — D1:2861-2892; 22 kolom; 3 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_pns` — D1:3540-3571; 22 kolom; 3 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_kgb` — D1:3107-3126; 12 kolom; 2 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_pendidikan` — D1:3326-3358; 21 kolom; 4 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_diklat` — D1:2897-2919; 15 kolom; 2 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_hukdis` — D1:3008-3039; 20 kolom; 4 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_ak` — D1:2673-2717; 35 kolom; 2 FK di CREATE, 1 FK ke riwayat di 131000, 1 FK G-02 ditahan.
 *   - `pegawai_ak_siasn` — D1:2742-2779; 28 kolom; 2 FK di CREATE, 1 FK ke riwayat di 131000, 1 FK G-02 ditahan.
 *   - `pegawai_keluarga` — D1:3088-3102; 9 kolom; 1 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_alamat` — D1:2784-2817; 20 kolom; 5 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_alamat_kantor` — D1:2822-2856; 21 kolom; 5 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_tanda_jasa` — D1:3669-3686; 10 kolom; 2 FK di CREATE, 1 FK ke riwayat di 131000.
 *   - `pegawai_mutasi_jabatan` — D1:3192-3277; 52 kolom; 2 FK di CREATE, 1 FK ke riwayat di 131000, 9 FK G-02
 *     ditahan, 4 FK ke tabel belum ada di v2.
 *
 * Deviasi [V2] (rinci per tabel di dokumen Bagian 3):
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT (D1: CASCADE/CASCADE atau SET NULL/CASCADE). Ganti NIP lewat B-06.
 *   - `COLLATE utf8mb4_unicode_ci` per kolom di D1 sama dengan collation tabel, jadi diwarisi dari tabel; nilai
 *     AUTO_INCREMENT awal dan ROW_FORMAT=DYNAMIC (default InnoDB) tidak ditulis.
 *   - Trigger legacy pada tabel ini tidak dibawa; padanannya aturan aplikasi v2 (dokumen Bagian 5).
 *   - VARCHAR(30) (D1 VARCHAR(50)) untuk `pegawai_ak.nip`, `pegawai_ak_siasn.nip`: = PK `pegawai.nip` (K1); tanpa
 *     kehilangan data karena FK legacy sudah memaksa nilai = `pegawai.nip`.
 *   - Tipe INT (D1 TINYINT) untuk `pegawai_pendidikan.id_jenjang_pendidikan`, `pegawai_hukdis.id_tingkat_hukdis`,
 *     `pegawai_hukdis.id_jenis_hukdis`, `pegawai_tanda_jasa.id_tanda_jasa`: mengikuti PK master di `main`
 *     (`jenjang_pendidikan`/`jenis_hukdis`/`tingkat_hukdis`/`tanda_jasa` INT; FK mensyaratkan tipe sama).
 *     Penyelarasan master dengan D1 diajukan di dokumen Bagian 8.
 *
 * FK yang TIDAK dipasang di sini (index kolomnya sudah ada di DDL D1, jadi migration penyusul cukup
 * ADD CONSTRAINT):
 *   - snapshot → riwayat: 2026-09-30-131000_AddFkSnapshotKeRiwayat (DBV-013);
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
class CreatePegawaiSnapshot extends Migration
{
    /**
     * Urutan CREATE (induk → anak); down() men-drop dengan urutan terbalik.
     */
    public const TABLES = ['pegawai_kp', 'pegawai_cpns', 'pegawai_pns', 'pegawai_kgb', 'pegawai_pendidikan', 'pegawai_diklat', 'pegawai_hukdis', 'pegawai_ak', 'pegawai_ak_siasn', 'pegawai_keluarga', 'pegawai_alamat', 'pegawai_alamat_kantor', 'pegawai_tanda_jasa', 'pegawai_mutasi_jabatan'];

    /**
     * DDL per tabel; `{{nama}}` diganti nama tabel ber-prefix (DBPrefix) yang sudah di-escape.
     *
     * @var array<string, string>
     */
    private const DDL = [
        'pegawai_kp' => <<<'SQL'
            CREATE TABLE {{pegawai_kp}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_kp` int DEFAULT NULL,
              `id_jenis_kp` tinyint DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `tmtsk` date NOT NULL,
              `tgl_sk` date NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_pertek_bkn` date DEFAULT NULL,
              `no_pertek_bkn` varchar(100) DEFAULT NULL,
              `jumlah_kredit_utama` double DEFAULT NULL,
              `jumlah_kredit_tambahan` double DEFAULT NULL,
              `jenis_kp` varchar(100) NOT NULL,
              `gol` varchar(10) NOT NULL,
              `ruang` varchar(10) NOT NULL,
              `gol_ruang` varchar(10) NOT NULL,
              `pangkat` varchar(50) NOT NULL,
              `mker_th` tinyint DEFAULT NULL,
              `mker_bl` tinyint DEFAULT NULL,
              `gaji_pokok` int DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_kp_peg_kp_to_rwy_kp` (`id_riwayat_kp`),
              KEY `fk_id_jenis_kp_peg_kp_to_jenis_kp` (`id_jenis_kp`),
              KEY `fk_id_pangkat_peg_kp_to_pangkat` (`id_pangkat`),
              CONSTRAINT `fk_id_jenis_kp_peg_kp_to_jenis_kp` FOREIGN KEY (`id_jenis_kp`) REFERENCES {{jenis_kp}} (`id_jenis_kp`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_pangkat_peg_kp_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_pegkp_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_cpns' => <<<'SQL'
            CREATE TABLE {{pegawai_cpns}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_kp` int NOT NULL,
              `id_jenis_kp` tinyint DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `tmtsk` date NOT NULL,
              `tgl_sk` date NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_pertek_bkn` date DEFAULT NULL,
              `no_pertek_bkn` varchar(100) DEFAULT NULL,
              `jumlah_kredit_utama` double DEFAULT NULL,
              `jumlah_kredit_tambahan` double DEFAULT NULL,
              `jenis_kp` varchar(100) NOT NULL,
              `gol` varchar(10) NOT NULL,
              `ruang` varchar(10) NOT NULL,
              `gol_ruang` varchar(10) NOT NULL,
              `pangkat` varchar(50) NOT NULL,
              `mker_th` tinyint DEFAULT NULL,
              `mker_bl` tinyint DEFAULT NULL,
              `gaji_pokok` int DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_kp_pcpns_to_rwy_kp` (`id_riwayat_kp`),
              KEY `fk_id_jenis_kp_pcpns_to_jenis_kp` (`id_jenis_kp`),
              KEY `fk_id_pangkat_pcpns_to_pangkat` (`id_pangkat`),
              CONSTRAINT `fk_id_jenis_kp_pcpns_to_jenis_kp` FOREIGN KEY (`id_jenis_kp`) REFERENCES {{jenis_kp}} (`id_jenis_kp`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_pangkat_pcpns_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_pcpns_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_pns' => <<<'SQL'
            CREATE TABLE {{pegawai_pns}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_kp` int DEFAULT NULL,
              `id_jenis_kp` tinyint DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `tmtsk` date NOT NULL,
              `tgl_sk` date NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_pertek_bkn` date DEFAULT NULL,
              `no_pertek_bkn` varchar(100) DEFAULT NULL,
              `jumlah_kredit_utama` double DEFAULT NULL,
              `jumlah_kredit_tambahan` double DEFAULT NULL,
              `jenis_kp` varchar(100) NOT NULL,
              `gol` varchar(10) NOT NULL,
              `ruang` varchar(10) NOT NULL,
              `gol_ruang` varchar(10) NOT NULL,
              `pangkat` varchar(50) NOT NULL,
              `mker_th` tinyint DEFAULT NULL,
              `mker_bl` tinyint DEFAULT NULL,
              `gaji_pokok` int DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_kp_ppns_to_rwy_kp` (`id_riwayat_kp`),
              KEY `fk_id_jenis_kp_ppns_to_jenis_kp` (`id_jenis_kp`),
              KEY `fk_id_pangkat_ppns_to_pangkat` (`id_pangkat`),
              CONSTRAINT `fk_id_jenis_kp_ppns_to_jenis_kp` FOREIGN KEY (`id_jenis_kp`) REFERENCES {{jenis_kp}} (`id_jenis_kp`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_pangkat_ppns_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_ppns_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_kgb' => <<<'SQL'
            CREATE TABLE {{pegawai_kgb}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_kgb` int DEFAULT NULL,
              `id_gol_pppk` tinyint DEFAULT NULL,
              `tmtsk` date NOT NULL,
              `no_sk` varchar(100) DEFAULT NULL,
              `tgl_sk` date NOT NULL,
              `gol_pppk` varchar(10) DEFAULT NULL,
              `mker_gol_th` tinyint DEFAULT NULL,
              `gaji_pokok` int DEFAULT NULL,
              `jym` varchar(255) DEFAULT NULL COMMENT 'Jabatan Yang Menandatangani',
              `keterangan` tinytext,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_kgb_pkgb_to_rkgb` (`id_riwayat_kgb`),
              KEY `pegawai_kgb_ibfk_01` (`id_gol_pppk`),
              CONSTRAINT `fk_nip_pegkgb_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `pegawai_kgb_ibfk_01` FOREIGN KEY (`id_gol_pppk`) REFERENCES {{gol_pppk}} (`id_gol_pppk`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_pendidikan' => <<<'SQL'
            CREATE TABLE {{pegawai_pendidikan}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_pendidikan` int DEFAULT NULL,
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
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_jenjang_pendidikan_pegpend_to_jpend` (`id_jenjang_pendidikan`),
              KEY `fk_id_bidang_pendidikan_pegpend_to_bpend` (`id_bidang_pendidikan`),
              KEY `fk_id_jurusan_pendidikan_pegpend_to_jupend` (`id_jurusan_pendidikan`),
              KEY `fk_id_riwayat_pendidikan_pegpend_to_rpend` (`id_riwayat_pendidikan`),
              CONSTRAINT `fk_id_bidang_pendidikan_pegpend_to_bpend` FOREIGN KEY (`id_bidang_pendidikan`) REFERENCES {{bidang_pendidikan}} (`id_bidang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_jenjang_pendidikan_pegpend_to_jpend` FOREIGN KEY (`id_jenjang_pendidikan`) REFERENCES {{jenjang_pendidikan}} (`id_jenjang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_jurusan_pendidikan_pegpend_to_jupend` FOREIGN KEY (`id_jurusan_pendidikan`) REFERENCES {{jurusan_pendidikan}} (`id_jurusan_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_pegpend_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_diklat' => <<<'SQL'
            CREATE TABLE {{pegawai_diklat}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_diklat` int DEFAULT NULL,
              `id_diklat` tinyint DEFAULT NULL,
              `id_sub_group_jabatan` int DEFAULT NULL,
              `tgl_sertifikat` date DEFAULT NULL,
              `no_sertifikat` varchar(255) DEFAULT NULL,
              `jumlah_jp` int DEFAULT NULL,
              `nama_diklat` varchar(255) DEFAULT NULL,
              `nama_diklat_lain` varchar(255) DEFAULT NULL,
              `sub_group_jabatan` varchar(255) DEFAULT NULL,
              `deskripsi` tinytext,
              `instansi_penyelenggara` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_diklat_pegdiklat_to_rwydiklat` (`id_riwayat_diklat`),
              KEY `fk_id_diklat_pegdiklat_to_diklat` (`id_diklat`),
              CONSTRAINT `fk_id_diklat_pegdiklat_to_diklat` FOREIGN KEY (`id_diklat`) REFERENCES {{diklat}} (`id_diklat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_pegdiklat_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_hukdis' => <<<'SQL'
            CREATE TABLE {{pegawai_hukdis}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_hukdis` int DEFAULT NULL,
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
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis` (`id_riwayat_hukdis`),
              KEY `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis` (`id_tingkat_hukdis`),
              KEY `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis` (`id_jenis_hukdis`),
              KEY `fk_id_pangkat_peg_hukdis_to_pangkat` (`id_pangkat`),
              CONSTRAINT `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis` FOREIGN KEY (`id_jenis_hukdis`) REFERENCES {{jenis_hukdis}} (`id_jenis_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_pangkat_peg_hukdis_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis` FOREIGN KEY (`id_tingkat_hukdis`) REFERENCES {{tingkat_hukdis}} (`id_tingkat_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_peghukdis_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_ak' => <<<'SQL'
            CREATE TABLE {{pegawai_ak}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_ak` int DEFAULT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
              `jenis_ak` tinyint NOT NULL DEFAULT '2' COMMENT '1: Starting AK, 2: Pengajuan Riwayat AK',
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
              `nilai_ak` double NOT NULL DEFAULT '0' COMMENT 'Nilai Final (Terakhir + Pengajuan)',
              `nama_pym` varchar(150) DEFAULT NULL COMMENT 'Nama Pejabat Yang Menetapkan',
              `jab_pym` varchar(256) DEFAULT NULL COMMENT 'Jabatan Pejabat Yang Menetapkan',
              `flag_instansi_ym` tinyint NOT NULL DEFAULT '0' COMMENT '0: Kemenparekraf, 1: Instansi Lain',
              `instansi_ym` varchar(256) DEFAULT NULL,
              `jabatan` varchar(256) DEFAULT NULL,
              `tmt_jab` date DEFAULT NULL,
              `mker_th_jab` tinyint DEFAULT NULL,
              `mker_bl_jab` tinyint DEFAULT NULL,
              `pangkat` varchar(256) DEFAULT NULL,
              `gol_ruang` varchar(256) DEFAULT NULL,
              `tmt_pang` date DEFAULT NULL,
              `mker_th_pang` tinyint DEFAULT NULL,
              `mker_bl_pang` tinyint DEFAULT NULL,
              `keterangan` tinytext,
              `file_pak` varchar(256) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `created_by` int DEFAULT NULL,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              PRIMARY KEY (`nip`),
              KEY `fk_id_jabatan_cak_to_jab` (`id_jabatan`),
              KEY `fk_id_pangkat_cak_to_pangkat` (`id_pangkat`),
              KEY `fk_id_riwayat_ak_cak_to_rak` (`id_riwayat_ak`) USING BTREE,
              CONSTRAINT `fk_id_pangkat_cak_to_pangkat` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_cak_to_peg` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_ak_siasn' => <<<'SQL'
            CREATE TABLE {{pegawai_ak_siasn}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_ak_siasn` bigint DEFAULT NULL,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `id_pns` varchar(100) DEFAULT NULL,
              `id_jabatan` int DEFAULT NULL,
              `id_pangkat` tinyint DEFAULT NULL,
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
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              `updated_by` int DEFAULT NULL,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_ak_siasn_peg_ak_siasn_02` (`id_riwayat_ak_siasn`),
              KEY `fk_id_jabatan_peg_ak_siasn_03` (`id_jabatan`),
              KEY `fk_id_pangkat_peg_ak_siasn_04` (`id_pangkat`),
              CONSTRAINT `fk_id_pangkat_peg_ak_siasn_04` FOREIGN KEY (`id_pangkat`) REFERENCES {{pangkat}} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_peg_ak_siasn_01` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_keluarga' => <<<'SQL'
            CREATE TABLE {{pegawai_keluarga}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_keluarga` int DEFAULT NULL,
              `urutan_perkawinan` tinyint NOT NULL DEFAULT '1',
              `tgl_perkawinan` date NOT NULL,
              `kota_perkawinan` varchar(255) DEFAULT NULL,
              `nama_pasangan` varchar(255) NOT NULL,
              `jumlah_anak` tinyint NOT NULL DEFAULT '0',
              `keterangan` tinytext,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga` (`id_riwayat_keluarga`),
              CONSTRAINT `fk_nip_pegkeluarga_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_alamat' => <<<'SQL'
            CREATE TABLE {{pegawai_alamat}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_alamat` int NOT NULL,
              `id_provinsi` char(2) DEFAULT NULL,
              `id_kabupaten_kota` char(4) DEFAULT NULL,
              `id_kecamatan` char(7) DEFAULT NULL,
              `id_kelurahan` char(10) DEFAULT NULL,
              `alamat_utama` tinyint(1) NOT NULL DEFAULT '2' COMMENT '1: Ya, 2: Tidak',
              `jenis_alamat` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Alamat KTP, 2: Alamat Domisili',
              `provinsi` varchar(255) DEFAULT NULL,
              `provinsi_lain` varchar(255) DEFAULT NULL,
              `kabupaten_kota` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lain` varchar(255) DEFAULT NULL,
              `kecamatan` varchar(255) DEFAULT NULL,
              `kecamatan_lain` varchar(255) DEFAULT NULL,
              `kelurahan` varchar(255) DEFAULT NULL,
              `kelurahan_lain` varchar(255) DEFAULT NULL,
              `kd_pos` char(5) DEFAULT NULL,
              `alamat` tinytext,
              `keterangan` tinytext,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_alamat_pegalamat_riwalamat` (`id_riwayat_alamat`),
              KEY `fk_id_provinsi_pegalamat_provinsi` (`id_provinsi`),
              KEY `fk_id_kabupaten_kota_pegalamat_kabkota` (`id_kabupaten_kota`),
              KEY `fk_id_kecamatan_pegalamat_kecamatan` (`id_kecamatan`),
              KEY `fk_id_kelurahan_pegalamat_kelurahan` (`id_kelurahan`),
              CONSTRAINT `fk_id_kabupaten_kota_pegalamat_kabkota` FOREIGN KEY (`id_kabupaten_kota`) REFERENCES {{kabupaten_kota}} (`id_kabupaten_kota`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_kecamatan_pegalamat_kecamatan` FOREIGN KEY (`id_kecamatan`) REFERENCES {{kecamatan}} (`id_kecamatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_kelurahan_pegalamat_kelurahan` FOREIGN KEY (`id_kelurahan`) REFERENCES {{kelurahan}} (`id_kelurahan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_id_provinsi_pegalamat_provinsi` FOREIGN KEY (`id_provinsi`) REFERENCES {{provinsi}} (`id_provinsi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_pegalamat_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_alamat_kantor' => <<<'SQL'
            CREATE TABLE {{pegawai_alamat_kantor}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_alamat` int NOT NULL,
              `id_provinsi` char(2) DEFAULT NULL,
              `id_kabupaten_kota` char(4) DEFAULT NULL,
              `id_kecamatan` char(7) DEFAULT NULL,
              `id_kelurahan` char(10) DEFAULT NULL,
              `provinsi` varchar(255) DEFAULT NULL,
              `provinsi_lain` varchar(255) DEFAULT NULL,
              `kabupaten_kota` varchar(255) DEFAULT NULL,
              `kabupaten_kota_lain` varchar(255) DEFAULT NULL,
              `kecamatan` varchar(255) DEFAULT NULL,
              `kecamatan_lain` varchar(255) DEFAULT NULL,
              `kelurahan` varchar(255) DEFAULT NULL,
              `kelurahan_lain` varchar(255) DEFAULT NULL,
              `kd_pos` char(5) DEFAULT NULL,
              `alamat` tinytext,
              `nama_kantor` varchar(255) DEFAULT NULL,
              `lantai` varchar(50) DEFAULT NULL,
              `telp` varchar(50) DEFAULT NULL,
              `faks` varchar(50) DEFAULT NULL,
              `keterangan` tinytext,
              PRIMARY KEY (`nip`) USING BTREE,
              KEY `fk_id_riwayat_alamat_pegalamat_riwalamat` (`id_riwayat_alamat`) USING BTREE,
              KEY `fk_id_provinsi_pegalamat_provinsi` (`id_provinsi`) USING BTREE,
              KEY `fk_id_kabupaten_kota_pegalamat_kabkota` (`id_kabupaten_kota`) USING BTREE,
              KEY `fk_id_kecamatan_pegalamat_kecamatan` (`id_kecamatan`) USING BTREE,
              KEY `fk_id_kelurahan_pegalamat_kelurahan` (`id_kelurahan`) USING BTREE,
              CONSTRAINT `pegawai_alamat_kantor_ibfk_1` FOREIGN KEY (`id_kabupaten_kota`) REFERENCES {{kabupaten_kota}} (`id_kabupaten_kota`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `pegawai_alamat_kantor_ibfk_2` FOREIGN KEY (`id_kecamatan`) REFERENCES {{kecamatan}} (`id_kecamatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `pegawai_alamat_kantor_ibfk_3` FOREIGN KEY (`id_kelurahan`) REFERENCES {{kelurahan}} (`id_kelurahan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `pegawai_alamat_kantor_ibfk_4` FOREIGN KEY (`id_provinsi`) REFERENCES {{provinsi}} (`id_provinsi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `pegawai_alamat_kantor_ibfk_6` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_tanda_jasa' => <<<'SQL'
            CREATE TABLE {{pegawai_tanda_jasa}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_tanda_jasa` int DEFAULT NULL,
              `id_tanda_jasa` int DEFAULT NULL,
              `tgl_sertifikat` date NOT NULL,
              `no_sertifikat` varchar(100) DEFAULT NULL,
              `tanda_jasa` varchar(255) NOT NULL,
              `negara` varchar(255) DEFAULT NULL,
              `keterangan` tinytext,
              `id_rw_siasn` varchar(100) DEFAULT NULL,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_tanda_jasa_ptj_to_rtj` (`id_riwayat_tanda_jasa`),
              KEY `fk_id_tanda_jasa_pegtj_to_tj` (`id_tanda_jasa`),
              CONSTRAINT `fk_id_tanda_jasa_pegtj_to_tj` FOREIGN KEY (`id_tanda_jasa`) REFERENCES {{tanda_jasa}} (`id_tanda_jasa`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `fk_nip_pegtj_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        'pegawai_mutasi_jabatan' => <<<'SQL'
            CREATE TABLE {{pegawai_mutasi_jabatan}} (
              `nip` varchar(30) NOT NULL,
              `id_riwayat_mutasi_jabatan` int DEFAULT NULL,
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
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`nip`),
              KEY `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj` (`id_riwayat_mutasi_jabatan`),
              KEY `fk_id_group_jabatan_pmj_to_gj` (`id_group_jabatan`),
              KEY `fk_id_sub_group_jabatan_pmj_to_sgj` (`id_sub_group_jabatan`),
              KEY `fk_id_unit_pmj_to_unit` (`id_unit`),
              KEY `fk_id_satker_pmj_to_satker` (`id_satker`),
              KEY `fk_id_atasan_es_1_pmj_to_jabatan` (`id_atasan_es_1`),
              KEY `fk_id_atasan_es_2_pmj_to_jabatan` (`id_atasan_es_2`),
              KEY `fk_id_atasan_es_3_pmj_to_jabatan` (`id_atasan_es_3`),
              KEY `fk_id_atasan_es_4_pmj_to_jabatan` (`id_atasan_es_4`),
              KEY `fk_id_jabatan_pmj_to_jabatan` (`id_jabatan`),
              KEY `fk_id_atasan_es_3_koord_pmj_to_jabkoor` (`id_atasan_es_3_koord`),
              KEY `fk_id_atasan_es_4_koord_pmj_to_jabkoor` (`id_atasan_es_4_koord`),
              KEY `fk_id_jabatan_koord_pmj_to_jabkoor` (`id_jabatan_koord`),
              KEY `pegawai_mutasi_jabatan_ibfk_01` (`id_gol_pppk`),
              KEY `pegawai_mutasi_jabatan_ibfk_02` (`id_rumpun_jabatan`),
              CONSTRAINT `fk_nip_pmj_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {{pegawai}} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT `pegawai_mutasi_jabatan_ibfk_01` FOREIGN KEY (`id_gol_pppk`) REFERENCES {{gol_pppk}} (`id_gol_pppk`) ON DELETE RESTRICT ON UPDATE RESTRICT
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

            throw new RuntimeException('DBV-012: DDL CreatePegawaiSnapshot gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
