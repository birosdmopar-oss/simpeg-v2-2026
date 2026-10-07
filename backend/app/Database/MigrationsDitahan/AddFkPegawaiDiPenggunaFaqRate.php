<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DITAHAN (DBV-019, keputusan #4; sebelumnya DBV-012 keputusan #14) — file ini sengaja di luar app/Database/Migrations
 * sehingga TIDAK dijalankan `php spark migrate`. Dipindah ke app/Database/Migrations dengan nama
 * `<timestamp>_AddFkPegawaiDiPenggunaFaqRate.php` (timestamp lebih besar dari migration terakhir di lingkungan target)
 * setelah prasyaratnya terpenuhi (dokumen backend/docs/db-review/DBV-019-fk-g02-pegawai-schema.md Bagian 1.2):
 *   (1) `pegawai` sudah terisi di lingkungan target (impor Tier 2 / B-05): `pengguna` berisi akun ber-NIP dan migration
 *       ini fail-closed selama ada NIP yang tidak ada di `pegawai`;
 *   (2) pembuatan/ubah akun ber-NIP (A-09 AccountProvisioner, Manajemen Akun) memvalidasi NIP ada di `pegawai` (422),
 *       supaya FK tidak berubah menjadi error server;
 *   (3) seed & test yang membuat akun/rating ber-NIP menyisipkan `pegawai` lebih dulu.
 * Isi dan pra-cek orphan tetap diuji Tests\Database\Kepegawaian\NipReferenceRegistryTest (dipasang lalu dilepas di test).
 *
 * DBV-012 — B-01: FK masuk ke `pegawai.nip` dari dua tabel yang sudah ada di `main` sebelum `pegawai` dibuat:
 *   - `fk_id_pegawai_pengguna_to_pegawai` [K D1]: `pengguna.nip` (NULL untuk akun non-pegawai, K2/DBV-010) →
 *     `pegawai.nip` (A-01 #4). Memakai UNIQUE `nip` yang sudah ada, tanpa KEY baru.
 *   - `fk_nip_faqrate_to_peg` [K D1]: `faq_rate.nip` → `pegawai.nip` (G-10 D2). KEY dengan nama ini sudah dibuat
 *     migration FAQ, jadi cukup ADD CONSTRAINT.
 * Keduanya ON DELETE RESTRICT ON UPDATE RESTRICT (legacy `pengguna` SET NULL/CASCADE, `faq_rate` CASCADE/CASCADE;
 * ganti NIP lewat B-06, K1).
 *
 * up() fail-closed: sebelum ALTER apa pun, baris yang NIP-nya tidak ada di `pegawai` (orphan) dihitung; bila ada,
 * migration berhenti dengan pesan jumlah per tabel (impor `pegawai` dulu, atau perbaiki/hapus baris orphan). FK yang
 * sudah ada dilewati; bila ALTER kedua gagal, FK pertama yang dipasang PADA RUN ITU dilepas lagi sebelum error dilempar
 * ulang. down() hanya melepas FK yang ada (index tetap, seperti sebelum up()).
 */
class AddFkPegawaiDiPenggunaFaqRate extends Migration
{
    /**
     * FK masuk: nama => [tabel anak, kolom, keterangan orphan untuk pesan error].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const FOREIGN_KEYS = [
        'fk_id_pegawai_pengguna_to_pegawai' => ['pengguna', 'nip', 'akun pengguna (termasuk yang soft-deleted)'],
        'fk_nip_faqrate_to_peg'             => ['faq_rate', 'nip', 'rating FAQ'],
    ];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        $this->assertNoOrphans();

        $added = [];

        try {
            foreach (self::FOREIGN_KEYS as $name => [$table, $column]) {
                if ($this->foreignKeyExists($table, $name)) {
                    continue;
                }

                $this->exec("ALTER TABLE {$this->t($table)} ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`)
                    REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT);
                $added[$name] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($added, true) as $name => $table) {
                try {
                    $this->db->query("ALTER TABLE {$this->t($table)} DROP FOREIGN KEY `{$name}`");
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa FK dibersihkan manual (dokumen DBV-019 Bagian 4.1).
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::FOREIGN_KEYS, true) as $name => [$table]) {
            if ($this->foreignKeyExists($table, $name)) {
                $this->exec("ALTER TABLE {$this->t($table)} DROP FOREIGN KEY `{$name}`");
            }
        }
    }

    /**
     * Pra-cek orphan (fail-closed). ADD FOREIGN KEY sendiri juga menolak orphan (1452), tetapi tanpa jumlah per tabel;
     * pesan ini memberi operator angka yang bisa ditindaklanjuti sebelum impor diulang.
     */
    private function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::FOREIGN_KEYS as [$table, $column, $label]) {
            $row = $this->first(
                "SELECT COUNT(*) AS n FROM {$this->t($table)} c
                 WHERE c.`{$column}` IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM {$this->t('pegawai')} p WHERE p.`nip` = c.`{$column}`)",
            );
            $orphans = (int) ($row['n'] ?? 0);

            if ($orphans > 0) {
                $problems[] = "{$table}.{$column}: {$orphans} {$label} dengan NIP yang tidak ada di pegawai";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-019: FK ke pegawai.nip tidak dipasang — ' . implode('; ', $problems)
                . '. Impor pegawai dulu atau perbaiki/hapus baris tersebut, lalu jalankan ulang migrate.',
            );
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $row = $this->first(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->connection()->prefixTable($table)}'
               AND CONSTRAINT_NAME = '{$name}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function first(string $sql): array
    {
        $result = $this->connection()->query($sql);

        if (! $result instanceof ResultInterface) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-019: query gagal (' . $error['code'] . '): ' . $error['message']);
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
            throw new RuntimeException('DBV-019: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-019: ALTER FK pegawai gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
