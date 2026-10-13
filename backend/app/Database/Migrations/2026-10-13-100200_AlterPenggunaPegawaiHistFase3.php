<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DBV-020 (3/3) — dua perubahan kolom [V2] kebutuhan Fase 3.
 * Review DB Validator: backend/docs/db-review/DBV-020-skema-tambahan-fase3.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui.
 *
 * 1. `pengguna.must_change_password` TINYINT(1) NOT NULL DEFAULT 0, sesudah `expired_at` [V2]. Penanda "wajib ganti
 *    password saat login berikutnya" setelah password direset ke NIP oleh Koreksi NIP (MAKE-010, ikut legacy
 *    `L_employee.php` :589-599) atau oleh reset admin. Legacy tidak punya kolom ini: popup paksa ganti password legacy
 *    dihitung saat login dari `md5(NIP) = password` (role 2/6) atau `expired_at` lewat (`core/MY_Controller.php`
 *    :209-238); v2 menyimpan hash Argon2id sehingga perbandingan itu tidak dipakai. Migration ini hanya menambah kolom
 *    (semua akun 0); pengisian dan penegakan di login/frontend = pekerjaan MAKE terpisah setelah DBV approve.
 * 2. `pegawai_hist.glr_awal` VARCHAR(12) → VARCHAR(50) dan `glr_akhir` VARCHAR(24) → VARCHAR(50) [V2], sama dengan
 *    `pegawai.glr_awal`/`glr_akhir` (D1:2506-2507). D1 pegawai_hist (D1:2948-2949) masih memakai lebar lama, sehingga
 *    pengajuan biodata yang menyalin gelar pegawai > 12/24 karakter gagal. MODIFY mempertahankan NULL, DEFAULT NULL,
 *    dan collation utf8mb4_unicode_ci; lebar byte maksimal tetap ≤ 255 (prefix panjang 1 byte), jadi MySQL 8 /
 *    MariaDB 10.4 dapat mengerjakannya in-place tanpa menyalin tabel (dokumen Bagian 5).
 *
 * up(): bila ALTER kedua gagal, ALTER pertama dibatalkan lagi sebelum error dilempar ulang.
 * down(): fail-closed — ditolak sebelum ALTER apa pun bila ada gelar di `pegawai_hist` yang lebih panjang dari lebar
 * lama (memotong data diam-diam tidak diizinkan). Nilai `must_change_password` hilang saat rollback.
 */
class AlterPenggunaPegawaiHistFase3 extends Migration
{
    /**
     * Lebar gelar: kolom => [lama (D1 pegawai_hist), baru (= pegawai)].
     */
    public const LEBAR_GELAR = [
        'glr_awal'  => [12, 50],
        'glr_akhir' => [24, 50],
    ];

    public function up(): void
    {
        $this->exec("ALTER TABLE {$this->t('pengguna')} ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '1: wajib ganti password saat login berikutnya (reset admin / Koreksi NIP), 0: tidak' AFTER `expired_at`");

        try {
            $this->ubahLebarGelar(1);
        } catch (Throwable $e) {
            try {
                $this->db->query("ALTER TABLE {$this->t('pengguna')} DROP COLUMN `must_change_password`");
            } catch (Throwable) {
                // Error asli tetap dilempar; kolom dibersihkan manual (dokumen DBV-020 Bagian 7).
            }

            throw $e;
        }
    }

    public function down(): void
    {
        $problems = [];

        foreach (self::LEBAR_GELAR as $kolom => [$lama]) {
            $row = $this->first("SELECT COUNT(*) AS n FROM {$this->t('pegawai_hist')} WHERE CHAR_LENGTH(`{$kolom}`) > {$lama}");
            $n   = (int) ($row['n'] ?? 0);

            if ($n > 0) {
                $problems[] = "pegawai_hist.{$kolom}: {$n} baris lebih dari {$lama} karakter";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('DBV-020: rollback ditolak — ' . implode('; ', $problems) . '.');
        }

        $this->ubahLebarGelar(0);

        if (array_key_exists('must_change_password', $this->kolom('pengguna'))) {
            $this->exec("ALTER TABLE {$this->t('pengguna')} DROP COLUMN `must_change_password`");
        }
    }

    /**
     * @param int $indeks 0 = lebar lama, 1 = lebar baru
     */
    private function ubahLebarGelar(int $indeks): void
    {
        $specs = [];

        foreach (self::LEBAR_GELAR as $kolom => $lebar) {
            $specs[] = "MODIFY `{$kolom}` VARCHAR({$lebar[$indeks]}) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL";
        }

        $this->exec("ALTER TABLE {$this->t('pegawai_hist')} " . implode(', ', $specs));
    }

    /**
     * @return array<string, true>
     */
    private function kolom(string $table): array
    {
        $result = $this->connection()->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->connection()->prefixTable($table)],
        );

        if (! $result instanceof ResultInterface) {
            throw new RuntimeException('DBV-020: gagal membaca kolom ' . $table . '.');
        }

        return array_fill_keys(array_column($result->getResultArray(), 'COLUMN_NAME'), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function first(string $sql): array
    {
        $result = $this->connection()->query($sql);

        if (! $result instanceof ResultInterface) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-020: query gagal (' . $error['code'] . '): ' . $error['message']);
        }

        return $result->getRowArray() ?? [];
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        return $this->connection()->escapeIdentifiers($this->connection()->prefixTable($table));
    }

    private function connection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-020: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-020: ALTER gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
