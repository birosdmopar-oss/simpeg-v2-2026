<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-003 — 4 baris sentinel LAIN-LAIN di tabel wilayah (G-07 kantor, keputusan F8). Review DB Validator:
 * backend/docs/db-review/G-07-G-08-kantor-hari-libur-kursem-schema.md Bagian 2.5 — JANGAN dijalankan di
 * Dev/Production sebelum disetujui.
 *
 * Sumber: kode LAIN-LAIN legacy 99/9999/9999999/9999999999 [K] (`Lm_umum.php:1797-1831`, `kantor/form.php`,
 * `A_employee.php:438-441`). Nama `LAIN-LAIN` dan status 1 [I kuat] (`L_user.php:4708-4739` menyalin nama wilayah
 * untuk kode ini ke kolom NOT NULL; `Tester.php:4009-4015` membandingkannya dengan 'lain-lain'). Rantai induk
 * 99 → 9999 → 9999999 → 9999999999 [I kuat]. `order` 0 [V2]: di luar urutan tampil. `kd_area`/`kd_pos` NULL [K]
 * (kode pos kelurahan sentinel diisi bebas di form kantor).
 *
 * Di aplikasi keempat baris ini adalah baris sistem (opsi `systemIds` Config\MasterData, CR-010): tidak tampil di
 * options/daftar admin, tidak ikut urutan, tidak bisa diubah/dinonaktifkan/dihapus/menjadi induk, dan hanya bisa
 * dirujuk field ber-allowSystem (kolom wilayah `kantor`). Nilai di migration ini ditulis literal (tidak dibaca dari
 * config) agar migration tidak berubah bila kode aplikasi berubah; LiburKantorKursemSchemaTest mencocokkan ROWS
 * dengan `systemIds` config.
 *
 * up() fail-closed: bila SATU saja dari keempat kode sudah ada (sisa impor/isian manual), tidak ada yang ditulis dan
 * exception menyebut tabel + kodenya — periksa baris itu dulu (tanpa INSERT IGNORE). Keempat INSERT dalam satu
 * transaksi. down() menghapus keempat baris dalam satu transaksi (anak → induk); selama baris itu masih dirujuk
 * (mis. kantor berkode LAIN-LAIN, FK RESTRICT 1451) down() menolak dan tidak menghapus apa pun.
 *
 * Versi 100200 sengaja LEBIH KECIL dari CreateKantor (100300): dalam satu batch rollback berjalan terbalik, sehingga
 * `kantor` di-drop dulu, baru sentinel dihapus. Keempat migration DBV-003 dijalankan di Dev/Production dalam satu
 * batch (G-doc Bagian 2.5).
 */
class SeedWilayahLainLain extends Migration
{
    /**
     * Nama baris sentinel (kolom nama wilayah = nama tabelnya).
     */
    public const NAME = 'LAIN-LAIN';

    /**
     * Baris sentinel urut induk → anak: tabel => [kolom PK, kode, kolom induk|null, kode induk|null].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: string|null}>
     */
    public const ROWS = [
        'provinsi'       => ['id_provinsi', '99', null, null],
        'kabupaten_kota' => ['id_kabupaten_kota', '9999', 'id_provinsi', '99'],
        'kecamatan'      => ['id_kecamatan', '9999999', 'id_kabupaten_kota', '9999'],
        'kelurahan'      => ['id_kelurahan', '9999999999', 'id_kecamatan', '9999999'],
    ];

    /**
     * Error FK MySQL/MariaDB: baris induk masih dirujuk baris lain.
     */
    private const ERR_ROW_IS_REFERENCED = 1451;

    public function up(): void
    {
        $db = $this->connection();

        // Skema wilayah wajib sudah legacy (DBV-001: kolom nama `provinsi`, bukan `nama_provinsi`).
        if (! $db->fieldExists('provinsi', 'provinsi')) {
            throw new RuntimeException('DBV-003: skema wilayah belum DBV-001 (kolom provinsi.provinsi tidak ada) — jalankan 2026-09-23-000000_AlterBatch1KeSkemaLegacy dulu.');
        }

        $existing = [];

        foreach (self::ROWS as $table => [$pk, $code]) {
            if ($db->table($table)->where($pk, $code)->countAllResults() > 0) {
                $existing[] = "{$table} {$code}";
            }
        }

        if ($existing !== []) {
            throw new RuntimeException('DBV-003: baris sentinel LAIN-LAIN sudah ada (' . implode(', ', $existing) . ') — periksa baris itu (sisa impor/isian manual); tidak ada yang ditulis.');
        }

        $this->inTransaction(function (): void {
            foreach (self::ROWS as $table => [$pk, $code, $parentColumn, $parentCode]) {
                $columns = [$pk];
                $values  = [$code];

                if ($parentColumn !== null) {
                    $columns[] = $parentColumn;
                    $values[]  = $parentCode;
                }

                $columns[] = $table;
                $values[]  = self::NAME;

                $list = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
                $bind = implode(', ', array_fill(0, count($values), '?'));

                $this->exec(
                    "INSERT INTO {$this->t($table)} ({$list}, `order`, `status`, `created_at`) VALUES ({$bind}, 0, 1, UTC_TIMESTAMP())",
                    $values,
                    "tambah sentinel {$table} {$code}",
                );
            }
        });
    }

    public function down(): void
    {
        $this->inTransaction(function (): void {
            foreach (array_reverse(self::ROWS) as $table => [$pk, $code]) {
                try {
                    $this->exec("DELETE FROM {$this->t($table)} WHERE `{$pk}` = ?", [$code], "hapus sentinel {$table} {$code}");
                } catch (RuntimeException $e) {
                    if ($e->getCode() === self::ERR_ROW_IS_REFERENCED) {
                        throw new RuntimeException(
                            "DBV-003: baris sentinel LAIN-LAIN masih dirujuk ({$table} {$code}) — hapus rujukannya dulu (mis. kantor berkode LAIN-LAIN).",
                            self::ERR_ROW_IS_REFERENCED,
                            $e,
                        );
                    }

                    throw $e;
                }
            }
        });
    }

    /**
     * Jalankan $work dalam satu transaksi: gagal → rollback seluruhnya lalu lempar ulang.
     */
    private function inTransaction(callable $work): void
    {
        $db = $this->connection();
        $db->transBegin();

        try {
            $work();
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();

            throw $e;
        }

        $db->transCommit();
        $db->resetTransStatus();
    }

    /**
     * Query DML dengan binding. Gagal (query() false saat DBDebug = false, atau DatabaseException saat DBDebug = true)
     * → RuntimeException berkode error MySQL/MariaDB asli.
     *
     * @param list<string|null> $binds
     */
    private function exec(string $sql, array $binds, string $what): void
    {
        $db = $this->connection();

        try {
            $result = $db->query($sql, $binds);
        } catch (Throwable $e) {
            throw new RuntimeException("DBV-003: {$what} gagal ({$e->getCode()}): {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        if ($result === false) {
            $error = $db->error();

            throw new RuntimeException("DBV-003: {$what} gagal ({$error['code']}): {$error['message']}", (int) $error['code']);
        }
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
            throw new RuntimeException('DBV-003: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }
}
