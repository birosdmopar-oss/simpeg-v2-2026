<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DRAF DBV-023 (Fase 5A, F5-4) — DITAHAN, pola sama dengan `CreatePresensiGaji` di folder ini: dipindah ke
 * app/Database/Migrations dengan nama `<timestamp>_AlterAuditLogsEventPresensi.php` di M2 setelah DBV-023 disetujui dan
 * DBV-022 ada di `main`. Bila DBV-022 juga mengubah ENUM `audit_logs.event`, daftar NILAI_LAMA diganti nilai ENUM terkini
 * di `main` saat itu (gabungan) dan timestamp file ini lebih baru dari migration DBV-022.
 *
 * Menambah dua nilai `audit_logs.event` [V2]:
 *   - `warning` — catatan non-mutasi: ketidakcocokan nama–NIP saat pratinjau impor Slip Gaji (D-10, F5-4), sekali per
 *     kunci per pratinjau;
 *   - `import`  — ringkasan satu commit impor Slip Gaji (tanpa nominal).
 *
 * down() fail-closed (keputusan reviewer CR 20-10-2026, PRD 5A §7.1.1 butir 4): bila masih ada baris bernilai baru,
 * rollback DITOLAK (exception) tanpa menghapus baris audit apa pun. Ini sengaja berbeda dari
 * `2026-09-17-000004_AlterAuditLogsEventAddAuth::down()` yang menghapus baris `login`/`logout` sebelum mengecilkan ENUM.
 */
class AlterAuditLogsEventPresensi extends Migration
{
    /** Nilai ENUM di `main` sebelum migration ini (`2026-09-17-000004_AlterAuditLogsEventAddAuth`). */
    public const NILAI_LAMA = ['create', 'update', 'delete', 'login', 'logout'];

    /** Nilai baru DBV-023. */
    public const NILAI_BARU = ['warning', 'import'];

    public function up(): void
    {
        $this->ubahEnum([...self::NILAI_LAMA, ...self::NILAI_BARU]);
    }

    public function down(): void
    {
        $sisa = $this->db->table('audit_logs')->whereIn('event', self::NILAI_BARU)->countAllResults();

        if ($sisa > 0) {
            throw new RuntimeException(
                "DBV-023: rollback ENUM audit_logs.event ditolak — masih ada {$sisa} baris event 'warning'/'import'. "
                . 'Baris audit tidak dihapus otomatis; putuskan penanganannya lebih dulu.',
            );
        }

        $this->ubahEnum(self::NILAI_LAMA);
    }

    /**
     * @param list<string> $nilai
     */
    private function ubahEnum(array $nilai): void
    {
        $this->forge->modifyColumn('audit_logs', [
            'event' => ['name' => 'event', 'type' => 'ENUM', 'constraint' => $nilai, 'null' => false],
        ]);
    }
}
