<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DITAHAN (DBV-020, keputusan dokumen Bagian 6) — file ini sengaja di luar app/Database/Migrations sehingga TIDAK
 * dijalankan `php spark migrate` (pola DBV-019 `AddFkPegawaiDiPenggunaFaqRate`). Dipindah ke app/Database/Migrations
 * dengan nama `<timestamp>_AddFkSertifikasiDiRiwayatDiklat.php` (timestamp lebih besar dari migration terakhir di
 * lingkungan target) setelah prasyaratnya terpenuhi (dokumen backend/docs/db-review/DBV-020-skema-tambahan-fase3.md
 * Bagian 1.3):
 *   (1) master `rumpun_sertifikasi`/`lembaga_sertifikasi` dan `riwayat_diklat` sudah diimpor di lingkungan target dan
 *       audit yatim Bagian 1.3 = 0 (D1 tidak punya FK ini; form legacy `L_diklat.php` :652-661 menyimpan id kiriman
 *       klien tanpa memeriksa keberadaan master, jadi id yatim mungkin ada);
 *   (2) Definisi Diklat v2 memakai dropdown master (MAKE lanjutan WS-1) — sampai itu kedua kolom selalu NULL di v2.
 * Isi dan pra-cek yatim diuji Tests\Database\Kepegawaian\SkemaTambahanFase3Test (dipasang lalu dilepas di test).
 *
 * FK [V2] (tidak ada di D1; nama mengikuti pola legacy `fk_id_diklat_rdiklat_to_diklat`):
 *   - `fk_id_rumpun_sertifikasi_rdiklat_to_rs`: `riwayat_diklat.id_rumpun_sertifikasi` → `rumpun_sertifikasi`;
 *   - `fk_id_lembaga_sertifikasi_rdiklat_to_ls`: `riwayat_diklat.id_lembaga_sertifikasi` → `lembaga_sertifikasi`.
 * Keduanya ON DELETE RESTRICT ON UPDATE RESTRICT (konvensi V1 DBV-012/013). Kolom anak INT NULL [K] = PK induk INT [K];
 * D1 tidak punya index untuk kedua kolom, jadi KEY bernama sama dengan FK ikut dibuat.
 *
 * up() fail-closed: sebelum ALTER, nilai yatim per FK dihitung; bila ada, migration berhenti dengan pesan jumlah per
 * kolom. Satu ALTER untuk dua KEY + dua FK (satu kali rebuild `riwayat_diklat`). FK yang sudah ada dilewati. down()
 * melepas FK lalu KEY yang dibuat migration ini, sehingga skema kembali sama dengan D1.
 */
class AddFkSertifikasiDiRiwayatDiklat extends Migration
{
    /**
     * nama FK (= nama KEY) => [kolom anak, tabel induk]; kolom induk = `id_<tabel induk>`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const FOREIGN_KEYS = [
        'fk_id_rumpun_sertifikasi_rdiklat_to_rs'  => ['id_rumpun_sertifikasi', 'rumpun_sertifikasi'],
        'fk_id_lembaga_sertifikasi_rdiklat_to_ls' => ['id_lembaga_sertifikasi', 'lembaga_sertifikasi'],
    ];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        $this->assertNoOrphans();

        $specs = [];

        foreach (self::FOREIGN_KEYS as $name => [$column, $parent]) {
            if ($this->constraintExists($name)) {
                continue;
            }

            if (! $this->indexExists($name)) {
                $specs[] = "ADD KEY `{$name}` (`{$column}`)";
            }

            $specs[] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES {$this->t($parent)} (`id_{$parent}`) "
                . self::RESTRICT;
        }

        if ($specs !== []) {
            $this->exec("ALTER TABLE {$this->t('riwayat_diklat')} " . implode(', ', $specs));
        }
    }

    public function down(): void
    {
        $specs = [];

        foreach (array_keys(array_reverse(self::FOREIGN_KEYS, true)) as $name) {
            if ($this->constraintExists($name)) {
                $specs[] = "DROP FOREIGN KEY `{$name}`";
            }
        }

        if ($specs !== []) {
            $this->exec("ALTER TABLE {$this->t('riwayat_diklat')} " . implode(', ', $specs));
        }

        $specs = [];

        foreach (array_keys(array_reverse(self::FOREIGN_KEYS, true)) as $name) {
            if ($this->indexExists($name)) {
                $specs[] = "DROP KEY `{$name}`";
            }
        }

        if ($specs !== []) {
            $this->exec("ALTER TABLE {$this->t('riwayat_diklat')} " . implode(', ', $specs));
        }
    }

    /**
     * Pra-cek yatim (fail-closed): id master yang dirujuk riwayat tetapi tidak ada di tabel master.
     */
    private function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::FOREIGN_KEYS as [$column, $parent]) {
            $row = $this->first(
                "SELECT COUNT(*) AS n FROM {$this->t('riwayat_diklat')} c
                 WHERE c.`{$column}` IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM {$this->t($parent)} p WHERE p.`id_{$parent}` = c.`{$column}`)",
            );
            $orphans = (int) ($row['n'] ?? 0);

            if ($orphans > 0) {
                $problems[] = "riwayat_diklat.{$column}: {$orphans} baris merujuk id yang tidak ada di {$parent}";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-020: FK sertifikasi tidak dipasang — ' . implode('; ', $problems)
                . '. Impor baris master yang hilang (status 10 bila tidak dipakai lagi) atau NULL-kan dengan catatan '
                . 'rekonsiliasi, lalu jalankan ulang migrate.',
            );
        }
    }

    private function constraintExists(string $name): bool
    {
        $row = $this->first(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->connection()->prefixTable('riwayat_diklat')}'
               AND CONSTRAINT_NAME = '{$name}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    private function indexExists(string $name): bool
    {
        $row = $this->first(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->connection()->prefixTable('riwayat_diklat')}'
               AND INDEX_NAME = '{$name}'",
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
        try {
            $ok = $this->connection()->query($sql) !== false;
        } catch (Throwable $e) {
            throw new RuntimeException('DBV-020: ALTER riwayat_diklat gagal: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (! $ok) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-020: ALTER riwayat_diklat gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
