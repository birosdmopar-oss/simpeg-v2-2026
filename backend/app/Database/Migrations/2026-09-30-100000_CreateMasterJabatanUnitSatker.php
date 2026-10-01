<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-008 — G-02: master unit kerja, satuan kerja, group & sub group jabatan, kelas jabatan, dan jabatan dengan skema
 * SIMPEG legacy (Mapping Migrasi Prinsip #1, Tier 1 "Copy langsung").
 * Sumber: dump struktur produksi lengkap `simpeg01_struktur_lengkap_20261001.sql` (D1, MySQL 8.0.21) [K] untuk keenam
 * tabel: `unit` D1:7505-7518, `satker` D1:6886-6904, `group_jabatan` D1:1295-1304, `sub_group_jabatan` D1:7183-7195,
 * `kelas_jabatan` D1:2027-2035, `jabatan` D1:1448-1473 (`group_jabatan`/`jabatan` sama dengan `simpeg_prod.sql`
 * :1294-1303/:1447-1472). Revisi 01-10-2026: `unit`/`satker`/`kelas_jabatan` semula [L] salinan lokal (cocok dengan D1)
 * dan `sub_group_jabatan` semula [I] dari kode; `sub_group_jabatan` diselaraskan dengan D1 (`id_group_jabatan` NOT
 * NULL, `need_satker` TINYINT(1) DEFAULT 2, KEY legacy `id_group_jabatan_idx`).
 * Review DB Validator: backend/docs/db-review/G-02-jabatan-unit-satker-schema.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom, tipe & panjang kolom, PK (`kelas_jabatan` = PK alami TINYINT tanpa
 * AUTO_INCREMENT), nama KEY/FK legacy (termasuk KEY `jabatan` dan KEY `fk_id_jenjang_jf_jab_to_jenjang_jf`), kolom
 * audit `created_at`/`updated_at`/`updated_by`, collation utf8mb4_unicode_ci.
 *
 * Deviasi dari legacy (dicatat untuk DBV, alasan di dokumen Bagian 3):
 *   - Status 1 Aktif / 2 Tidak Aktif / 10 Dihapus + COMMENT v2 di keenam tabel (legacy hapus keras).
 *   - UNIQUE nama: `unit`, `satker (id_unit, satker)`, `group_jabatan`, `sub_group_jabatan (id_group_jabatan,
 *     sub_group_jabatan)`, `jabatan (id_sub_group_jabatan, id_satker, jabatan)`; berlaku juga untuk baris status 2/10.
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT dengan nama legacy (legacy SET NULL / CASCADE).
 *   - FK `jabatan.id_jenjang_jf` → `jenjang_jf` [K] D1:1469 TIDAK dibuat di sini; tabel `jenjang_jf` + FK-nya
 *     ditambahkan migration DBV-018 (G-02 sisa). Kolom + KEY-nya sudah ada.
 *   - 5 CHECK: `is_upt` 0/1 (unit, satker), `satker.zonasi` 0..120 menit, `sub_group_jabatan.need_satker` 1/2,
 *     `kelas_jabatan` 1..20.
 *   - Kolom `order` TINYINT [V2] di `sub_group_jabatan` (tidak ada di D1); `jabatan` dan `kelas_jabatan` tanpa `order`.
 *   - `sub_group_jabatan.sub_group_jabatan` NOT NULL [V2] (D1 NULL): nama tampilan wajib dan bagian kunci UNIQUE.
 *   - Trigger legacy `jabatan_af*`/`satker_af*` (D1:7743-7816, :10530-10576; menulis lintas schema ke aplikasi lain)
 *     tidak dibuat: integrasi itu ditangani rencana pengalihan konsumen, bukan skema v2.
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan untuk penulisan di luar
 * aplikasi. Nilai AUTO_INCREMENT awal tidak ditulis (legacy group_jabatan AUTO_INCREMENT=7, jabatan=2203; counter
 * disalin saat impor). Tanpa baris seed: ID yang di-hard-code kode legacy datang dari impor ID legacy apa adanya.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateMasterJabatanUnitSatker extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan untuk down(): anak sebelum induk.
     */
    private const TABLES = ['jabatan', 'kelas_jabatan', 'sub_group_jabatan', 'group_jabatan', 'satker', 'unit'];

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
        $audit    = $this->auditColumnsSql();
        $restrict = 'ON DELETE RESTRICT ON UPDATE RESTRICT';
        $sql      = [];

        // [K] D1:7505-7518. is_upt: 1 UPT / 0 bukan (konsumen legacy memakai == '1'; form unit legacy mengirim 2
        // untuk "TIDAK", dinormalkan ke 0 saat impor).
        $sql['unit'] = "CREATE TABLE {$this->t('unit')} (
            `id_unit` INT NOT NULL AUTO_INCREMENT,
            `unit` VARCHAR(150) NOT NULL,
            `is_upt` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0: Bukan UPT, 1: UPT',
            `alamat_pdf_header` TINYTEXT NULL,
            `tembusan_kppn` VARCHAR(256) NULL DEFAULT NULL,
            `lokasi_kppn` VARCHAR(50) NULL DEFAULT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_unit`),
            UNIQUE KEY `uq_unit_nama` (`unit`),
            CONSTRAINT `chk_unit_is_upt` CHECK (`is_upt` IN (0, 1))
        ) " . self::TABLE_OPTIONS;

        // [K] D1:6886-6904. zonasi = selisih jam presensi dari WIB dalam menit (form legacy "WIB + N menit",
        // mask 0-120, satker/form.php:48-58, :131-135). logo_uns disimpan, belum dikelola v2.
        $sql['satker'] = "CREATE TABLE {$this->t('satker')} (
            `id_satker` INT NOT NULL AUTO_INCREMENT,
            `id_unit` INT NULL DEFAULT NULL,
            `satker` VARCHAR(150) NOT NULL,
            `is_upt` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0: Bukan UPT, 1: UPT',
            `alamat_pdf_header` TEXT NULL,
            `tembusan_kppn` VARCHAR(256) NULL DEFAULT NULL,
            `lokasi_kppn` VARCHAR(50) NULL DEFAULT NULL,
            `logo_uns` VARCHAR(256) NULL DEFAULT NULL,
            `zonasi` INT NOT NULL DEFAULT 0 COMMENT 'offset jam presensi dari WIB dalam menit: 0 WIB, 60 WITA, 120 WIT',
            `order` SMALLINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_satker`),
            UNIQUE KEY `uq_satker_nama` (`id_unit`, `satker`),
            KEY `fk_id_unit_satker_to_unit` (`id_unit`),
            CONSTRAINT `fk_id_unit_satker_to_unit` FOREIGN KEY (`id_unit`)
                REFERENCES {$this->t('unit')} (`id_unit`) {$restrict},
            CONSTRAINT `chk_satker_is_upt` CHECK (`is_upt` IN (0, 1)),
            CONSTRAINT `chk_satker_zonasi` CHECK (`zonasi` BETWEEN 0 AND 120)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:1295-1304 (urutan kolom legacy: status sebelum order; status TINYINT(1)).
        $sql['group_jabatan'] = "CREATE TABLE {$this->t('group_jabatan')} (
            `id_group_jabatan` INT NOT NULL AUTO_INCREMENT,
            `group_jabatan` VARCHAR(45) NOT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1 {$status},
            `order` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_group_jabatan`),
            UNIQUE KEY `uq_group_jabatan_nama` (`group_jabatan`)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:7183-7195. need_satker 1 Ya / 2 Tidak (Lm_jabatan.php:1015); order [V2] (urutan dropdown per group);
        // nama NOT NULL [V2] (D1 NULL). KEY legacy id_group_jabatan_idx menjadi index FK.
        $sql['sub_group_jabatan'] = "CREATE TABLE {$this->t('sub_group_jabatan')} (
            `id_sub_group_jabatan` INT NOT NULL AUTO_INCREMENT,
            `id_group_jabatan` INT NOT NULL,
            `sub_group_jabatan` VARCHAR(100) NOT NULL,
            `need_satker` TINYINT(1) NOT NULL DEFAULT 2 COMMENT '1: Ya, 2: Tidak (jabatan dipilih per satuan kerja di riwayat jabatan)',
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_sub_group_jabatan`),
            UNIQUE KEY `uq_sub_group_jabatan_nama` (`id_group_jabatan`, `sub_group_jabatan`),
            KEY `id_group_jabatan_idx` (`id_group_jabatan`),
            CONSTRAINT `fk_id_group_jabatan_sgj_to_gj` FOREIGN KEY (`id_group_jabatan`)
                REFERENCES {$this->t('group_jabatan')} (`id_group_jabatan`) {$restrict},
            CONSTRAINT `chk_sub_group_jabatan_need_satker` CHECK (`need_satker` IN (1, 2))
        ) " . self::TABLE_OPTIONS;

        // [K] D1:2027-2035 (legacy COLLATE 0900, tanpa kolom string; v2 unicode_ci seperti tabel lain): PK alami = nomor kelas (bukan AUTO_INCREMENT), tanpa kolom nama/order.
        $sql['kelas_jabatan'] = "CREATE TABLE {$this->t('kelas_jabatan')} (
            `kelas_jabatan` TINYINT NOT NULL,
            `tukin` INT NOT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`kelas_jabatan`),
            CONSTRAINT `chk_kelas_jabatan_kelas_jabatan` CHECK (`kelas_jabatan` BETWEEN 1 AND 20)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:1448-1473. FK ke jenjang_jf (D1:1469) ditambahkan migration DBV-018 bersama tabelnya; KEY-nya sudah ada.
        $sql['jabatan'] = "CREATE TABLE {$this->t('jabatan')} (
            `id_jabatan` INT NOT NULL AUTO_INCREMENT,
            `id_group_jabatan` INT NULL DEFAULT NULL,
            `id_sub_group_jabatan` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `id_jenjang_jf` TINYINT NULL DEFAULT NULL,
            `kelas_jabatan` TINYINT NULL DEFAULT NULL,
            `jabatan` VARCHAR(250) NOT NULL,
            `umur_pensiun` INT NULL DEFAULT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_jabatan`),
            UNIQUE KEY `uq_jabatan_nama` (`id_sub_group_jabatan`, `id_satker`, `jabatan`),
            KEY `jabatan` (`jabatan`),
            KEY `fk_id_group_jabatan_jabatan_to_gj` (`id_group_jabatan`),
            KEY `fk_id_sub_group_jabatan_jabatan_to_sgj` (`id_sub_group_jabatan`),
            KEY `fk_id_satker_jabatan_to_satker` (`id_satker`),
            KEY `fk_kelas_jabatan_jabatan_to_kelasjabatan` (`kelas_jabatan`),
            KEY `fk_id_jenjang_jf_jab_to_jenjang_jf` (`id_jenjang_jf`),
            CONSTRAINT `fk_id_group_jabatan_jabatan_to_gj` FOREIGN KEY (`id_group_jabatan`)
                REFERENCES {$this->t('group_jabatan')} (`id_group_jabatan`) {$restrict},
            CONSTRAINT `fk_id_satker_jabatan_to_satker` FOREIGN KEY (`id_satker`)
                REFERENCES {$this->t('satker')} (`id_satker`) {$restrict},
            CONSTRAINT `fk_id_sub_group_jabatan_jabatan_to_sgj` FOREIGN KEY (`id_sub_group_jabatan`)
                REFERENCES {$this->t('sub_group_jabatan')} (`id_sub_group_jabatan`) {$restrict},
            CONSTRAINT `fk_kelas_jabatan_jabatan_to_kelasjabatan` FOREIGN KEY (`kelas_jabatan`)
                REFERENCES {$this->t('kelas_jabatan')} (`kelas_jabatan`) {$restrict}
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan drop
     * tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-02 Bagian 6.6).
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
     * Kolom audit pola D1 keenam tabel [K]: created_at NOT NULL DEFAULT CURRENT_TIMESTAMP,
     * updated_at NULL ON UPDATE, updated_by = id_pengguna aktor tanpa FK (preseden DBV-001).
     */
    private function auditColumnsSql(): string
    {
        return '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . '`updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, '
            . "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-008: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-008: DDL G-02 gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
