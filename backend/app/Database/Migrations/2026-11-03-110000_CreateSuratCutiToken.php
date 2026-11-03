<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-030 (Fase 6 Jalur B, MAKE-065 F6-18 Q6-22/Q6-23) — disetujui DB Validator @227805a9 (validasi ulang §13), dipindah
 * dari `MigrationsDitahan/` tanpa perubahan DDL (pola DBV-024/DBV-027). Dokumen:
 * backend/docs/db-review/DBV-030-token-surat-cuti.md.
 *
 * `surat_cuti_token` [V2] — satu token publik acak per surat cuti (keputusan Horii 03-11-2026, kartu Catatan Teknis):
 *   - dipakai DUA URL publik tanpa login: PDF surat ber-TTE (isi QR `imageTTD` tanda tangan BSrE, K3b — ikut legacy yang
 *     memuat URL langsung ke berkas) dan halaman verifikasi data minimal (QR kaki surat, K3c — pola slip D-14, ADR-032);
 *   - DB hanya menyimpan `token_hash` = SHA-256 heks token (UNIQUE, dicari saat verifikasi) dan `token_enkripsi` =
 *     token terenkripsi Encrypter CI4 untuk mencetak ulang QR (pola `gaji_pegawai.qr_token_enkripsi`, Q5A-01 opsi a);
 *     token mentah tidak pernah disimpan, dicatat, atau dikirim ke klien selain di dalam QR;
 *   - `status` 1 aktif / 2 nonaktif (surat dicabut/dibatalkan → URL 404); domain status proyek 1/2/10 — 10 tidak
 *     dipakai (baris ikut dihapus bila cuti dihapus keras);
 *   - satu baris per cuti (UNIQUE `id_riwayat_cuti`); token baru untuk cuti yang sama = UPDATE (rotasi) tercatat di
 *     `audit_logs`, bukan baris baru;
 *   - FK `id_riwayat_cuti` → `riwayat_cuti` RESTRICT (ADR-008): `riwayat_cuti` dihapus keras oleh `CutiService::hapus`
 *     (role 1), sehingga aplikasi menghapus baris token di transaksi yang sama sebelum menghapus cuti;
 *   - tanpa kolom NIP (URL tidak memuat NIP — beda dari legacy `ARSIP/{nip}/cuti/…`); registry NIP tidak berubah.
 *
 * Tabel baru tanpa data lama → tanpa pra-cek data. up() gagal → tabel yang sempat dibuat di-DROP; down() men-DROP tabel.
 */
class CreateSuratCutiToken extends Migration
{
    public const TABEL = 'surat_cuti_token';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        try {
            $this->exec(
                "CREATE TABLE {$this->t(self::TABEL)} (
                    `id_surat_cuti_token` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_riwayat_cuti` INT NOT NULL COMMENT 'Surat cuti pemilik token (satu token per cuti)',
                    `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 heks token publik; token mentah tidak disimpan',
                    `token_enkripsi` VARBINARY(255) NOT NULL COMMENT 'Token terenkripsi Encrypter CI4 untuk cetak ulang QR',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 nonaktif (surat dicabut/dibatalkan → 404)',
                    `nonaktif_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC saat dinonaktifkan',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pemicu terbit (tanpa FK, pola kolom pelaku)',
                    PRIMARY KEY (`id_surat_cuti_token`),
                    UNIQUE KEY `uk_sct_id_riwayat_cuti` (`id_riwayat_cuti`),
                    UNIQUE KEY `uk_sct_token_hash` (`token_hash`),
                    CONSTRAINT `fk_sct_id_riwayat_cuti` FOREIGN KEY (`id_riwayat_cuti`) REFERENCES {$this->t('riwayat_cuti')} (`id_riwayat_cuti`) " . self::RESTRICT . ',
                    CONSTRAINT `chk_surat_cuti_token_status` CHECK (`status` IN (1, 2)),
                    CONSTRAINT `chk_surat_cuti_token_nonaktif` CHECK ((`status` = 1 AND `nonaktif_at` IS NULL) OR (`status` = 2 AND `nonaktif_at` IS NOT NULL))
                ) ' . self::TABLE_OPTIONS . " COMMENT='Token publik acak per surat cuti: URL PDF ber-TTE (QR tanda tangan) dan halaman verifikasi (QR kaki surat)'",
                'CREATE TABLE surat_cuti_token',
            );
        } catch (Throwable $e) {
            try {
                $this->exec('DROP TABLE IF EXISTS ' . $this->t(self::TABEL), 'DROP TABLE surat_cuti_token');
            } catch (Throwable) {
                // Galat asli lebih penting.
            }

            throw $e;
        }
    }

    public function down(): void
    {
        $this->exec('DROP TABLE IF EXISTS ' . $this->t(self::TABEL), 'DROP TABLE surat_cuti_token');
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-030: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-030: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
