<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 2): riwayat kenaikan pangkat `riwayat_kp` (B-08) dan kenaikan gaji berkala `riwayat_kgb`
 * (B-09) dengan skema SIMPEG legacy. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.2) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber (label dokumen B-01/B-02):
 *   - `riwayat_kp`: stub [L] `simpeg_prod_duplikat` (5 kolom); kolom bisnis = snapshot `pegawai_kp` [L] (DDL lengkap:
 *     tipe, NOT NULL, panjang); penulisan kode [I] `L_kp.php:538-566` (`set_param`, `$param = $postData`), approval
 *     `L_kp.php:649-669`, sinkron SIASN `siasn_sync/Rw_gol.php:254-306, 390-408` (`tgl_pertek_bkn`, `no_pertek_bkn`,
 *     `jumlah_kredit_*`, `id_rw_siasn`); nama FK [K-erd]. Titik awal DDL: seed rekonstruksi
 *     `simpeg_v2_local_seed_legacy.sql:1639-1678` [I].
 *   - `riwayat_kgb`: kode [I] `L_kgb.php:508-526` (`sp`), `:622-648` (`sp_process`); `jym` = "Jabatan Yang
 *     Menandatangani" (`views/hr/employee/rwy/kgb/form.php`); `id_gol_pppk` hanya dari FK ERD `riwayat_kgb_ibfk_01`
 *     → `gol_pppk` [K-erd] (tidak ada titik tulis PHP). Tidak ada DDL [K]/[L] selain stub 5 kolom.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `nip` NOT NULL (stub [L] DEFAULT NULL); status TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/3/10 (stub [L] VARCHAR(5)
 *     DEFAULT '1'); FK RESTRICT/RESTRICT (seed: `nip` CASCADE/CASCADE, master SET NULL/CASCADE).
 *   - `created_at` [I], `updated_at` NULL ON UPDATE (stub [L]: NOT NULL tanpa ON UPDATE); blok workflow TINYINT
 *     (keputusan dokumen B-01/B-02 #3).
 *   - Duplikat `tmtsk` KP per `nip` (status bukan 2/10) ditolak aplikasi (`L_kp.php:523-531`), BUKAN UNIQUE: status
 *     2/10 boleh berulang. Aturan periode KP (TMT 1 April/1 Oktober) dan jarak KGB minimal 2 tahun juga di aplikasi
 *     (B-21), bukan CHECK.
 *   - Tidak meniru trigger `d_*`: jejak perubahan lewat `audit_logs`.
 *
 * Kolom audit/workflow diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT
 * awal tidak ditulis (impor memakai ID legacy apa adanya, karena `document_attachment.id_entri` merujuk ID riwayat).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatKpKgb extends Migration
{
    private const STATUS_RIWAYAT_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    /**
     * Domain status riwayat (dokumen B-01/B-02 2.0.3): 0/1/2/10 + 3 "Diproses" yang ditulis form admin legacy
     * (`views/hr/employee/rwy/<modul>/form.php:68`, opsi `value="3"`) dan tercatat di COMMENT [K-m] `d_riwayat_cuti`.
     */
    private const STATUS_RIWAYAT_DOMAIN = '0, 1, 2, 3, 10';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan down(): kebalikan urutan pembuatan (tidak ada FK di antara kedua tabel ini).
     */
    private const TABLES = ['riwayat_kgb', 'riwayat_kp'];

    public function up(): void
    {
        $created = [];

        try {
            foreach ($this->createStatements() as $table => $sql) {
                $this->exec($sql);
                $created[] = $table;
            }
        } catch (Throwable $e) {
            $this->dropCreated($created);

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}");
        }
    }

    /**
     * CREATE TABLE per tabel.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $sql = [];

        // Kolom bisnis = pegawai_kp [L] persis (snapshot disalin dari baris riwayat yang disetujui, sehingga tipe dan
        // NOT NULL harus sama). jenis_kp/gol/ruang/gol_ruang/pangkat = salinan teks master saat pengajuan.
        $sql['riwayat_kp'] = "CREATE TABLE {$this->t('riwayat_kp')} (
            `id_riwayat_kp` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `id_jenis_kp` TINYINT NULL DEFAULT NULL,
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `tmtsk` DATE NOT NULL,
            `tgl_sk` DATE NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_pertek_bkn` DATE NULL DEFAULT NULL,
            `no_pertek_bkn` VARCHAR(100) NULL DEFAULT NULL,
            `jumlah_kredit_utama` DOUBLE NULL DEFAULT NULL,
            `jumlah_kredit_tambahan` DOUBLE NULL DEFAULT NULL,
            `jenis_kp` VARCHAR(100) NOT NULL,
            `gol` VARCHAR(10) NOT NULL,
            `ruang` VARCHAR(10) NOT NULL,
            `gol_ruang` VARCHAR(10) NOT NULL,
            `pangkat` VARCHAR(50) NOT NULL,
            `mker_th` TINYINT NULL DEFAULT NULL,
            `mker_bl` TINYINT NULL DEFAULT NULL,
            `gaji_pokok` INT NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_kp`),
            KEY `fk_nip_rwykp_to_pegawai` (`nip`),
            KEY `fk_id_jenis_kp_rwy_kp_to_jenis_kp` (`id_jenis_kp`),
            KEY `fk_id_pangkat_rwy_kp_to_pangkat` (`id_pangkat`),
            CONSTRAINT `fk_nip_rwykp_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_jenis_kp_rwy_kp_to_jenis_kp` FOREIGN KEY (`id_jenis_kp`)
                REFERENCES {$this->t('jenis_kp')} (`id_jenis_kp`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_pangkat_rwy_kp_to_pangkat` FOREIGN KEY (`id_pangkat`)
                REFERENCES {$this->t('pangkat')} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            {$this->statusCheckSql('riwayat_kp')}
        ) " . self::TABLE_OPTIONS;

        // Kolom = L_kgb::sp() (urutan kode) + id_gol_pppk dari FK ERD. keterangan TINYTEXT seperti keluarga KP
        // (pegawai_kp [L]); snapshot pegawai_kgb wajib bertipe sama/lebih lebar (dokumen kelompok 2, keputusan D #8).
        $sql['riwayat_kgb'] = "CREATE TABLE {$this->t('riwayat_kgb')} (
            `id_riwayat_kgb` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `id_gol_pppk` TINYINT NULL DEFAULT NULL,
            `tmtsk` DATE NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sk` DATE NULL DEFAULT NULL,
            `mker_gol_th` TINYINT NULL DEFAULT NULL,
            `gaji_pokok` INT NULL DEFAULT NULL,
            `jym` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Jabatan Yang Menandatangani SK',
            `keterangan` TINYTEXT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_kgb`),
            KEY `fk_nip_rwykgb_to_pegawai` (`nip`),
            KEY `riwayat_kgb_ibfk_01` (`id_gol_pppk`),
            CONSTRAINT `fk_nip_rwykgb_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `riwayat_kgb_ibfk_01` FOREIGN KEY (`id_gol_pppk`)
                REFERENCES {$this->t('gol_pppk')} (`id_gol_pppk`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            {$this->statusCheckSql('riwayat_kgb')}
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Status + blok workflow standar + audit riwayat (konvensi B-02 2.0.3/2.0.4). Tipe TINYINT untuk `show_notif`/
     * `show_ua_*` = stub [L] (`show_notif tinyint NOT NULL DEFAULT '0'`); bukti [K] `absen_ijin` memakai INT (keputusan
     * dokumen B-01/B-02 #3).
     */
    private function workflowAuditSql(): string
    {
        return "`status` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_RIWAYAT_COMMENT . "',
            `reason_note` TEXT NULL,
            `approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang menyetujui/menolak',
            `show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'penanda notifikasi pegawai (nilai legacy), diisi aplikasi',
            `notif_date` DATETIME NULL DEFAULT NULL,
            `show_ua_upt` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin UPT (satker)',
            `show_ua_deputi` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin deputi (unit)',
            `show_ua_biro` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin biro',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    private function statusCheckSql(string $table): string
    {
        return "CONSTRAINT `chk_{$table}_status` CHECK (`status` IN (" . self::STATUS_RIWAYAT_DOMAIN . '))';
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen B-02 6.6).
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

            throw new RuntimeException('DBV-013: DDL riwayat KP/KGB gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
