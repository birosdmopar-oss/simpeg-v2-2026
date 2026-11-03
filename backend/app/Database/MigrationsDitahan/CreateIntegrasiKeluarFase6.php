<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-027 (Fase 6 Jalur B, H-01 bagian B) — DRAF DITAHAN sampai disetujui DB Validator; dipindah ke `Migrations/` dengan
 * timestamp baru setelah approve (pola DBV-024). Dokumen: backend/docs/db-review/DBV-027-integrasi-keluar.md.
 *
 * Isi (PRD Fase 6 §7.1, F6-14, F6-15, F6-24, §8):
 *   - `integrasi_log` [V2] — log integrasi masuk/keluar teredaksi (F6-24), retensi 180 hari oleh command.
 *   - `siasn_status_pegawai` [K padanan `siasn_simpeg.dsp`] — stempel & flag per jenis tarik SIASN per NIP.
 *   - `siasn_upload_arsip` [K tabel `simpeg01`, kolom [I] dari kode `SS/Arsip`, `SS/Rw_jabatan::set_queue_arsip`].
 *   - `double_riwayat_kp` [K tabel `simpeg01`, kolom [I] dari `SS/Rw_gol`] — NIP dengan golongan ganda di SIASN.
 *   - `siasn_ref_unor` [K padanan `siasn_simpeg.REF_UNOR`, kolom dari `SS/Ref_unor::sync`].
 *   - `siasn_referensi` [V2 gabungan 7 tabel referensi `siasn_simpeg.*_ID`/`TK_PENDIDIKAN` yang dipakai job aktif].
 *   - ALTER `firebase_token`: `nip` NULL-able, `id_pengguna`, `platform` 1 web / 2 android, `token_hash` UNIQUE per pemilik,
 *     `refresh_date` [K `fb_token`] + CHECK pemilik token per platform (F6-13, DQ-25).
 *   - ALTER `riwayat_skp_periodik` UNIQUE (`id_pns`, `periode_id`, `skp_id`) (Q6-10) — pra-cek duplikat lebih dulu.
 *   - TANPA seed akun: akun sistem integrasi dibuat command `integrasi:siapkan-akun-sistem` sesudah impor `pengguna`
 *     (keputusan DBV Z-1 opsi c, DBV-027 §4) — migration ini murni skema.
 *
 * Semua pra-cek dijalankan SEBELUM DDL apa pun; DDL MySQL ter-commit per statement, jadi bila satu langkah gagal up()
 * membatalkan langkah yang sudah jalan pada run itu (urutan terbalik) lalu melempar ulang galat. down() kebalikan up().
 */
class CreateIntegrasiKeluarFase6 extends Migration
{
    public const TABEL_BARU = [
        'integrasi_log',
        'siasn_status_pegawai',
        'siasn_upload_arsip',
        'double_riwayat_kp',
        'siasn_ref_unor',
        'siasn_referensi',
    ];

    public const JENIS_REFERENSI = [
        'jabatan_fungsional', 'jabatan_fungsional_umum', 'jenis_jabatan', 'latihan_struktural', 'tk_pendidikan',
        'jenis_kp', 'golongan',
    ];

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        $this->praCek();

        /** @var list<callable(): void> $batal */
        $batal = [];

        try {
            foreach (self::TABEL_BARU as $tabel) {
                $this->exec($this->create($tabel), "CREATE TABLE {$tabel}");
                $batal[] = fn () => $this->exec('DROP TABLE IF EXISTS ' . $this->t($tabel), "DROP TABLE {$tabel}");
            }

            $this->alterFirebaseToken();
            $batal[] = fn () => $this->kembalikanFirebaseToken();

            $this->exec(
                "ALTER TABLE {$this->t('riwayat_skp_periodik')} ADD UNIQUE KEY `uq_rsp_pns_periode_skp` (`id_pns`, `periode_id`, `skp_id`)",
                'UNIQUE riwayat_skp_periodik',
            );
            $batal[] = fn () => $this->exec("ALTER TABLE {$this->t('riwayat_skp_periodik')} DROP INDEX `uq_rsp_pns_periode_skp`", 'DROP UNIQUE riwayat_skp_periodik');
        } catch (Throwable $e) {
            foreach (array_reverse($batal) as $langkah) {
                try {
                    $langkah();
                } catch (Throwable) {
                    // Galat asli lebih penting; langkah pembatalan berikutnya tetap dicoba.
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        if ($this->adaKolom('firebase_token', 'platform')) {
            $this->kembalikanFirebaseToken();
        }

        if ($this->adaIndeks('riwayat_skp_periodik', 'uq_rsp_pns_periode_skp')) {
            $this->exec("ALTER TABLE {$this->t('riwayat_skp_periodik')} DROP INDEX `uq_rsp_pns_periode_skp`", 'DROP UNIQUE riwayat_skp_periodik');
        }

        foreach (array_reverse(self::TABEL_BARU) as $tabel) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($tabel), "DROP TABLE {$tabel}");
        }
    }

