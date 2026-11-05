<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-026 (Fase 6 Jalur A, H-01 bagian A, PRD §7.0.1/§7.1) — disetujui DB Validator @79f00bb5 (04-11-2026), dipindah dari
 * `MigrationsDitahan/` tanpa perubahan DDL (pola DBV-027/DBV-030). Dokumen:
 * backend/docs/db-review/DBV-026-klien-layanan-simapi.md.
 *
 * Enam tabel baru, tanpa ALTER tabel lama dan tanpa data lama (F6-08: kredensial klien lama tidak diimpor; token,
 * riwayat perubahan, dan penanda kirim mulai kosong):
 *   - `klien_layanan` [V2] — pengganti `simapi_auth`/`sal_user`/konstanta `MUDIG_*`: rahasia di-hash (BP-8), jenis 1/2/3,
 *     hak per grup endpoint (SET), allowlist IP opsional, status 1/2/10;
 *   - `simapi_token` [K-kode]+[V2] — token opak disimpan sebagai SHA-256 (`token_hash` PK), satu baris per handshake
 *     (F6-05), FK ke klien;
 *   - `simapi_jd` [K-kode] — master jenis data change history; isi (seed) menunggu D1 (DBV-026-Q3);
 *   - `simapi_auth_jd` [K-kode] — filter jenis data per klien (legacy kunci `username`, v2 `id_klien`);
 *   - `simapi_ch` [K-kode] — baris perubahan (format pipe), diisi aplikasi sesudah commit (F6-06, MAKE-060);
 *   - `simapi_auth_ch` [K-kode] — penanda baris sudah dikirim per klien (ack-on-read).
 *
 * `simapi_ch.nip` tanpa FK (log riwayat; baris DELETE pegawai tetap tersimpan, tidak ikut Ubah NIP) — DBV-026-Q6:
 * di luar registry NIP, tercatat di README modul Kepegawaian.
 * up() gagal → tabel yang sempat dibuat di-DROP (urutan anak dulu); down() men-DROP keenam tabel.
 */
