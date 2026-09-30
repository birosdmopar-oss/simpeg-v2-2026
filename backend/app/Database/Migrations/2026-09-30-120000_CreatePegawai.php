<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-012 (1/2) — B-01: `pegawai`, `pegawai_hist`, `pegawai_foto` dengan skema SIMPEG legacy (Mapping Migrasi Prinsip
 * #1, Tier 2 "Copy langsung"). Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md
 * (Bagian 2.1) — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-012.
 *
 * Sumber per tabel:
 *   - `pegawai`: SHOW CREATE TABLE salinan lokal `simpeg_prod_duplikat` [L] (nama, tipe, NULL, default, urutan kolom,
 *     KEY `status`) + nama FK master dari ERD `simpeg01.erd` [K-erd]. `created_at` wajib ikut diimpor: laporan presensi
 *     legacy memfilter `peg.created_at` (L_presensi.php:8255).
 *   - `pegawai_hist`: kode legacy [I] — baris hist = salinan baris `pegawai` (`$param + $peg`, tanpa `deleted_at`)
 *     + `email` + kolom workflow (L_employee.php:431-490); proses setuju/tolak menyalin baris hist kembali ke `pegawai`
 *     setelah membuang kolom workflow (sp_process :1172-1178). `flag_update` 1 Diajukan / 2 Ditolak-dibatalkan /
 *     3 Disetujui (:445, :1022, :1175). Stub [L] 4 kolom bertentangan dengan kode, tidak dipakai. Nama FK [K-erd]
 *     `fk_peg_hist_ibfk_01..06`.
 *   - `pegawai_foto`: nama FK [K-erd] `fk_nip_pegfoto_to_peg`; kolom yang dibaca kode [I] (`id_pegawai_foto`, `nip`,
 *     `foto`: L_employee.php:44, views/hr/employee/form.php:894-895). Tidak ada titik tulis PHP (kemungkinan diisi
 *     trigger); `created_at` [V2]. Kolom lain menunggu SHOW CREATE TABLE produksi.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - Semua FK ON DELETE RESTRICT ON UPDATE RESTRICT (legacy [L]/seed: nip CASCADE/CASCADE; ERD tidak mencatat aksi).
 *     Ganti NIP lewat B-06 (salin baris → arahkan ulang anak → hapus baris lama), bukan ON UPDATE CASCADE (K1).
 *   - `pegawai_hist.nip` NOT NULL (legacy NULL): setiap pengajuan milik satu pegawai.
 *   - `pegawai_hist.flag_update` DEFAULT 1 (Diajukan) + COMMENT (legacy stub DEFAULT 0 — nilai 0 tidak pernah ditulis
 *     kode untuk hist).
 *   - CHECK [V2]: `pegawai.status` 1/2/10, `pegawai.flag_update` 0..3, `pegawai.jenis_kelamin` 1/2; hist: `flag_update`
 *     1/2/3, `status` 1/2/10, `jenis_kelamin` 1/2 (hist adalah salinan baris pegawai).
 *   - COMMENT `status` v2 "10: Dihapus" (legacy "10: Deleted"), COMMENT `flag_update`, `updated_by`, `approved_by` [V2].
 *   - Blok workflow `pegawai_hist` mengikuti konvensi bersama B-01/B-02 (`show_notif` TINYINT NOT NULL DEFAULT 0,
 *     `show_ua_*` TINYINT NULL); tipe INT vs TINYINT masih menunggu keputusan (dokumen Bagian 4 #3).
 *
 * Kolom audit diisi aplikasi (waktu UTC, `updated_by`/`approved_by` = id_pengguna tanpa FK, preseden DBV-001/010);
 * default DB hanya cadangan. Nilai AUTO_INCREMENT awal tidak ditulis. Tanpa baris seed.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreatePegawai extends Migration
{
    public const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    public const FLAG_UPDATE_COMMENT = '0: Tidak ada pengajuan, 1: Diajukan, 2: Ditolak/Dibatalkan, 3: Disetujui';

    public const HIST_FLAG_UPDATE_COMMENT = '1: Diajukan, 2: Ditolak/Dibatalkan, 3: Disetujui';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    /**
     * Urutan down(): anak (`pegawai_foto`, `pegawai_hist`) sebelum induk (`pegawai`).
     */
    private const TABLES = ['pegawai_foto', 'pegawai_hist', 'pegawai'];

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
        $status    = "COMMENT '" . self::STATUS_COMMENT . "'";
        $biodata   = $this->biodataColumnsSql();
        $pegawaiFk = $this->masterForeignKeysSql([
            'fk_id_provinsi_lahir_peg_to_prov',
            'fk_id_kabupaten_kota_lahir_peg_to_kab_kota',
            'fk_id_agama_peg_to_agama',
            'fk_id_jenis_pegawai_peg_to_jenis_pegawai',
            'fk_id_jenis_status_peg_to_jenis_status',
        ]);
        $histFk = $this->masterForeignKeysSql([
            'fk_peg_hist_ibfk_02',
            'fk_peg_hist_ibfk_03',
            'fk_peg_hist_ibfk_04',
            'fk_peg_hist_ibfk_05',
            'fk_peg_hist_ibfk_06',
        ]);
        $sql = [];

        // [L] simpeg_prod_duplikat.pegawai (urutan kolom persis) + FK [K-erd]. PK nip VARCHAR(30) (K1).
        $sql['pegawai'] = "CREATE TABLE {$this->t('pegawai')} (
            `nip` VARCHAR(30) NOT NULL,
            {$biodata},
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            `flag_update` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::FLAG_UPDATE_COMMENT . "',
            `id_pns_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NULL DEFAULT 0,
            `siasn_lu` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            `deleted_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`nip`),
            KEY `status` (`status`),
            KEY `fk_id_provinsi_lahir_peg_to_prov` (`id_provinsi_lahir`),
            KEY `fk_id_kabupaten_kota_lahir_peg_to_kab_kota` (`id_kabupaten_kota_lahir`),
            KEY `fk_id_agama_peg_to_agama` (`id_agama`),
            KEY `fk_id_jenis_pegawai_peg_to_jenis_pegawai` (`id_jenis_pegawai`),
            KEY `fk_id_jenis_status_peg_to_jenis_status` (`id_jenis_status`),
            {$pegawaiFk},
            CONSTRAINT `chk_pegawai_status` CHECK (`status` IN (1, 2, 10)),
            CONSTRAINT `chk_pegawai_flag_update` CHECK (`flag_update` IN (0, 1, 2, 3)),
            CONSTRAINT `chk_pegawai_jenis_kelamin` CHECK (`jenis_kelamin` IN (1, 2))
        ) " . self::TABLE_OPTIONS;

        // [I] salinan kolom pegawai (tipe identik, tanpa deleted_at) + email + blok workflow; FK [K-erd].
        $sql['pegawai_hist'] = "CREATE TABLE {$this->t('pegawai_hist')} (
            `id_pegawai_hist` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            {$biodata},
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            `flag_update` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::HIST_FLAG_UPDATE_COMMENT . "',
            `id_pns_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NULL DEFAULT 0,
            `siasn_lu` DATETIME NULL DEFAULT NULL,
            `email` VARCHAR(150) NULL DEFAULT NULL COMMENT 'email akun yang diajukan; disalin ke pengguna.email saat disetujui',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            `approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang memproses (setuju/tolak)',
            `reason_note` MEDIUMTEXT NULL,
            `show_notif` TINYINT NOT NULL DEFAULT 0,
            `notif_date` DATETIME NULL DEFAULT NULL,
            `show_ua_upt` TINYINT NULL DEFAULT NULL,
            `show_ua_deputi` TINYINT NULL DEFAULT NULL,
            `show_ua_biro` TINYINT NULL DEFAULT NULL,
            PRIMARY KEY (`id_pegawai_hist`),
            KEY `nip` (`nip`),
            KEY `flag_update` (`flag_update`),
            KEY `show_notif` (`show_notif`, `notif_date`),
            KEY `fk_peg_hist_ibfk_02` (`id_provinsi_lahir`),
            KEY `fk_peg_hist_ibfk_03` (`id_kabupaten_kota_lahir`),
            KEY `fk_peg_hist_ibfk_04` (`id_agama`),
            KEY `fk_peg_hist_ibfk_05` (`id_jenis_pegawai`),
            KEY `fk_peg_hist_ibfk_06` (`id_jenis_status`),
            CONSTRAINT `fk_peg_hist_ibfk_01` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            {$histFk},
            CONSTRAINT `chk_pegawai_hist_flag_update` CHECK (`flag_update` IN (1, 2, 3)),
            CONSTRAINT `chk_pegawai_hist_status` CHECK (`status` IN (1, 2, 10)),
            CONSTRAINT `chk_pegawai_hist_jenis_kelamin` CHECK (`jenis_kelamin` IN (1, 2))
        ) " . self::TABLE_OPTIONS;

        // [K-erd] + [I] kolom yang dibaca kode; created_at [V2]. Galeri foto per pegawai (banyak baris per nip).
        $sql['pegawai_foto'] = "CREATE TABLE {$this->t('pegawai_foto')} (
            `id_pegawai_foto` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `foto` VARCHAR(255) NOT NULL COMMENT 'path berkas foto (format = pegawai.foto)',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_pegawai_foto`),
            KEY `fk_nip_pegfoto_to_peg` (`nip`),
            CONSTRAINT `fk_nip_pegfoto_to_peg` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . '
        ) ' . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Kolom biodata `pegawai` [L] dari `id_provinsi_lahir` s.d. `keterangan` (urutan persis); dipakai juga oleh
     * `pegawai_hist` agar tipe salinannya identik.
     */
    private function biodataColumnsSql(): string
    {
        return "`id_provinsi_lahir` CHAR(2) NULL DEFAULT NULL,
            `id_kabupaten_kota_lahir` CHAR(4) NULL DEFAULT NULL,
            `id_agama` TINYINT NULL DEFAULT NULL,
            `id_jenis_pegawai` TINYINT NULL DEFAULT NULL,
            `id_jenis_status` TINYINT NULL DEFAULT NULL,
            `nip_lama` VARCHAR(18) NULL DEFAULT NULL,
            `nama` VARCHAR(100) NOT NULL,
            `glr_awal` VARCHAR(50) NULL DEFAULT NULL,
            `glr_akhir` VARCHAR(50) NULL DEFAULT NULL,
            `tgl_lahir` DATE NOT NULL,
            `provinsi_lahir` VARCHAR(255) NULL DEFAULT NULL,
            `provinsi_lahir_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kabupaten_kota_lahir` VARCHAR(255) NULL DEFAULT NULL,
            `kabupaten_kota_lahir_lain` VARCHAR(255) NULL DEFAULT NULL,
            `agama` VARCHAR(30) NULL DEFAULT NULL,
            `jenis_kelamin` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Laki-laki, 2: Perempuan',
            `npwp` VARCHAR(20) NULL DEFAULT NULL,
            `nik` VARCHAR(20) NULL DEFAULT NULL,
            `bpjs_kes` VARCHAR(50) NULL DEFAULT NULL,
            `bpjs_ket` VARCHAR(50) NULL DEFAULT NULL,
            `no_taspen` VARCHAR(50) NULL DEFAULT NULL,
            `no_hp` VARCHAR(20) NULL DEFAULT NULL,
            `jenis_kerabat` VARCHAR(100) NULL DEFAULT NULL,
            `no_telp_kerabat` VARCHAR(20) NULL DEFAULT NULL,
            `status_pernikahan` TINYINT(1) NULL DEFAULT NULL COMMENT '1: Menikah, 2: Tidak Menikah',
            `jenis_pegawai` VARCHAR(255) NULL DEFAULT NULL,
            `jenis_status` VARCHAR(100) NULL DEFAULT NULL,
            `tmt_status` DATE NULL DEFAULT NULL,
            `foto` VARCHAR(255) NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL";
    }

    /**
     * FK master biodata (target sudah ada di `main`: wilayah DBV-001, agama/jenis_pegawai/jenis_status DBV-001),
     * urutan kolom: provinsi lahir, kabupaten/kota lahir, agama, jenis pegawai, jenis status.
     *
     * @param array{0: string, 1: string, 2: string, 3: string, 4: string} $names nama FK sesuai urutan kolom
     */
    private function masterForeignKeysSql(array $names): string
    {
        $targets = [
            ['id_provinsi_lahir', 'provinsi', 'id_provinsi'],
            ['id_kabupaten_kota_lahir', 'kabupaten_kota', 'id_kabupaten_kota'],
            ['id_agama', 'agama', 'id_agama'],
            ['id_jenis_pegawai', 'jenis_pegawai', 'id_jenis_pegawai'],
            ['id_jenis_status', 'jenis_status', 'id_jenis_status'],
        ];

        $sql = [];

        foreach ($targets as $i => [$column, $parent, $parentColumn]) {
            $sql[] = "CONSTRAINT `{$names[$i]}` FOREIGN KEY (`{$column}`)
                REFERENCES {$this->t($parent)} (`{$parentColumn}`) " . self::RESTRICT;
        }

        return implode(",\n            ", $sql);
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan
     * drop tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen
     * Bagian 6.6).
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

            throw new RuntimeException('DBV-012: DDL pegawai gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
