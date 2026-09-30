<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 2): riwayat pendidikan `riwayat_pendidikan` (B-10), pelatihan `riwayat_diklat` (B-11), dan
 * kursus/seminar `riwayat_seminar` (B-11) dengan skema SIMPEG legacy. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.2) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber (label dokumen B-01/B-02):
 *   - `riwayat_pendidikan`: stub [L] (5 kolom); kode [I] `L_pendidikan.php:803-841` (`sp`, `$param = $postData`),
 *     `:939-965` (`sp_process`), sinkron SIASN `siasn_sync/Rw_pendidikan.php:146-172, 407-433` (termasuk `glr_awal`,
 *     `glr_akhir`, `id_rw_siasn`) dan `Siasn.php:5585-5590`; nama FK [K-erd]. Titik awal DDL: seed rekonstruksi
 *     `simpeg_v2_local_seed_legacy.sql:1757-1797` [I].
 *   - `riwayat_diklat`: stub [L] (5 kolom); kode [I] `L_diklat.php:626-684` (`set_param`), `:802-822` (approval),
 *     `Siasn.php:1450-1465, 1725, 1861-1862, 2010-2015, 4745-4760` (`nama_diklat_lain`, `id_rw_siasn`, `siasn_flag`,
 *     `siasn_error`); nama FK [K-erd] (`fk_id_diklat_rdiklat_to_diklat`, tanpa FK untuk sub group/rumpun/lembaga).
 *   - `riwayat_seminar`: stub [L] (5 kolom); kode [I] `L_seminar.php:512-531` (`set_param`, `$param = $postData`),
 *     `:614-634` (approval), `Siasn.php:4635-4662, 4905-4912`; `views/hr/employee/rwy/seminar/form.php:81-175`
 *     (jenis 1 Seminar / 2 Kursus; bidang & instansi = teks nama `bidang_kursem`/`instansi_kursem` atau isian
 *     "Lain-Lain", tanpa FK).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `nip` NOT NULL (stub [L] DEFAULT NULL); status TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/10 (stub [L] VARCHAR(5)
 *     DEFAULT '1'); FK RESTRICT/RESTRICT (seed: `nip` CASCADE/CASCADE, master SET NULL/CASCADE).
 *   - CHECK baru `jenis_diklat` 1-5 (domain `getJenisDiklat()`/opsi form legacy, sama dengan master `diklat`).
 *   - `created_at` [I], `updated_at` NULL ON UPDATE (stub [L]: NOT NULL tanpa ON UPDATE); blok workflow TINYINT
 *     (keputusan dokumen B-01/B-02 #3).
 *   - `tgl_sertifikat` NOT NULL untuk diklat dan seminar [I]: wajib di form dan selalu diisi sinkron SIASN.
 *   - Tidak meniru trigger `d_*`: jejak perubahan lewat `audit_logs`.
 *
 * Kolom audit/workflow diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT
 * awal tidak ditulis (impor memakai ID legacy apa adanya, karena `document_attachment.id_entri` merujuk ID riwayat).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatPendidikanDiklatSeminar extends Migration
{
    private const STATUS_RIWAYAT_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    /**
     * Domain status riwayat (dokumen B-01/B-02 2.0.3): 0/1/2/10 + 3 "Diproses" yang ditulis form admin legacy
     * (`views/hr/employee/rwy/<modul>/form.php:68`, opsi `value="3"`) dan tercatat di COMMENT [K-m] `d_riwayat_cuti`.
     */
    private const STATUS_RIWAYAT_DOMAIN = '0, 1, 2, 3, 10';

    private const SIASN_FLAG_COMMENT = 'sinkron SIASN legacy: 0 belum, 1 sukses, 2 gagal, 3 proses, 4 dilewati';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan down(): kebalikan urutan pembuatan (tidak ada FK di antara ketiga tabel ini).
     */
    private const TABLES = ['riwayat_seminar', 'riwayat_diklat', 'riwayat_pendidikan'];

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

        // Kolom = seed rekonstruksi (sama dengan snapshot pegawai_pendidikan + glr_awal/glr_akhir yang ditulis sinkron
        // SIASN). ID LAIN-LAIN 98 (bidang) dan 1185 (jurusan) di-hard-code legacy → wajib ada di impor master G-05.
        $sql['riwayat_pendidikan'] = "CREATE TABLE {$this->t('riwayat_pendidikan')} (
            `id_riwayat_pendidikan` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `id_jenjang_pendidikan` INT NULL DEFAULT NULL,
            `id_bidang_pendidikan` TINYINT NULL DEFAULT NULL COMMENT '98 = LAIN-LAIN (pakai bidang_pendidikan_lain)',
            `id_jurusan_pendidikan` INT NULL DEFAULT NULL COMMENT '1185 = LAIN-LAIN (pakai jurusan_pendidikan_lain)',
            `tgl_lulus` DATE NULL DEFAULT NULL,
            `no_ijazah` VARCHAR(100) NULL DEFAULT NULL,
            `no_sk_penc_glr` VARCHAR(100) NULL DEFAULT NULL,
            `institusi_pendidikan` VARCHAR(255) NULL DEFAULT NULL,
            `jenjang_pendidikan_singkat` VARCHAR(50) NULL DEFAULT NULL,
            `bidang_pendidikan` VARCHAR(100) NULL DEFAULT NULL,
            `bidang_pendidikan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `jurusan_pendidikan` VARCHAR(255) NULL DEFAULT NULL,
            `jurusan_pendidikan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `nem` DOUBLE NULL DEFAULT NULL COMMENT 'hanya jenjang 1-3 (SD/SLTP/SLTA)',
            `ipk` DOUBLE NULL DEFAULT NULL COMMENT 'hanya jenjang selain 1-3',
            `glr_awal` VARCHAR(50) NULL DEFAULT NULL,
            `glr_akhir` VARCHAR(50) NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_pendidikan`),
            KEY `fk_nip_rwypendidikan_to_pegawai` (`nip`),
            KEY `fk_id_jenjang_pendidikan_rpend_to_jpend` (`id_jenjang_pendidikan`),
            KEY `fk_id_bidang_pendidikan_rpend_to_bpend` (`id_bidang_pendidikan`),
            KEY `fk_id_jurusan_pendidikan_rpend_jupend` (`id_jurusan_pendidikan`),
            CONSTRAINT `fk_nip_rwypendidikan_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_jenjang_pendidikan_rpend_to_jpend` FOREIGN KEY (`id_jenjang_pendidikan`)
                REFERENCES {$this->t('jenjang_pendidikan')} (`id_jenjang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_bidang_pendidikan_rpend_to_bpend` FOREIGN KEY (`id_bidang_pendidikan`)
                REFERENCES {$this->t('bidang_pendidikan')} (`id_bidang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_jurusan_pendidikan_rpend_jupend` FOREIGN KEY (`id_jurusan_pendidikan`)
                REFERENCES {$this->t('jurusan_pendidikan')} (`id_jurusan_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            {$this->statusCheckSql('riwayat_pendidikan')}
        ) " . self::TABLE_OPTIONS;

        // id_diklat TINYINT = PK master diklat di main (G-06 1.4 #3). Kolom per jenis (L_diklat::set_param): 1/2/4 →
        // id_diklat + nama_diklat; 3 Fungsional → id_sub_group_jabatan + sub_group_jabatan; 5 Sertifikasi →
        // rumpun/lembaga sertifikasi + masa berlaku. id_sub_group_jabatan/id_rumpun_sertifikasi/id_lembaga_sertifikasi
        // tanpa FK (ERD tidak mencatat FK; master rumpun/lembaga sertifikasi belum ada di main).
        $sql['riwayat_diklat'] = "CREATE TABLE {$this->t('riwayat_diklat')} (
            `id_riwayat_diklat` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `jenis_diklat` TINYINT(1) NOT NULL COMMENT '1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan, 5: Sertifikasi',
            `id_diklat` TINYINT NULL DEFAULT NULL,
            `nama_diklat` VARCHAR(255) NULL DEFAULT NULL,
            `nama_diklat_lain` VARCHAR(255) NULL DEFAULT NULL,
            `id_sub_group_jabatan` INT NULL DEFAULT NULL COMMENT 'jenis 3 Fungsional; tanpa FK (legacy)',
            `sub_group_jabatan` VARCHAR(100) NULL DEFAULT NULL,
            `id_rumpun_sertifikasi` INT NULL DEFAULT NULL COMMENT 'jenis 5 Sertifikasi; tanpa FK (legacy)',
            `rumpun_sertifikasi` VARCHAR(255) NULL DEFAULT NULL,
            `id_lembaga_sertifikasi` INT NULL DEFAULT NULL COMMENT 'jenis 5 Sertifikasi; tanpa FK (legacy)',
            `instansi_penyelenggara` VARCHAR(255) NULL DEFAULT NULL,
            `deskripsi` TEXT NULL,
            `tgl_sertifikat` DATE NOT NULL,
            `no_sertifikat` VARCHAR(100) NULL DEFAULT NULL,
            `jumlah_jp` SMALLINT NULL DEFAULT NULL,
            `mb_sertifikat_awal` DATE NULL DEFAULT NULL,
            `mb_sertifikat_akhir` DATE NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::SIASN_FLAG_COMMENT . "',
            `siasn_error` TEXT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_diklat`),
            KEY `fk_nip_rwydiklat_to_pegawai` (`nip`),
            KEY `fk_id_diklat_rdiklat_to_diklat` (`id_diklat`),
            CONSTRAINT `fk_nip_rwydiklat_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_diklat_rdiklat_to_diklat` FOREIGN KEY (`id_diklat`)
                REFERENCES {$this->t('diklat')} (`id_diklat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            {$this->statusCheckSql('riwayat_diklat')},
            CONSTRAINT `chk_riwayat_diklat_jenis_diklat` CHECK (`jenis_diklat` BETWEEN 1 AND 5)
        ) " . self::TABLE_OPTIONS;

        // bidang_seminar/instansi_penyelenggara = teks (nama master kursem G-08 atau isian Lain-Lain), bukan id.
        $sql['riwayat_seminar'] = "CREATE TABLE {$this->t('riwayat_seminar')} (
            `id_riwayat_seminar` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `jenis_seminar` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Seminar, 2: Kursus',
            `bidang_seminar` VARCHAR(255) NULL DEFAULT NULL COMMENT 'teks nama bidang_kursem atau isian Lain-Lain',
            `nama_seminar` VARCHAR(255) NULL DEFAULT NULL,
            `tgl_sertifikat` DATE NOT NULL,
            `no_sertifikat` VARCHAR(100) NULL DEFAULT NULL,
            `jumlah_jp` SMALLINT NULL DEFAULT NULL,
            `instansi_penyelenggara` VARCHAR(255) NULL DEFAULT NULL COMMENT 'teks nama instansi_kursem atau isian Lain-Lain',
            `keterangan` TEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::SIASN_FLAG_COMMENT . "',
            `siasn_error` TEXT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_seminar`),
            KEY `fk_nip_rwyseminar_to_pegawai` (`nip`),
            CONSTRAINT `fk_nip_rwyseminar_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            {$this->statusCheckSql('riwayat_seminar')}
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

            throw new RuntimeException('DBV-013: DDL riwayat pendidikan/diklat/seminar gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