class CreateKlienLayananSimapi extends Migration
{
    /** Urutan pembuatan (induk dulu); DROP memakai urutan terbalik. */
    public const TABEL = ['klien_layanan', 'simapi_jd', 'simapi_token', 'simapi_auth_jd', 'simapi_ch', 'simapi_auth_ch'];

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        try {
            foreach (self::TABEL as $tabel) {
                $this->exec($this->ddl($tabel), "CREATE TABLE {$tabel}");
            }
        } catch (Throwable $e) {
            try {
                $this->down();
            } catch (Throwable) {
                // Galat asli lebih penting.
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABEL) as $tabel) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($tabel), "DROP TABLE {$tabel}");
        }
    }

    private function ddl(string $tabel): string
    {
        $r = self::RESTRICT;

        return match ($tabel) {
            'klien_layanan' => "CREATE TABLE {$this->t('klien_layanan')} (
                    `id_klien` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `nama` VARCHAR(150) NOT NULL COMMENT 'Nama sistem pemanggil (mis. Simanta, Pusdatin, MuDig)',
                    `username` VARCHAR(100) NOT NULL COMMENT 'User HTTP Basic [K simapi_auth.username]',
                    `rahasia_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash rahasia klien; rahasia mentah tidak disimpan (BP-8)',
                    `jenis` TINYINT NOT NULL COMMENT '1 SIMAPI lama/Pusdatin, 2 SIMAPI baru/Simanta [K simapi_auth.type], 3 layanan/MuDig',
                    `hak` SET('simapi','stat','dm','esign','sinkron') NOT NULL DEFAULT '' COMMENT 'Grup endpoint yang boleh dipanggil',
                    `ip_allowlist` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'IP/CIDR dipisah koma; NULL = semua IP',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 nonaktif, 10 dihapus',
                    `rahasia_diubah_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC rotasi rahasia terakhir',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC saat status 10',
                    `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK; NULL = command CLI)',
                    `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK; NULL = command CLI)',
                    PRIMARY KEY (`id_klien`),
                    UNIQUE KEY `uk_klien_layanan_username` (`username`),
                    CONSTRAINT `chk_klien_layanan_jenis` CHECK (`jenis` IN (1, 2, 3)),
                    CONSTRAINT `chk_klien_layanan_status` CHECK (`status` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Klien antar-sistem (SIMAPI dan services/*); pengganti simapi_auth, sal_user, MUDIG_*'",
            'simapi_jd' => "CREATE TABLE {$this->t('simapi_jd')} (
                    `id_simapi_jd` INT NOT NULL AUTO_INCREMENT,
                    `jenis_data` VARCHAR(100) NOT NULL COMMENT 'Nama jenis data yang dikirim change_history [K AE:84]',
                    PRIMARY KEY (`id_simapi_jd`),
                    UNIQUE KEY `uk_simapi_jd_jenis_data` (`jenis_data`)
                ) " . self::TABLE_OPTIONS . " COMMENT='Master jenis data change history SIMAPI'",
            'simapi_token' => "CREATE TABLE {$this->t('simapi_token')} (
                    `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 heks token opak 30 karakter; token mentah tidak disimpan',
                    `id_klien` INT UNSIGNED NOT NULL,
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 dicabut',
                    `expired_at` DATETIME NOT NULL COMMENT 'UTC; terbit + 1 jam [K A_auth.php:77-78]',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    PRIMARY KEY (`token_hash`),
                    KEY `idx_simapi_token_klien` (`id_klien`, `expired_at`),
                    KEY `idx_simapi_token_expired` (`expired_at`),
                    CONSTRAINT `fk_simapi_token_klien` FOREIGN KEY (`id_klien`) REFERENCES {$this->t('klien_layanan')} (`id_klien`) {$r},
                    CONSTRAINT `chk_simapi_token_status` CHECK (`status` IN (1, 2))
                ) " . self::TABLE_OPTIONS . " COMMENT='Token SIMAPI (hash), satu baris per handshake'",
            'simapi_auth_jd' => "CREATE TABLE {$this->t('simapi_auth_jd')} (
                    `id_klien` INT UNSIGNED NOT NULL,
                    `id_simapi_jd` INT NOT NULL,
                    PRIMARY KEY (`id_klien`, `id_simapi_jd`),
                    KEY `idx_simapi_auth_jd_jd` (`id_simapi_jd`),
                    CONSTRAINT `fk_simapi_auth_jd_klien` FOREIGN KEY (`id_klien`) REFERENCES {$this->t('klien_layanan')} (`id_klien`) {$r},
                    CONSTRAINT `fk_simapi_auth_jd_jd` FOREIGN KEY (`id_simapi_jd`) REFERENCES {$this->t('simapi_jd')} (`id_simapi_jd`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Filter jenis data change history per klien (tanpa baris = semua jenis)'",
            'simapi_ch' => "CREATE TABLE {$this->t('simapi_ch')} (
                    `id_simapi_ch` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_simapi_jd` INT NOT NULL,
                    `id_rwy` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Id baris sumber (riwayat) [K-kode AE:84; tipe dicocokkan D1]',
                    `nip` VARCHAR(30) NULL DEFAULT NULL COMMENT 'NIP pemilik data saat perubahan; tanpa FK (log riwayat)',
                    `action` TINYINT NOT NULL COMMENT '1 INSERT / UPDATE, 2 DELETE [K-kode AE:98]',
                    `action_at` DATETIME NOT NULL COMMENT 'Waktu perubahan (zona: DBV-026-Q5)',
                    `field_name` TEXT NULL COMMENT 'Nama kolom dipisah pipe [K]',
                    `field_value` TEXT NULL COMMENT 'Nilai kolom dipisah pipe [K]',
                    PRIMARY KEY (`id_simapi_ch`),
                    KEY `idx_simapi_ch_jd` (`id_simapi_jd`, `id_simapi_ch`),
                    KEY `idx_simapi_ch_nip` (`nip`),
                    CONSTRAINT `fk_simapi_ch_jd` FOREIGN KEY (`id_simapi_jd`) REFERENCES {$this->t('simapi_jd')} (`id_simapi_jd`) {$r},
                    CONSTRAINT `chk_simapi_ch_action` CHECK (`action` IN (1, 2))
                ) " . self::TABLE_OPTIONS . " COMMENT='Riwayat perubahan data pegawai untuk SIMAPI change_history'",
            'simapi_auth_ch' => "CREATE TABLE {$this->t('simapi_auth_ch')} (
                    `id_klien` INT UNSIGNED NOT NULL,
                    `id_simapi_ch` BIGINT UNSIGNED NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC saat dikirim',
                    PRIMARY KEY (`id_klien`, `id_simapi_ch`),
                    KEY `idx_simapi_auth_ch_ch` (`id_simapi_ch`),
                    CONSTRAINT `fk_simapi_auth_ch_klien` FOREIGN KEY (`id_klien`) REFERENCES {$this->t('klien_layanan')} (`id_klien`) {$r},
                    CONSTRAINT `fk_simapi_auth_ch_ch` FOREIGN KEY (`id_simapi_ch`) REFERENCES {$this->t('simapi_ch')} (`id_simapi_ch`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Penanda baris simapi_ch sudah dikirim ke klien (ack-on-read)'",
            default => throw new RuntimeException("DBV-026: tabel {$tabel} tidak dikenal."),
        };
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-026: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-026: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
