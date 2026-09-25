<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-003 — G-07 kantor dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1). Review DB Validator:
 * backend/docs/db-review/G-07-G-08-kantor-hari-libur-kursem-schema.md — JANGAN dijalankan di Dev/Production sebelum
 * disetujui.
 *
 * Sumber: DDL legacy `kantor` tidak tersedia (dump terpotong di `jabatan`). Nama & urutan kolom dari kode legacy [K]
 * (`Lm_umum.php` set_param_kantor :1838-1870, list_kantor :1576-1590; nama kolom `id_kabupaten`, bukan
 * `id_kabupaten_kota`); nama keempat FK dari ERD simpeg01 [K]. Tipe kolom [I]: kode wilayah CHAR(2/4/7/10) dan
 * `*_lain` VARCHAR(255) meniru kolom sejenis di DDL produksi (`form_kesehatan`, simpeg_prod.sql:1206-1236) dan PK
 * wilayah pasca-DBV-001.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - 4 FK ke provinsi/kabupaten_kota/kecamatan/kelurahan ON DELETE/UPDATE RESTRICT (aksi legacy tidak diketahui;
 *     ERD tidak menyimpannya). FK per kolom tidak memeriksa konsistensi rantai; itu tugas aplikasi (KantorHooks).
 *   - `UNIQUE uq_kantor_nama (nama_kantor)` global, berlaku juga untuk baris status 2/10.
 *   - Status v2 1 Aktif / 2 Tidak Aktif / 10 Dihapus (legacy hanya 1, hapus fisik); COMMENT kolom audit.
 *
 * Kode LAIN-LAIN legacy (99/9999/9999999/9999999999, teks di kolom `*_lain`) membutuhkan 4 baris sentinel di tabel
 * wilayah. Baris itu di-seed migration terpisah `2026-09-25-100200_SeedWilayahLainLain`, yang belum ada di branch ini
 * dan menyusul di tahap CR-010 bersama fitur sentinel engine. Sampai migration itu ada, kantor berkode LAIN-LAIN
 * ditolak FK (error 1452).
 *
 * Versi 100200 lebih kecil dari migration ini. Urutan rollback "kantor dulu, baru sentinel" hanya terjamin bila
 * keduanya jalan dalam SATU batch; Dev/Production menerima keduanya bersama dalam PR DBV-003/CR-010. Di DB yang
 * sudah menjalankan migration ini lebih dulu (mis. DB lokal cabang ini), CodeIgniter menjalankan 100200 di batch
 * baru (MigrationRunner::latest()), sehingga rollback batch itu menghapus sentinel selagi `kantor` masih ada, dan
 * down() sentinel menolak (FK 1451) selama ada baris kantor yang merujuk sentinel. Di DB seperti itu, rollback
 * batch DBV-003 dulu sebelum migrate tahap CR-010.
 *
 * Kolom kode mewarisi collation tabel (utf8mb4_unicode_ci) = collation PK wilayah pasca-DBV-001; FK string beda
 * collation ditolak MySQL (error 3780), jadi migration ini wajib berjalan setelah 2026-09-23-000000. Selama `kantor`
 * ada, rollback DBV-001 langsung (AlterBatch1KeSkemaLegacy::down()) ditolak MySQL (error 1833/3780): rollback DBV-003
 * dulu. Satu statement CREATE: bila gagal tidak ada tabel yang tertinggal.
 */
class CreateKantor extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        // Urutan kolom mengikuti set_param_kantor (Lm_umum.php:1840-1856); status & audit di akhir.
        $this->exec("CREATE TABLE {$this->t('kantor')} (
            `id_kantor` INT NOT NULL AUTO_INCREMENT,
            `order` INT NOT NULL DEFAULT 1,
            `nama_kantor` VARCHAR(255) NOT NULL,
            `alamat` TEXT NOT NULL,
            `id_provinsi` CHAR(2) NOT NULL,
            `provinsi_lain` VARCHAR(255) NULL DEFAULT NULL,
            `id_kabupaten` CHAR(4) NOT NULL,
            `kabupaten_lain` VARCHAR(255) NULL DEFAULT NULL,
            `id_kecamatan` CHAR(7) NOT NULL,
            `kecamatan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `id_kelurahan` CHAR(10) NOT NULL,
            `kelurahan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kode_pos` CHAR(5) NULL DEFAULT NULL,
            `telp` VARCHAR(50) NULL DEFAULT NULL,
            `faks` VARCHAR(50) NULL DEFAULT NULL,
            `remark` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            PRIMARY KEY (`id_kantor`),
            UNIQUE KEY `uq_kantor_nama` (`nama_kantor`),
            KEY `fk_id_provinsi_kantor_to_prov` (`id_provinsi`),
            KEY `fk_id_kabupaten_kantor_to_kab` (`id_kabupaten`),
            KEY `fk_id_kecamatan_kantor_to_kec` (`id_kecamatan`),
            KEY `fk_id_kelurahan_kantor_to_kel` (`id_kelurahan`),
            CONSTRAINT `fk_id_provinsi_kantor_to_prov` FOREIGN KEY (`id_provinsi`)
                REFERENCES {$this->t('provinsi')} (`id_provinsi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_kabupaten_kantor_to_kab` FOREIGN KEY (`id_kabupaten`)
                REFERENCES {$this->t('kabupaten_kota')} (`id_kabupaten_kota`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_kecamatan_kantor_to_kec` FOREIGN KEY (`id_kecamatan`)
                REFERENCES {$this->t('kecamatan')} (`id_kecamatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_kelurahan_kantor_to_kel` FOREIGN KEY (`id_kelurahan`)
                REFERENCES {$this->t('kelurahan')} (`id_kelurahan`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS);
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS {$this->t('kantor')}");
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-003: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-003: DDL kantor gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
