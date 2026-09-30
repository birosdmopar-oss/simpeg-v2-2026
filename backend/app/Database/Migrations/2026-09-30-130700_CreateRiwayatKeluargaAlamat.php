<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 4): riwayat keluarga `riwayat_keluarga` + data anak `detail_anak` (B-15) dan riwayat alamat
 * `riwayat_alamat` (B-16) dengan skema SIMPEG legacy. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.4) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber (label dokumen B-01/B-02):
 *   - `riwayat_keluarga`: stub [L] `simpeg_prod_duplikat` (5 kolom); kolom bisnis [I] `L_keluarga.php:1035-1050`
 *     (`sp`), approval `:1300-1320` (`sp_process`); titik awal DDL seed rekonstruksi
 *     `simpeg_v2_local_seed_legacy.sql:1882-1904` [I]; nama FK [K-erd].
 *   - `detail_anak`: DDL produksi `simpeg_prod.sql:373-386` [K] persis (kolom, tipe, NOT NULL, default, KEY, nama FK);
 *     ditulis `L_keluarga.php:344, 584, 612` (`sp_anak` `:1111-1175`), dihapus keras `:640, 833`.
 *   - `riwayat_alamat`: stub [L] (5 kolom); kolom bisnis [I] `L_alamat.php:593-680` (`sp`), approval `:777-800`;
 *     seed rekonstruksi `:1920-1966` [I]; nama FK [K-erd].
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `nip` NOT NULL (stub [L] DEFAULT NULL); status TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/10 (stub [L] VARCHAR(5)
 *     DEFAULT '1'); semua FK RESTRICT/RESTRICT (legacy/seed: `nip` CASCADE/CASCADE, wilayah SET NULL/CASCADE,
 *     `detail_anak` [K] CASCADE/CASCADE). Hapus keluarga legacy (`L_keluarga.php:720-777`) hanya menghapus baris
 *     `riwayat_keluarga` + lampiran dan MENGANDALKAN CASCADE untuk `detail_anak`; di v2 service B-15 wajib menghapus
 *     `detail_anak` (dan lampiran id_riwayat 3) lebih dulu, dalam satu transaksi.
 *   - `created_at` [I], `updated_at` NULL ON UPDATE (stub [L]: NOT NULL tanpa ON UPDATE); blok workflow TINYINT
 *     (keputusan dokumen B-01/B-02 #3 masih terbuka).
 *   - `riwayat_alamat.jenis_alamat` NOT NULL [I] (wajib di form, `L_alamat.php:499-502`; seed: NULL).
 *   - Tidak meniru trigger `d_*`: jejak perubahan lewat `audit_logs`.
 *
 * Kolom audit/workflow diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT
 * awal tidak ditulis (impor memakai ID legacy apa adanya, karena `document_attachment.id_entri` merujuk ID riwayat/anak).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatKeluargaAlamat extends Migration
{
    private const STATUS_RIWAYAT_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 10: Dihapus';

    /**
     * Domain status riwayat (dokumen B-01/B-02 2.0.3). `riwayat_alamat`: 0/1/2/10 (form admin alamat tanpa opsi 3).
     */
    private const STATUS_RIWAYAT_DOMAIN = '0, 1, 2, 10';

    /**
     * `riwayat_keluarga`: + 3 "Diproses" yang ditulis form admin legacy (views/hr/employee/rwy/keluarga/form.php:68).
     */
    private const STATUS_DIPROSES_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    private const STATUS_DIPROSES_DOMAIN = '0, 1, 2, 3, 10';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    /**
     * Urutan down(): anak (`detail_anak`) sebelum induk (`riwayat_keluarga`).
     */
    private const TABLES = ['riwayat_alamat', 'detail_anak', 'riwayat_keluarga'];

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
        $sql = [];

        // Kolom bisnis = snapshot pegawai_keluarga (kelompok 1) persis: snapshot disalin dari baris yang disetujui.
        // jumlah_anak dihitung aplikasi = jumlah detail_anak (L_keluarga.php:1044, :829).
        $sql['riwayat_keluarga'] = "CREATE TABLE {$this->t('riwayat_keluarga')} (
            `id_riwayat_keluarga` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `urutan_perkawinan` TINYINT NULL DEFAULT NULL,
            `tgl_perkawinan` DATE NULL DEFAULT NULL,
            `kota_perkawinan` VARCHAR(255) NULL DEFAULT NULL,
            `nama_pasangan` VARCHAR(255) NULL DEFAULT NULL,
            `jumlah_anak` TINYINT NOT NULL DEFAULT 0 COMMENT 'jumlah baris detail_anak, dihitung aplikasi',
            `keterangan` TINYTEXT NULL,
            {$this->workflowAuditSql(self::STATUS_DIPROSES_COMMENT)},
            PRIMARY KEY (`id_riwayat_keluarga`),
            KEY `fk_nip_rwykeluarga_to_pegawai` (`nip`),
            CONSTRAINT `fk_nip_rwykeluarga_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            {$this->statusCheckSql('riwayat_keluarga', self::STATUS_DIPROSES_DOMAIN)}
        ) " . self::TABLE_OPTIONS;

        // [K] simpeg_prod.sql:373-386 persis; satu-satunya deviasi = aksi FK CASCADE → RESTRICT. Tanpa kolom nip:
        // pemilik = riwayat_keluarga.nip. Lampiran akte lahir: document_attachment id_riwayat 3, id_entri = id_detail_anak.
        $sql['detail_anak'] = "CREATE TABLE {$this->t('detail_anak')} (
            `id_detail_anak` INT NOT NULL AUTO_INCREMENT,
            `id_riwayat_keluarga` INT NOT NULL,
            `urutan` TINYINT NOT NULL DEFAULT 1,
            `tgl_lahir` DATE NOT NULL,
            `nama` VARCHAR(255) NOT NULL,
            `tempat_lahir` VARCHAR(255) NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_detail_anak`),
            KEY `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` (`id_riwayat_keluarga`),
            CONSTRAINT `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` FOREIGN KEY (`id_riwayat_keluarga`)
                REFERENCES {$this->t('riwayat_keluarga')} (`id_riwayat_keluarga`) " . self::RESTRICT . '
        ) ' . self::TABLE_OPTIONS;

        // Kolom = L_alamat::sp() (urutan seed). Kolom wilayah bisnis = snapshot pegawai_alamat/pegawai_alamat_kantor
        // (kelompok 1) persis. jenis_alamat 3 (Kantor) → alamat_utama 2 + kolom kantor terisi (L_alamat.php:669-675).
        // Kode wilayah LAIN-LAIN 99/9999/9999999/9999999999 + kolom *_lain (sentinel DBV-003 valid terhadap FK).
        $sql['riwayat_alamat'] = "CREATE TABLE {$this->t('riwayat_alamat')} (
            `id_riwayat_alamat` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `jenis_alamat` TINYINT(1) NOT NULL COMMENT '1: KTP, 2: Domisili, 3: Kantor',
            `alamat_utama` TINYINT(1) NULL DEFAULT NULL COMMENT '1: Ya, 2: Tidak (jenis_alamat 3 selalu 2)',
            `id_provinsi` CHAR(2) NULL DEFAULT NULL,
            `id_kabupaten_kota` CHAR(4) NULL DEFAULT NULL,
            `id_kecamatan` CHAR(7) NULL DEFAULT NULL,
            `id_kelurahan` CHAR(10) NULL DEFAULT NULL,
            `provinsi` VARCHAR(255) NULL DEFAULT NULL,
            `provinsi_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kabupaten_kota` VARCHAR(255) NULL DEFAULT NULL,
            `kabupaten_kota_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kecamatan` VARCHAR(255) NULL DEFAULT NULL,
            `kecamatan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kelurahan` VARCHAR(255) NULL DEFAULT NULL,
            `kelurahan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kd_pos` VARCHAR(10) NULL DEFAULT NULL,
            `KdPos` VARCHAR(10) NULL DEFAULT NULL COMMENT 'salinan kd_pos yang juga ditulis legacy (L_alamat.php:615)',
            `alamat` TEXT NULL,
            `nama_kantor` VARCHAR(255) NULL DEFAULT NULL,
            `lantai` VARCHAR(50) NULL DEFAULT NULL,
            `telp` VARCHAR(50) NULL DEFAULT NULL,
            `faks` VARCHAR(50) NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_alamat`),
            KEY `fk_nip_rwyalamat_to_pegawai` (`nip`),
            KEY `fk_id_provinsi_riwalamat_provinsi` (`id_provinsi`),
            KEY `fk_id_kabupaten_kota_riwalamat_kabkota` (`id_kabupaten_kota`),
            KEY `fk_id_kecamatan_riwalamat_kecamatan` (`id_kecamatan`),
            KEY `fk_id_kelurahan_riwalamat_kelurahan` (`id_kelurahan`),
            CONSTRAINT `fk_nip_rwyalamat_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            CONSTRAINT `fk_id_provinsi_riwalamat_provinsi` FOREIGN KEY (`id_provinsi`)
                REFERENCES {$this->t('provinsi')} (`id_provinsi`) " . self::RESTRICT . ",
            CONSTRAINT `fk_id_kabupaten_kota_riwalamat_kabkota` FOREIGN KEY (`id_kabupaten_kota`)
                REFERENCES {$this->t('kabupaten_kota')} (`id_kabupaten_kota`) " . self::RESTRICT . ",
            CONSTRAINT `fk_id_kecamatan_riwalamat_kecamatan` FOREIGN KEY (`id_kecamatan`)
                REFERENCES {$this->t('kecamatan')} (`id_kecamatan`) " . self::RESTRICT . ",
            CONSTRAINT `fk_id_kelurahan_riwalamat_kelurahan` FOREIGN KEY (`id_kelurahan`)
                REFERENCES {$this->t('kelurahan')} (`id_kelurahan`) " . self::RESTRICT . ",
            {$this->statusCheckSql('riwayat_alamat')}
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Status + blok workflow standar + audit riwayat (konvensi B-02 2.0.3/2.0.4), sama persis dengan kelompok 2/3.
     * Tipe TINYINT untuk `show_notif`/`show_ua_*` = stub [L] (`show_notif tinyint NOT NULL DEFAULT '0'`); bukti [K]
     * `absen_ijin` memakai INT (keputusan dokumen B-01/B-02 #3).
     */
    private function workflowAuditSql(string $statusComment = self::STATUS_RIWAYAT_COMMENT): string
    {
        return "`status` TINYINT NOT NULL DEFAULT 0 COMMENT '" . $statusComment . "',
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

    private function statusCheckSql(string $table, string $domain = self::STATUS_RIWAYAT_DOMAIN): string
    {
        return "CONSTRAINT `chk_{$table}_status` CHECK (`status` IN (" . $domain . '))';
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan drop
     * tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen B-02 6.6).
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

            throw new RuntimeException('DBV-013: DDL riwayat keluarga/alamat gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
