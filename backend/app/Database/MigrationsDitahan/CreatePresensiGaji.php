<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DRAF DBV-023 (Fase 5A D-01) — DITAHAN. File ini sengaja di luar app/Database/Migrations sehingga TIDAK dijalankan
 * `php spark migrate` (pola DBV-019/DBV-020). Dipindah ke app/Database/Migrations dengan nama
 * `<timestamp>_CreatePresensiGaji.php` di milestone M2 PRD 5A, setelah (1) DB Validator menyetujui DBV-023 dan (2) DBV-022
 * (Fase 4) ada di `main`; timestamp WAJIB lebih baru dari semua migration DBV-022 (PRD 5A §3.5). Isi final mengikuti
 * keputusan DB Validator di dokumen backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md (Bagian 9).
 *
 * Tabel:
 *   - `dh_online` — D1:390-401 [K] (PK `(nip, tgl)`, FK `fk_nip_dh_to_pegawai` RESTRICT/RESTRICT [V2], D1 CASCADE)
 *     + kolom bukti presensi online F5-12 [V2] (koordinat, akurasi, lokasi pengesah, `sumber`) + `created_by` [V2].
 *     `tgl`/`jam_*` = tanggal/jam dinding lokal satker (WIB + `satker.zonasi`), bukan UTC. Trigger `dh_online_beDel`
 *     (D1:7709-7714, arsip `d_dh_online`) tidak dibawa: pengganti = `audit_logs` event `delete`.
 *   - `gaji_pegawai` — versi legacy Slip Gaji `771f8b4` (F5-2): kunci & kolom status D1:1242-1273 [K] + 28 kolom GPP/TK
 *     SQL-L `2026_09_01…:51-79` [K] + `uang_makan_hari`/`uang_makan_periode` SQL-L `2026_10_08…:27-29` [K]; 15 kolom
 *     generik D1 dibuang [V2]; `qr_token` berisi hash SHA-256 token aktif (F5-6) dengan UNIQUE [V2]; `created_by`,
 *     `updated_by`, `qr_token_enkripsi` (Q5A-01 opsi a) [V2]; CHECK bulan/tahun/nominal [V2]. Tanpa FK `nip` [K]
 *     (registry `NonFkSlipGaji`).
 *   - `gaji_pegawai_qr_history` — SQL-L `2026_09_02…:28-40` [K]; FK `fk_gpqh_id_gaji_pegawai` RESTRICT [V2] (SQL-L
 *     CASCADE); UNIQUE token hash [V2]; kolom snapshot verifikasi AS-06 [V2].
 *
 * Kolom [K] mengikuti urutan sumber; kolom [V2] diletakkan paling akhir (pola G-03). CHECK `chk_dh_online_jam` hanya
 * dipertahankan bila kueri audit DBV-023 #2 = 0 (DBV-Q5). up() men-DROP tabel yang sempat dibuat bila gagal di tengah;
 * down() men-DROP ketiga tabel (anak dulu).
 */
