<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 4): permohonan kartu pegawai `riwayat_karpeg` dan kartu istri/suami `riwayat_kariskarsu`
 * (layanan B-17), riwayat tanda jasa `riwayat_tanda_jasa` (B-17) dan riwayat organisasi `riwayat_organisasi` (B-17),
 * dengan skema SIMPEG legacy. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.4) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber (label dokumen B-01/B-02):
 *   - `riwayat_karpeg`: stub [L] `simpeg_prod_duplikat` (6 kolom, termasuk `rated`); kode [I] `L_karpeg.php:160-360`
 *     (unggah `file_*`), `:882-890` (`sp`, `$param = $postData`), `:1011` (`file_tanda_terima`), `:1077-1085`
 *     (`sp_process`); controller `controllers/hr/rwy/Karpeg.php:77-78` (nip + status 0); `rated` dibaca
 *     `core/MY_Controller.php:488-523` (`status='1' AND rated='2'`), ditulis `controllers/hr/Announcement.php:683, 710`;
 *     `notif_date` dibaca `L_notification.php:4120-4127`; `created_at` dipakai nomor tiket (`L_karpeg.php:100, 960`).
 *   - `riwayat_kariskarsu`: sama dengan karpeg (`L_kariskarsu.php`), tanpa `jenis_permohonan`, ditambah `file_alasan`.
 *   - `riwayat_tanda_jasa`: stub [L]; kode [I] `L_tj.php:491-505` (`set_param`, `$param = $postData` → termasuk field
 *     form `negara`), approval `:591-610`; sinkron SIASN `Siasn.php:6045-6070` (insert `negara` = 'Indonesia'),
 *     `siasn_sync/Rw_tj.php:420-581` (`id_rw_siasn`, `siasn_flag`, `siasn_error`); nama FK [K-erd].
 *   - `riwayat_organisasi`: stub [L]; kode [I] `L_organisasi.php:512-522` (`set_param`), approval `:606-625`.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `nip` NOT NULL (stub [L] DEFAULT NULL); status TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/10 (stub [L] VARCHAR(5)
 *     DEFAULT '1'); FK RESTRICT/RESTRICT (legacy/seed: `nip` CASCADE/CASCADE).
 *   - `rated` TINYINT NOT NULL DEFAULT 2 mengikuti pola [K-m] `d_riwayat_cuti.rated` (stub [L]: VARCHAR(5) DEFAULT NULL
 *     — dengan default NULL pop-up survei legacy `rated='2'` tidak pernah muncul, jadi stub diragukan).
 *   - `created_at` [I], `updated_at` NULL ON UPDATE (stub [L]: NOT NULL tanpa ON UPDATE); blok workflow TINYINT
 *     (keputusan dokumen B-01/B-02 #3 masih terbuka).
 *   - `riwayat_tanda_jasa.negara` [I] (tidak ada di rencana); `tgl_sertifikat` dan `riwayat_organisasi.tgl_mulai`
 *     DATE NOT NULL [I] (wajib di form; SIASN selalu mengisi `tgl_sertifikat`).
 *   - Tidak meniru trigger `d_*`: jejak perubahan lewat `audit_logs`.
 *
 * Kolom audit/workflow diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT
 * awal tidak ditulis (impor memakai ID legacy apa adanya, karena `document_attachment.id_entri` dan nomor tiket
 * karpeg/kariskarsu merujuk ID lama).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatKartuTjOrganisasi extends Migration
{
    private const STATUS_RIWAYAT_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 10: Dihapus';

    /**
     * Domain status permohonan karpeg/kariskarsu (dokumen B-01/B-02 2.0.3): 0/1/2/10.
     */
    private const STATUS_RIWAYAT_DOMAIN = '0, 1, 2, 10';

    /**
     * `riwayat_tanda_jasa`, `riwayat_organisasi`: + 3 "Diproses" yang ditulis form admin legacy
     * (views/hr/employee/rwy/tj/form.php:68, organisasi/form.php:68).
     */
    private const STATUS_DIPROSES_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    private const STATUS_DIPROSES_DOMAIN = '0, 1, 2, 3, 10';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    /**
     * Urutan down(): kebalikan urutan pembuatan (tidak ada FK di antara keempat tabel ini).
     */
    private const TABLES = ['riwayat_organisasi', 'riwayat_tanda_jasa', 'riwayat_kariskarsu', 'riwayat_karpeg'];

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

        // Permohonan layanan: berkas di kolom path (arsip/{nip}/karpeg/…), bukan document_attachment. Diajukan pegawai
        // (status 0), diproses petugas layanan (1/2); hapus legacy = hard delete (L_karpeg.php:736).
        $sql['riwayat_karpeg'] = "CREATE TABLE {$this->t('riwayat_karpeg')} (
            `id_riwayat_karpeg` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `jenis_permohonan` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Permohonan Pertama / Karena Kehilangan',
            `file_1` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path SK CPNS',
            `file_2` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path SK PNS',
            `file_3` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path SPMT',
            `file_4` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path sertifikat prajabatan',
            `file_5` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path pas foto',
            `file_surat_hilang` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path surat kehilangan (jenis Karena Kehilangan)',
            `file_tanda_terima` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path PDF tanda terima, dibuat saat disetujui',
            {$this->layananWorkflowAuditSql()},
            PRIMARY KEY (`id_riwayat_karpeg`),
            KEY `fk_nip_rkarpeg_to_pegawai` (`nip`),
            CONSTRAINT `fk_nip_rkarpeg_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            {$this->statusCheckSql('riwayat_karpeg')}
        ) " . self::TABLE_OPTIONS;

        $sql['riwayat_kariskarsu'] = "CREATE TABLE {$this->t('riwayat_kariskarsu')} (
            `id_riwayat_kariskarsu` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `file_1` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path SK CPNS',
            `file_2` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path SK PNS',
            `file_3` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path buku nikah',
            `file_4` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path laporan perkawinan',
            `file_5` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path pas foto',
            `file_surat_hilang` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path surat kehilangan',
            `file_alasan` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path akta kematian / surat cerai',
            `file_tanda_terima` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path PDF tanda terima, dibuat saat disetujui',
            {$this->layananWorkflowAuditSql()},
            PRIMARY KEY (`id_riwayat_kariskarsu`),
            KEY `fk_nip_rkariskarsu_to_pegawai` (`nip`),
            CONSTRAINT `fk_nip_rkariskarsu_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            {$this->statusCheckSql('riwayat_kariskarsu')}
        ) " . self::TABLE_OPTIONS;

        // Kolom bisnis = snapshot pegawai_tanda_jasa (kelompok 1) + negara/keterangan (dibaca snapshot di simapi
        // A_employee.php:2112-2131, lihat dokumen kelompok 4 koordinasi). id_tanda_jasa 44 = LAIN-LAIN (tanda_jasa_lain);
        // 26/27/28 = Satyalancana Karya Satya (DRH L_employee.php:10807-10882, sinkron SIASN Rw_tj.php:325, 393).
        $sql['riwayat_tanda_jasa'] = "CREATE TABLE {$this->t('riwayat_tanda_jasa')} (
            `id_riwayat_tanda_jasa` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `id_tanda_jasa` INT NULL DEFAULT NULL COMMENT '44 = LAIN-LAIN (nama di tanda_jasa_lain)',
            `tanda_jasa` VARCHAR(255) NULL DEFAULT NULL COMMENT 'salinan tanda_jasa.tanda_jasa saat pengajuan',
            `tanda_jasa_lain` VARCHAR(255) NULL DEFAULT NULL,
            `tgl_sertifikat` DATE NOT NULL,
            `no_sertifikat` VARCHAR(100) NULL DEFAULT NULL,
            `negara` VARCHAR(100) NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NOT NULL DEFAULT 0 COMMENT 'sinkron SIASN legacy: 0 belum, 1 sukses, 2 proses/gagal (lihat siasn_error), 3 fallback manual',
            `siasn_error` TEXT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_tanda_jasa`),
            KEY `fk_nip_rwytj_to_pegawai` (`nip`),
            KEY `fk_id_tanda_jasa_rtj_to_tj` (`id_tanda_jasa`),
            CONSTRAINT `fk_nip_rwytj_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            CONSTRAINT `fk_id_tanda_jasa_rtj_to_tj` FOREIGN KEY (`id_tanda_jasa`)
                REFERENCES {$this->t('tanda_jasa')} (`id_tanda_jasa`) " . self::RESTRICT . ",
            {$this->statusCheckSql('riwayat_tanda_jasa', self::STATUS_DIPROSES_DOMAIN)}
        ) " . self::TABLE_OPTIONS;

        // tgl_akhir NULL = masih aktif (form edit menonaktifkan isian tgl_akhir kosong, organisasi/form.php:106).
        $sql['riwayat_organisasi'] = "CREATE TABLE {$this->t('riwayat_organisasi')} (
            `id_riwayat_organisasi` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `nama_organisasi` VARCHAR(255) NULL DEFAULT NULL,
            `kedudukan` VARCHAR(255) NULL DEFAULT NULL COMMENT 'jabatan dalam organisasi',
            `tgl_mulai` DATE NOT NULL,
            `tgl_akhir` DATE NULL DEFAULT NULL COMMENT 'NULL = masih aktif',
            `keterangan` TEXT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_organisasi`),
            KEY `fk_nip_rwyorganisasi_to_pegawai` (`nip`),
            CONSTRAINT `fk_nip_rwyorganisasi_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            {$this->statusCheckSql('riwayat_organisasi', self::STATUS_DIPROSES_DOMAIN)}
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Status + blok workflow standar + audit riwayat (konvensi B-02 2.0.3/2.0.4), sama persis dengan kelompok 2/3.
     * Tipe TINYINT untuk `show_notif`/`show_ua_*` = stub [L] (`show_notif tinyint NOT NULL DEFAULT '0'`); bukti [K]
     * `absen_ijin` memakai INT (keputusan dokumen B-01/B-02 #3).
     */
    private function workflowAuditSql(): string
    {
        return "`status` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_DIPROSES_COMMENT . "',
            `reason_note` TEXT NULL,
            `approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang menyetujui/menolak',
            `show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'penanda notifikasi pegawai (nilai legacy), diisi aplikasi',
            `notif_date` DATETIME NULL DEFAULT NULL,
            `show_ua_upt` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin UPT (satker)',
            `show_ua_deputi` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin deputi (unit)',
            `show_ua_biro` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin biro',
            {$this->auditSql()}";
    }

    /**
     * Blok permohonan layanan karpeg/kariskarsu (konvensi B-02 2.0.3, pengecualian): hanya `show_ua_biro` (diproses
     * petugas layanan, `sp_process` selalu 1) + `rated` survei kepuasan layanan (pola [K-m] `d_riwayat_cuti.rated`).
     */
    private function layananWorkflowAuditSql(): string
    {
        return "`status` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_RIWAYAT_COMMENT . "',
            `reason_note` TEXT NULL,
            `approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang menyetujui/menolak',
            `show_ua_biro` TINYINT NULL DEFAULT NULL COMMENT '1: diproses petugas layanan',
            `show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'penanda notifikasi pegawai (nilai legacy), diisi aplikasi',
            `notif_date` DATETIME NULL DEFAULT NULL,
            `rated` TINYINT NOT NULL DEFAULT 2 COMMENT '0: Ignore, 1: Sudah, 2: Belum (survei kepuasan layanan)',
            {$this->auditSql()}";
    }

    /**
     * Audit riwayat [I] (konvensi B-02 2.0.4).
     */
    private function auditSql(): string
    {
        return "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    private function statusCheckSql(string $table, string $domain = self::STATUS_RIWAYAT_DOMAIN): string
    {
        return "CONSTRAINT `chk_{$table}_status` CHECK (`status` IN (" . $domain . '))';
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

            throw new RuntimeException('DBV-013: DDL riwayat kartu/tanda jasa/organisasi gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