    /**
     * Pra-cek sebelum DDL apa pun (tanpa NIP/nilai di pesan): duplikat kunci UNIQUE `riwayat_skp_periodik` (aturan
     * pemilihan baris diputus DBV — DBV-027 §5), duplikat token FCM (langkah dedup DBV-027 §3.7), baris token lama tanpa
     * `nip` (melanggar CHECK pemilik setelah menjadi platform 2).
     */
    private function praCek(): void
    {
        $dupSkp = (int) ($this->db->query(
            "SELECT COUNT(*) AS n FROM (SELECT 1 FROM {$this->t('riwayat_skp_periodik')}
             WHERE `id_pns` IS NOT NULL AND `periode_id` IS NOT NULL AND `skp_id` IS NOT NULL
             GROUP BY `id_pns`, `periode_id`, `skp_id` HAVING COUNT(*) > 1) d",
        )->getRow()->n ?? 0);

        if ($dupSkp > 0) {
            throw new RuntimeException("DBV-027: {$dupSkp} kunci (id_pns, periode_id, skp_id) ganda di riwayat_skp_periodik; selesaikan dengan aturan DBV-027 §5 sebelum migrate.");
        }

        $dupToken = (int) ($this->db->query(
            "SELECT COUNT(*) AS n FROM (SELECT 1 FROM {$this->t('firebase_token')} WHERE `fcm_token` IS NOT NULL
             GROUP BY `nip`, SHA2(`fcm_token`, 256) HAVING COUNT(*) > 1) d",
        )->getRow()->n ?? 0);

        if ($dupToken > 0) {
            throw new RuntimeException("DBV-027: {$dupToken} token FCM ganda per pemilik (nip) di firebase_token; deduplikasi sebelum migrate (DBV-027 §3.7).");
        }

        $tanpaNip = (int) ($this->db->query(
            "SELECT COUNT(*) AS n FROM {$this->t('firebase_token')} WHERE `nip` IS NULL",
        )->getRow()->n ?? 0);

        if ($tanpaNip > 0) {
            throw new RuntimeException("DBV-027: {$tanpaNip} baris firebase_token tanpa nip; baris lama menjadi platform 2 dan wajib ber-nip (CHECK pemilik).");
        }
    }

    private function alterFirebaseToken(): void
    {
        $t = $this->t('firebase_token');

        $this->exec(
            "ALTER TABLE {$t}
                MODIFY `nip` VARCHAR(30) NULL DEFAULT NULL COMMENT 'Pemilik token Android (platform 2); NULL untuk token web (pemilik = id_pengguna)',
                ADD COLUMN `id_pengguna` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Akun pemilik token web (platform 1) [K fb_token.id_user]' AFTER `nip`,
                ADD COLUMN `platform` TINYINT NOT NULL DEFAULT 2 COMMENT '1: web/desktop (padanan fb_token), 2: android (padanan firebase_token legacy)' AFTER `id_pengguna`,
                ADD COLUMN `token_hash` CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 hex fcm_token; unik per pemilik (DQ-25, DBV-Q8 ikut legacy)' AFTER `fcm_token`,
                ADD COLUMN `refresh_date` DATETIME NULL DEFAULT NULL COMMENT 'UTC; diperpanjang 1 minggu saat SPA mendaftar ulang [K fb_token.refresh_date]' AFTER `token_hash`",
            'ALTER firebase_token kolom',
        );

        $this->exec("UPDATE {$t} SET `token_hash` = SHA2(`fcm_token`, 256) WHERE `fcm_token` IS NOT NULL", 'UPDATE firebase_token token_hash');

        $this->exec(
            "ALTER TABLE {$t}
                ALTER COLUMN `platform` DROP DEFAULT,
                ADD UNIQUE KEY `uq_firebase_token_pengguna_hash` (`id_pengguna`, `token_hash`),
                ADD UNIQUE KEY `uq_firebase_token_nip_hash` (`nip`, `token_hash`),
                ADD CONSTRAINT `fk_id_pengguna_fbt_to_pengguna` FOREIGN KEY (`id_pengguna`) REFERENCES {$this->t('pengguna')} (`id_pengguna`) " . self::RESTRICT . ',
                ADD CONSTRAINT `chk_firebase_token_platform` CHECK (`platform` IN (1, 2)),
                ADD CONSTRAINT `chk_firebase_token_pemilik` CHECK ((`platform` = 1 AND `id_pengguna` IS NOT NULL AND `nip` IS NULL) OR (`platform` = 2 AND `nip` IS NOT NULL))',
            'ALTER firebase_token constraint',
        );
    }

    /**
     * Kembali ke skema DBV-022 (`nip` NOT NULL). Token web (`nip` NULL, pemilik = akun) tidak bisa disimpan di skema lama →
     * baris itu dihapus lebih dulu (token web hanya cache pendaftaran ulang SPA, dibuat lagi saat halaman dimuat).
     *
     * Constraint dilepas dengan `DROP CONSTRAINT` / `DROP FOREIGN KEY` (MySQL ≥ 8.0.19 dan MariaDB ≥ 10.2; `DROP CHECK`
     * khusus MySQL — temuan T1 DBV) dan hanya bila ada (pembatalan up() di tengah jalan); galat lain tidak ditelan.
     */
    private function kembalikanFirebaseToken(): void
    {
        $t = $this->t('firebase_token');

        foreach (['chk_firebase_token_pemilik' => 'DROP CONSTRAINT', 'chk_firebase_token_platform' => 'DROP CONSTRAINT', 'fk_id_pengguna_fbt_to_pengguna' => 'DROP FOREIGN KEY'] as $nama => $aksi) {
            if ($this->adaConstraint('firebase_token', $nama)) {
                $this->exec("ALTER TABLE {$t} {$aksi} `{$nama}`", "ALTER firebase_token {$aksi} {$nama}");
            }
        }

        $this->exec("DELETE FROM {$t} WHERE `nip` IS NULL", 'DELETE token web (tanpa NIP)');

        foreach (['uq_firebase_token_pengguna_hash', 'uq_firebase_token_nip_hash'] as $indeks) {
            if ($this->adaIndeks('firebase_token', $indeks)) {
                $this->exec("ALTER TABLE {$t} DROP INDEX `{$indeks}`", "DROP INDEX {$indeks}");
            }
        }

        $kolom  = array_values(array_filter(['refresh_date', 'token_hash', 'platform', 'id_pengguna'], fn (string $k): bool => $this->adaKolom('firebase_token', $k)));
        $spec   = array_map(static fn (string $k): string => "DROP COLUMN `{$k}`", $kolom);
        $spec[] = 'MODIFY `nip` VARCHAR(30) NOT NULL';

        $this->exec("ALTER TABLE {$t} " . implode(', ', $spec), 'ALTER firebase_token kembali');
    }

    private function create(string $tabel): string
    {
        $o = self::TABLE_OPTIONS;

        return match ($tabel) {
            'integrasi_log' => "CREATE TABLE {$this->t('integrasi_log')} (
                `id_integrasi_log` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `arah` TINYINT NOT NULL COMMENT '1: keluar (v2 memanggil layanan luar), 2: masuk (klien memanggil v2)',
                `layanan` VARCHAR(30) NOT NULL COMMENT 'esign, bkn, ekinerja, siasn, fcm, simapi, services',
                `operasi` VARCHAR(100) NOT NULL COMMENT 'Nama operasi/endpoint tanpa query string',
                `status` TINYINT NOT NULL COMMENT '1: sukses, 2: gagal',
                `http_status` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Kode HTTP; NULL = tidak ada respons (timeout/koneksi)',
                `kode` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Kode hasil layanan/gateway',
                `pesan` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Ringkasan teredaksi (tanpa token, passphrase, NIK, secret; BP-5)',
                `durasi_ms` INT UNSIGNED NULL DEFAULT NULL,
                `percobaan` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Urutan percobaan (retry GET, BP-3)',
                `ref_id` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Rujukan bisnis (mis. id riwayat, id periode)',
                `id_klien` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Klien layanan untuk arah masuk (DBV-026, Jalur A); tanpa FK',
                `created_at` DATETIME NOT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`id_integrasi_log`),
                KEY `idx_il_created` (`created_at`),
                KEY `idx_il_layanan_created` (`layanan`, `created_at`),
                KEY `idx_il_status_created` (`status`, `created_at`),
                KEY `idx_il_layanan_ref` (`layanan`, `ref_id`),
                CONSTRAINT `chk_integrasi_log_arah` CHECK (`arah` IN (1, 2)),
                CONSTRAINT `chk_integrasi_log_status` CHECK (`status` IN (1, 2))
            ) {$o} COMMENT='Log integrasi masuk/keluar teredaksi (F6-24, retensi 180 hari)'",

            'siasn_status_pegawai' => "CREATE TABLE {$this->t('siasn_status_pegawai')} (
                `nip` VARCHAR(30) NOT NULL,
                `du` TINYINT NOT NULL DEFAULT 0 COMMENT 'Data utama: 0 perlu/selesai siklus, 1 selesai, 2 diproses [K dsp.du]',
                `du_lu` DATE NULL DEFAULT NULL COMMENT 'Tanggal sinkron data utama terakhir [K dsp.du_lu Ymd]',
                `rw_gol` TINYINT NOT NULL DEFAULT 0, `rw_gol_lu` DATETIME NULL DEFAULT NULL,
                `rw_didik` TINYINT NOT NULL DEFAULT 0, `rw_didik_lu` DATETIME NULL DEFAULT NULL,
                `rw_pmk` TINYINT NOT NULL DEFAULT 0, `rw_pmk_lu` DATETIME NULL DEFAULT NULL,
                `rw_skp22` TINYINT NOT NULL DEFAULT 0, `rw_skp22_lu` DATETIME NULL DEFAULT NULL,
                `rw_ak` TINYINT NOT NULL DEFAULT 0, `rw_ak_lu` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`nip`),
                CONSTRAINT `fk_nip_ssp_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
                CONSTRAINT `chk_siasn_status_pegawai_flag` CHECK (`du` IN (0, 1, 2) AND `rw_gol` IN (0, 1, 2) AND `rw_didik` IN (0, 1, 2)
                    AND `rw_pmk` IN (0, 1, 2) AND `rw_skp22` IN (0, 1, 2) AND `rw_ak` IN (0, 1, 2))
            ) {$o} COMMENT='Status tarik SIASN per NIP per jenis (padanan siasn_simpeg.dsp, F6-14); stempel _lu UTC'",

            'siasn_upload_arsip' => "CREATE TABLE {$this->t('siasn_upload_arsip')} (
                `id_siasn_upload_arsip` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `rw_name` VARCHAR(50) NOT NULL COMMENT 'Rw Jabatan | Rw Sertifikasi [K]',
                `id_rw` INT NOT NULL COMMENT 'PK baris riwayat sumber (riwayat_mutasi_jabatan / riwayat_diklat)',
                `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL COMMENT 'id riwayat di SIASN (tujuan unggah)',
                `id_da` INT NOT NULL COMMENT 'document_attachment.id_attachment sumber berkas (selalu diisi [K]); tanpa FK',
                `arsip_path` VARCHAR(200) NULL DEFAULT NULL COMMENT 'Salinan document_attachment.path saat diantre',
                `arsip_name` VARCHAR(255) NULL DEFAULT NULL,
                `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0: antre, 1: terunggah, 2: gagal, 3: diproses [K]',
                `error_msg` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Pesan teredaksi',
                `resp_dok_id` VARCHAR(100) NULL DEFAULT NULL,
                `resp_dok_nama` VARCHAR(255) NULL DEFAULT NULL,
                `resp_dok_uri` VARCHAR(500) NULL DEFAULT NULL,
                `resp_object` VARCHAR(255) NULL DEFAULT NULL,
                `resp_slug` VARCHAR(50) NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`id_siasn_upload_arsip`),
                UNIQUE KEY `uq_sua_rw_da` (`rw_name`, `id_rw`, `id_da`),
                KEY `idx_sua_status` (`status`, `id_siasn_upload_arsip`),
                KEY `idx_sua_nip` (`nip`),
                CONSTRAINT `fk_nip_sua_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
                CONSTRAINT `chk_siasn_upload_arsip_status` CHECK (`status` IN (0, 1, 2, 3))
            ) {$o} COMMENT='Antrean unggah dokumen riwayat ke SIASN (F6-14, SS/Arsip)'",

            'double_riwayat_kp' => "CREATE TABLE {$this->t('double_riwayat_kp')} (
                `nip` VARCHAR(30) NOT NULL,
                `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`nip`),
                CONSTRAINT `fk_nip_drkp_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . "
            ) {$o} COMMENT='NIP dengan golongan ganda di SIASN saat tarik golongan (SS/Rw_gol), untuk ditindaklanjuti admin'",

            'siasn_ref_unor' => "CREATE TABLE {$this->t('siasn_ref_unor')} (
                `id` VARCHAR(100) NOT NULL COMMENT 'Id unor SIASN',
                `nama_unor` VARCHAR(255) NULL DEFAULT NULL,
                `nama_jabatan` VARCHAR(255) NULL DEFAULT NULL,
                `eselon_id` VARCHAR(50) NULL DEFAULT NULL,
                `cepat_kode` VARCHAR(100) NULL DEFAULT NULL,
                `atasan_id` VARCHAR(100) NULL DEFAULT NULL,
                `induk_unor_id` VARCHAR(100) NULL DEFAULT NULL,
                `instansi_id` VARCHAR(100) NULL DEFAULT NULL,
                `is_unor_sekolah` TINYINT NULL DEFAULT NULL,
                `jenis_unor_id` VARCHAR(50) NULL DEFAULT NULL,
                `pemimpin_pns_id` VARCHAR(100) NULL DEFAULT NULL,
                `id_jabatan` INT NULL DEFAULT NULL COMMENT 'Pemetaan lokal jabatan v2 (diisi impor/admin)',
                `id_unit` INT NULL DEFAULT NULL COMMENT 'Pemetaan lokal unit v2',
                `id_satker` INT NULL DEFAULT NULL COMMENT 'Pemetaan lokal satker v2',
                `lvl_uker` TINYINT NULL DEFAULT NULL COMMENT 'Level unit kerja pemetaan lokal [K]',
                `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`id`),
                KEY `idx_sru_jabatan` (`id_jabatan`),
                KEY `idx_sru_unit_satker_lvl` (`id_unit`, `id_satker`, `lvl_uker`)
            ) {$o} COMMENT='Referensi unit organisasi SIASN + pemetaan lokal (padanan siasn_simpeg.REF_UNOR, F6-14)'",

            'siasn_referensi' => "CREATE TABLE {$this->t('siasn_referensi')} (
                `jenis` VARCHAR(40) NOT NULL COMMENT 'Padanan tabel referensi legacy siasn_simpeg.* (lihat CHECK)',
                `id_siasn` VARCHAR(100) NOT NULL COMMENT 'ID referensi SIASN [K kolom ID]',
                `nama` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Nama referensi SIASN [K kolom NAMA]',
                `id_lokal` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Pemetaan ke id master v2 (id_group_jabatan/id_diklat/id_jenjang_pendidikan/id_jenis_kp/id_pangkat)',
                `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`jenis`, `id_siasn`),
                KEY `idx_sr_jenis_lokal` (`jenis`, `id_lokal`),
                KEY `idx_sr_jenis_nama` (`jenis`, `nama`),
                CONSTRAINT `chk_siasn_referensi_jenis` CHECK (`jenis` IN ('" . implode("', '", self::JENIS_REFERENSI) . "'))
            ) {$o} COMMENT='Referensi SIASN yang dipakai job tarik/dorong (padanan 7 tabel siasn_simpeg, F6-14)'",

            default => throw new RuntimeException("DBV-027: tabel {$tabel} tidak dikenal."),
        };
    }

    private function adaKolom(string $tabel, string $kolom): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->nama($tabel), $kolom],
        )->getRow() !== null;
    }

    private function adaConstraint(string $tabel, string $nama): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [$this->nama($tabel), $nama],
        )->getRow() !== null;
    }

    private function adaIndeks(string $tabel, string $indeks): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$this->nama($tabel), $indeks],
        )->getRow() !== null;
    }

    private function t(string $table): string
    {
        $nama = $this->nama($table);

        return $this->db instanceof BaseConnection ? $this->db->escapeIdentifiers($nama) : $nama;
    }

    private function nama(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-027: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->prefixTable($table);
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-027: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