class CreatePresensiGaji extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    private const RESTRICT      = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    /**
     * 22 kolom GPP lalu 6 kolom TK (= `App\Libraries\Presensi\SlipGaji\KolomGaji`), komentar SQL-L
     * `2026_09_01_slip_gaji_migration.sql:52-79` apa adanya.
     */
    public const KOLOM_NOMINAL = [
        'gjpokok'    => 'GPP: Gaji Pokok',
        'tjistri'    => 'GPP: Tunjangan Istri/Suami',
        'tjanak'     => 'GPP: Tunjangan Anak',
        'tjupns'     => 'GPP: Tunjangan Umum PNS',
        'tjstruk'    => 'GPP: Tunjangan Struktural',
        'tjfungs'    => 'GPP: Tunjangan Fungsional',
        'tjdaerah'   => 'GPP: Tunjangan Daerah',
        'tjpencil'   => 'GPP: Tunjangan Pencil/Pensiun',
        'tjlain'     => 'GPP: Tunjangan Lain-lain',
        'tjkompen'   => 'GPP: Tunjangan Kompensasi',
        'pembul'     => 'GPP: Pembulatan',
        'tjberas'    => 'GPP: Tunjangan Beras',
        'tjpph'      => 'GPP: Tunjangan PPh',
        'potpfkbul'  => 'GPP: Potongan PFK Bulanan',
        'potpfk2'    => 'GPP: Potongan PFK 2%',
        'potpfk10'   => 'GPP: Potongan PFK 10%',
        'potpph'     => 'GPP: Potongan PPh',
        'potswrum'   => 'GPP: Potongan Sewa Rumah',
        'potkelbtj'  => 'GPP: Potongan Kelebihan Tunjangan',
        'potlain'    => 'GPP: Potongan Lain-lain',
        'pottabrum'  => 'GPP: Potongan Tabungan Perumahan',
        'bersih_gpp' => 'GPP: Gaji Bersih',
        'kotor'      => 'TK: Penghasilan Kotor',
        'potongan'   => 'TK: Potongan',
        'bersih_tk'  => 'TK: Bersih',
        'pajak'      => 'TK: Pajak',
        'tunj_pajak' => 'TK: Tunjangan Pajak',
        'bersih_2'   => 'TK: Bersih 2 (label UI: Tunjangan Kinerja Bersih)',
    ];

    /** Urutan pembuatan (induk dulu); down() membalik urutan ini. */
    public const TABLES = ['dh_online', 'gaji_pegawai', 'gaji_pegawai_qr_history'];

    public function up(): void
    {
        $created = [];

        try {
            foreach ($this->statements() as $table => $sql) {
                $this->exec($sql);
                $created[] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->t($table));
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($table));
        }
    }

    /**
     * @return array<string, string>
     */
    private function statements(): array
    {
        $nominal = [];
        $cek     = [];

        foreach (self::KOLOM_NOMINAL as $kolom => $komentar) {
            $nominal[] = "`{$kolom}` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '{$komentar}'";
            $cek[]     = "`{$kolom}` >= 0";
        }

        $nominal = implode(",\n                ", $nominal);
        $cek     = implode(' AND ', [...$cek, '`uang_makan` >= 0']);

        return [
            'dh_online' => "CREATE TABLE {$this->t('dh_online')} (
                `nip` VARCHAR(30) NOT NULL,
                `tgl` DATE NOT NULL COMMENT 'Tanggal lokal satker (WIB + satker.zonasi), bukan UTC',
                `jam_masuk` TIME NULL DEFAULT NULL COMMENT 'Jam dinding lokal satker',
                `jam_pulang` TIME NULL DEFAULT NULL COMMENT 'Jam dinding lokal satker',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
                `lat_masuk` DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Lintang perangkat saat presensi masuk online; NULL untuk manual/impor/tanpa GPS',
                `lng_masuk` DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Bujur perangkat saat presensi masuk online',
                `akurasi_masuk` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Akurasi GPS masuk (meter); dicatat, tidak dipakai menolak',
                `id_lokasi_masuk` INT NULL DEFAULT NULL COMMENT 'Lokasi presensi yang mengesahkan masuk (terdekat dalam radius); NULL bila tanpa aturan/manual',
                `lat_pulang` DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Lintang perangkat saat presensi pulang online',
                `lng_pulang` DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Bujur perangkat saat presensi pulang online',
                `akurasi_pulang` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Akurasi GPS pulang (meter)',
                `id_lokasi_pulang` INT NULL DEFAULT NULL COMMENT 'Lokasi presensi yang mengesahkan pulang',
                `sumber` TINYINT NULL DEFAULT NULL COMMENT '1: Online, 2: Manual (melekat), NULL: impor legacy',
                `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
                PRIMARY KEY (`nip`, `tgl`),
                KEY `tgl` (`tgl`),
                KEY `fk_id_lokasi_masuk_dh_to_lp` (`id_lokasi_masuk`),
                KEY `fk_id_lokasi_pulang_dh_to_lp` (`id_lokasi_pulang`),
                CONSTRAINT `fk_nip_dh_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_lokasi_masuk_dh_to_lp` FOREIGN KEY (`id_lokasi_masuk`) REFERENCES {$this->t('lokasi_presensi')} (`id_lokasi_presensi`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_lokasi_pulang_dh_to_lp` FOREIGN KEY (`id_lokasi_pulang`) REFERENCES {$this->t('lokasi_presensi')} (`id_lokasi_presensi`) " . self::RESTRICT . ',
                CONSTRAINT `chk_dh_online_sumber` CHECK (`sumber` IN (1, 2)),
                CONSTRAINT `chk_dh_online_jam` CHECK (`jam_masuk` IS NOT NULL OR `jam_pulang` IS NOT NULL)
            ) ' . self::TABLE_OPTIONS,
            'gaji_pegawai' => "CREATE TABLE {$this->t('gaji_pegawai')} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL COMMENT 'NIP pegawai (tanpa FK; tidak ikut Ubah NIP)',
                `bulan` TINYINT UNSIGNED NOT NULL COMMENT 'Bulan gaji (1-12)',
                `tahun` SMALLINT UNSIGNED NOT NULL COMMENT 'Tahun gaji (misal 2026)',
                {$nominal},
                `uang_makan` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot uang makan presensi bulan N-1, dihitung saat slip dibuka',
                `viewed_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC saat pertama kali dibuka pegawai (NULL = belum dibuka)',
                `viewed_ip` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Alamat IP saat slip dibuka',
                `qr_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Hash SHA-256 heks token QR aktif; NULL = tidak ada token aktif',
                `uang_makan_hari` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Jumlah hari berhak uang makan pada snapshot',
                `uang_makan_periode` VARCHAR(7) NULL DEFAULT NULL COMMENT 'Bulan presensi sumber snapshot, format m-YYYY (mis. 9-2026)',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
                `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
                `qr_token_enkripsi` VARBINARY(255) NULL DEFAULT NULL COMMENT 'Token QR aktif terenkripsi (Encrypter CI4) untuk cetak ulang QR; NULL = tidak ada token aktif',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_nip_bulan_tahun` (`nip`, `bulan`, `tahun`),
                UNIQUE KEY `uk_qr_token` (`qr_token`),
                KEY `idx_nip` (`nip`),
                KEY `idx_bulan_tahun` (`bulan`, `tahun`),
                CONSTRAINT `chk_gaji_pegawai_bulan` CHECK (`bulan` BETWEEN 1 AND 12),
                CONSTRAINT `chk_gaji_pegawai_tahun` CHECK (`tahun` BETWEEN 1900 AND 2100),
                CONSTRAINT `chk_gaji_pegawai_nominal` CHECK ({$cek})
            ) " . self::TABLE_OPTIONS . " COMMENT='Data rincian slip gaji bulanan pegawai SIMPEG'",
            'gaji_pegawai_qr_history' => "CREATE TABLE {$this->t('gaji_pegawai_qr_history')} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_gaji_pegawai` BIGINT UNSIGNED NOT NULL,
                `qr_token` VARCHAR(64) NOT NULL COMMENT 'Hash SHA-256 heks token QR yang diterbitkan',
                `viewed_at` DATETIME NOT NULL COMMENT 'UTC saat token terbit (slip dibuka)',
                `viewed_ip` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Alamat IP saat token terbit; tidak ditampilkan di verifikasi',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                `snapshot_satker` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Nama satker jabatan pegawai saat token terbit (verifikasi AS-06)',
                `snapshot_nama` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Nama pegawai tersamar saat token terbit (verifikasi AS-06)',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_gpqh_qr_token` (`qr_token`),
                KEY `idx_id_gaji_pegawai` (`id_gaji_pegawai`),
                CONSTRAINT `fk_gpqh_id_gaji_pegawai` FOREIGN KEY (`id_gaji_pegawai`) REFERENCES {$this->t('gaji_pegawai')} (`id`) " . self::RESTRICT . '
            ) ' . self::TABLE_OPTIONS
                . " COMMENT='Riwayat setiap token QR verifikasi yang pernah diterbitkan per slip gaji, supaya PDF lama tetap bisa diverifikasi walau token aktif sudah berganti (setelah admin buka-akses-ulang).'",
        ];
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-023: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-023: DDL gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
