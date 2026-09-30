<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 3): periode e-Kinerja BKN, riwayat SKP tahunan, SKP periodik BKN, dan laporan kinerja
 * harian (LKH) dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1).
 * Sumber per tabel:
 *   - `bkn_periode_ekinper` = DDL produksi `simpeg_prod.sql:270-284` [K] persis.
 *   - `riwayat_skp` = kode CI3 [I]: `libraries/hr/rwy/L_skp.php:197-300, 625-662` (`$param = $postData`),
 *     `:745-765` (approval), `controllers/hr/services/Siasn.php:7016-7027, 7075-7101, 7777-7825` (sinkron SIASN).
 *     DDL lokal hanya stub 5 kolom [L]. Nama FK dari ERD simpeg01 [K-erd].
 *   - `riwayat_skp_periodik` = kode CI3 [I]: `controllers/hr/services/Ekin_bkn.php:263-340` (cron sinkron API
 *     e-Kinerja BKN), `libraries/hr/rwy/L_skp.php:772-960` (daftar, detail, `f_arsip_1`), `L_chart.php:4852`. Tanpa FK
 *     di ERD.
 *   - `riwayat_lckh` = DDL arsip hapus `d_lkh` `simpeg_prod.sql:673-701` [K-m] (trigger `INSERT INTO d_x SELECT *`
 *     mensyaratkan kolom identik) + PK `id_riwayat_lckh` INT AUTO_INCREMENT (dirujuk FK `aa_lkh` [K] :23-28). Nama FK
 *     `fk_riwayat_lckh_ibfk_01..04` [K-erd].
 * Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.3) — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-013.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - Semua FK ON DELETE RESTRICT ON UPDATE RESTRICT (K1; legacy `nip` CASCADE/CASCADE).
 *   - `riwayat_skp.nip` NOT NULL (stub [L] DEFAULT NULL); status TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/3/10 (stub [L]
 *     VARCHAR(5) DEFAULT '1'; 3 "Diproses" = opsi form admin legacy); `created_at` [I] (pola absen_ijin/d_lkh).
 *   - `riwayat_skp_periodik.status` DEFAULT 1 (satu-satunya penulis, cron BKN, selalu menulis '1') + CHECK 0/1/2/10.
 *   - `riwayat_lckh`: PK + AUTO_INCREMENT (tabel arsip `d_lkh` tidak ber-PK); CHECK status 0/1/2/3/10; COMMENT status
 *     v2 (legacy `'0: Wating, 1: Approved, 2: Rejected, 3: Revisi'`); KEY [V2] `idx_riwayat_lckh_nip_tgl`,
 *     `idx_riwayat_lckh_atasan_status`; FK `nip_atasan` tetap NULLable ([K-m] DEFAULT NULL).
 *   - Trigger arsip `d_lkh` tidak ditiru (jejak lewat `audit_logs`).
 *   - FK G-02 `fk_riwayat_lckh_ibfk_03` (unit) dan `_04` (satker) BELUM dipasang: KEY bernama FK dibuat di sini,
 *     constraint ditambahkan migration `AddFkG02Riwayat` (ditahan sampai DBV-008 merge).
 *   - Kolom NIP tanpa FK (daftar "NIP non-FK" registry B-06): `riwayat_skp.nip_penilai`, `nip_atasan_penilai`,
 *     `riwayat_skp_periodik.nip`, `pegawai_atasan_nip`.
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT awal tidak
 * ditulis. DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila
 * salah satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang
 * error. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatSkpLkh extends Migration
{
    private const STATUS_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 10: Dihapus';

    /**
     * Status riwayat ber-approval: + 3 "Diproses" yang ditulis form admin legacy (views/hr/employee/rwy/skp/form.php:83).
     */
    private const STATUS_WF_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan untuk down() (anak → induk). Keempat tabel tidak saling merujuk; semuanya anak `pegawai` kecuali
     * `bkn_periode_ekinper` (relasi logis `riwayat_skp_periodik.periode_id` tanpa FK).
     */
    private const TABLES = ['riwayat_lckh', 'riwayat_skp_periodik', 'riwayat_skp', 'bkn_periode_ekinper'];

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
     * CREATE TABLE per tabel, urut induk → anak.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $status   = "COMMENT '" . self::STATUS_COMMENT . "'";
        $statusWf = "COMMENT '" . self::STATUS_WF_COMMENT . "'";
        $audit    = $this->auditColumnsSql();
        $workflow = $this->workflowColumnsSql();
        $pegawai  = $this->t('pegawai');
        $sql      = [];

        // [K] simpeg_prod.sql:270-284 persis (termasuk tahun YEAR, periode_* VARCHAR(10) format string API BKN, audit
        // created_at/updated_at tanpa *_by). Diisi cron `Ekin_bkn::cron_periode_skp` (Ekin_bkn.php:9-54).
        $sql['bkn_periode_ekinper'] = "CREATE TABLE {$this->t('bkn_periode_ekinper')} (
            `id` VARCHAR(150) NOT NULL,
            `nama` VARCHAR(150) NOT NULL,
            `tahun` YEAR NOT NULL,
            `bulan` VARCHAR(5) NULL DEFAULT NULL,
            `periode_awal` VARCHAR(10) NULL DEFAULT NULL,
            `periode_akhir` VARCHAR(10) NULL DEFAULT NULL,
            `batas_pengisian` VARCHAR(10) NULL DEFAULT NULL,
            `jenis_periode` VARCHAR(150) NULL DEFAULT NULL,
            `status` TINYINT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tahun_bulan_jenis_periode_status` (`tahun`, `bulan`, `jenis_periode`, `status`)
        ) " . self::TABLE_OPTIONS;

        // [I] L_skp.php:625-662 + Siasn.php (sinkron). Tahun >= 2022 memakai rating_*, < 2022 memakai nilai_* (L_skp.php:638-656).
        // Status 3 "Diproses" ditawarkan form admin legacy (views/hr/employee/rwy/skp/form.php:83) → masuk domain CHECK
        // (dokumen DBV Bagian 4, keputusan status riwayat).
        $sql['riwayat_skp'] = "CREATE TABLE {$this->t('riwayat_skp')} (
            `id_riwayat_skp` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `tahun` SMALLINT NOT NULL COMMENT 'tahun penilaian; >= 2022 memakai rating_*, < 2022 memakai nilai_*',
            `tgl_mulai` DATE NOT NULL,
            `tgl_akhir` DATE NOT NULL,
            `nip_penilai` VARCHAR(30) NULL DEFAULT NULL COMMENT 'NIP pejabat penilai; tanpa FK (legacy)',
            `nama_penilai` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan_penilai` VARCHAR(255) NULL DEFAULT NULL,
            `nip_atasan_penilai` VARCHAR(30) NULL DEFAULT NULL COMMENT 'NIP atasan pejabat penilai; tanpa FK (legacy)',
            `nama_atasan_penilai` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan_atasan_penilai` VARCHAR(255) NULL DEFAULT NULL,
            `rating_skp` TINYINT NULL DEFAULT NULL COMMENT '1: Di Bawah Ekspektasi, 2: Sesuai Ekspektasi, 3: Di Atas Ekspektasi',
            `rating_perilaku` TINYINT NULL DEFAULT NULL COMMENT '1: Di Bawah Ekspektasi, 2: Sesuai Ekspektasi, 3: Di Atas Ekspektasi',
            `nilai_skp` DECIMAL(6,2) NULL DEFAULT NULL,
            `nilai_skp_60_persen` DECIMAL(6,2) NULL DEFAULT NULL,
            `nilai_perilaku` DECIMAL(6,2) NULL DEFAULT NULL,
            `nilai_perilaku_40_persen` DECIMAL(6,2) NULL DEFAULT NULL,
            `nilai_prestasi_kerja` DECIMAL(6,2) NULL DEFAULT NULL,
            `kategori_nilai_prestasi` VARCHAR(50) NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Belum dikirim, 1: Terkirim, 2: Gagal, 3: Data tidak valid',
            `siasn_error` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 0 {$statusWf},
            {$workflow},
            {$audit},
            PRIMARY KEY (`id_riwayat_skp`),
            KEY `fk_nip_rwyskp_to_pegawai` (`nip`),
            CONSTRAINT `fk_nip_rwyskp_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_riwayat_skp_status` CHECK (`status` IN (0, 1, 2, 3, 10))
        ) " . self::TABLE_OPTIONS;

        // [I] Ekin_bkn.php:263-297 (salinan respons API e-Kinerja BKN; tipe string mengikuti bkn_periode_ekinper [K]).
        // nip & pegawai_atasan_nip dari API: tanpa FK (bisa berisi NIP yang belum/tidak ada di pegawai).
        $sql['riwayat_skp_periodik'] = "CREATE TABLE {$this->t('riwayat_skp_periodik')} (
            `id_riwayat_skp_periodik` INT NOT NULL AUTO_INCREMENT,
            `id_pns` VARCHAR(100) NULL DEFAULT NULL COMMENT 'id pegawai di e-Kinerja BKN (field id respons API)',
            `periode_id` VARCHAR(150) NULL DEFAULT NULL COMMENT 'relasi logis ke bkn_periode_ekinper.id (tanpa FK)',
            `skp_id` VARCHAR(150) NULL DEFAULT NULL,
            `skp_penilaian_id` VARCHAR(150) NULL DEFAULT NULL,
            `jenis` VARCHAR(100) NULL DEFAULT NULL,
            `tahun_skp` VARCHAR(10) NULL DEFAULT NULL,
            `nip` VARCHAR(30) NOT NULL COMMENT 'NIP dari API BKN; tanpa FK',
            `nama` VARCHAR(255) NULL DEFAULT NULL,
            `periode_awal_skp` VARCHAR(30) NULL DEFAULT NULL,
            `periode_akhir_skp` VARCHAR(30) NULL DEFAULT NULL,
            `skp_unor_id` VARCHAR(150) NULL DEFAULT NULL,
            `skp_unor` VARCHAR(255) NULL DEFAULT NULL,
            `skp_unor_induk` VARCHAR(255) NULL DEFAULT NULL,
            `skp_jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `skp_jenis_jabatan` VARCHAR(100) NULL DEFAULT NULL,
            `is_skp_plt_plh_pjb` VARCHAR(10) NULL DEFAULT NULL,
            `hasil_kerja` VARCHAR(100) NULL DEFAULT NULL,
            `perilaku_kerja` VARCHAR(100) NULL DEFAULT NULL,
            `hasil_akhir` VARCHAR(100) NULL DEFAULT NULL,
            `pegawai_atasan_id` VARCHAR(150) NULL DEFAULT NULL,
            `pegawai_atasan_nip` VARCHAR(30) NULL DEFAULT NULL COMMENT 'NIP atasan dari API BKN; tanpa FK',
            `pegawai_atasan_nama` VARCHAR(255) NULL DEFAULT NULL,
            `pegawai_atasan_unor_id` VARCHAR(150) NULL DEFAULT NULL,
            `pegawai_atasan_unor` VARCHAR(255) NULL DEFAULT NULL,
            `pegawai_atasan_jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `pegawai_atasan_golru` VARCHAR(20) NULL DEFAULT NULL,
            `waktu_dinilai` VARCHAR(30) NULL DEFAULT NULL,
            `pegawai_penilai_id` VARCHAR(150) NULL DEFAULT NULL,
            `golru` VARCHAR(20) NULL DEFAULT NULL,
            `f_arsip_1` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path arsip SKP periodik (unggah admin, L_skp.php:893)',
            `keterangan` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_riwayat_skp_periodik`),
            KEY `idx_riwayat_skp_periodik_periode_nip` (`periode_id`, `nip`),
            KEY `idx_riwayat_skp_periodik_nip` (`nip`),
            CONSTRAINT `chk_riwayat_skp_periodik_status` CHECK (`status` IN (0, 1, 2, 10))
        ) " . self::TABLE_OPTIONS;

        // [K-m] d_lkh simpeg_prod.sql:673-701 persis + PK. kegiatan/output/jumlah_diselesaikan/jam_* berisi array JSON
        // (L_lkh.php:924-944). nip_atasan diisi form (L_lkh.php:904) dan bisa kosong → NULL (FK nullable).
        $sql['riwayat_lckh'] = "CREATE TABLE {$this->t('riwayat_lckh')} (
            `id_riwayat_lckh` INT NOT NULL AUTO_INCREMENT,
            `id_unit` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `nip` VARCHAR(30) NOT NULL,
            `tgl_laporan` DATE NOT NULL,
            `nama` VARCHAR(150) NOT NULL,
            `unit` VARCHAR(150) NULL DEFAULT NULL,
            `satker` VARCHAR(150) NULL DEFAULT NULL,
            `nip_atasan` VARCHAR(30) NULL DEFAULT NULL,
            `nama_atasan` VARCHAR(150) NOT NULL,
            `kegiatan` MEDIUMTEXT NOT NULL,
            `output` MEDIUMTEXT NOT NULL,
            `jumlah_diselesaikan` MEDIUMTEXT NULL,
            `jam_mulai` MEDIUMTEXT NULL,
            `jam_selesai` MEDIUMTEXT NULL,
            `catatan` TEXT NULL,
            `file_lckh` VARCHAR(250) NULL DEFAULT NULL,
            `status` INT NOT NULL DEFAULT 0 COMMENT '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Revisi, 10: Dihapus',
            `auto_approval` TINYINT(1) NULL DEFAULT 2 COMMENT '1: Yes, 2: No',
            `status_regen` TINYINT(1) NULL DEFAULT 0 COMMENT '0: Normal State, 1: Process State',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL,
            `approved_by` INT NULL DEFAULT NULL,
            `show_notif` INT NOT NULL DEFAULT 0,
            `show_atasan` INT NOT NULL DEFAULT 0,
            `show_history_atasan` INT NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_riwayat_lckh`),
            KEY `fk_riwayat_lckh_ibfk_01` (`nip`),
            KEY `fk_riwayat_lckh_ibfk_02` (`nip_atasan`),
            KEY `fk_riwayat_lckh_ibfk_03` (`id_unit`),
            KEY `fk_riwayat_lckh_ibfk_04` (`id_satker`),
            KEY `idx_riwayat_lckh_nip_tgl` (`nip`, `tgl_laporan`),
            KEY `idx_riwayat_lckh_atasan_status` (`nip_atasan`, `status`),
            CONSTRAINT `fk_riwayat_lckh_ibfk_01` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_riwayat_lckh_ibfk_02` FOREIGN KEY (`nip_atasan`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_riwayat_lckh_status` CHECK (`status` IN (0, 1, 2, 3, 10))
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen DBV Bagian 6.6).
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
     * Blok workflow approval riwayat [I] (kolom yang dibaca notifikasi `L_notification.php:3350-3425` dan ditulis
     * `L_skp.php:745-765`): reason_note, approved_by, show_notif, notif_date, show_ua_*. Tipe mengikuti konvensi
     * bersama B-01/B-02 (keputusan tipe TINYINT vs INT masih terbuka di dokumen DBV).
     */
    private function workflowColumnsSql(): string
    {
        return '`reason_note` TEXT NULL, '
            . "`approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang menyetujui/menolak', "
            . "`show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'penanda notifikasi pegawai (nilai legacy), diisi aplikasi', "
            . '`notif_date` DATETIME NULL DEFAULT NULL, '
            . "`show_ua_upt` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin UPT (satker)', "
            . "`show_ua_deputi` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin deputi (unit)', "
            . "`show_ua_biro` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin biro'";
    }

    /**
     * Kolom audit tabel riwayat [I] (pola absen_ijin [K] / d_lkh [K-m]): created_at, updated_at ON UPDATE, updated_by =
     * id_pengguna aktor tanpa FK (preseden DBV-001/010).
     */
    private function auditColumnsSql(): string
    {
        return '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . '`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
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

            throw new RuntimeException('DBV-013: DDL B-02 SKP/LKH gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
