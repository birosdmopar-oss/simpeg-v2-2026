<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;
use Throwable;

/**
 * DITAHAN DUA LAPIS (DBV-022, dokumen Bagian 7 DQ-3/DQ-4/DQ-12/DQ-17) — seperti AddFkPegawaiDiPenggunaFaqRate
 * (DBV-019): file ini tanpa stempel waktu dan sengaja di luar app/Database/Migrations. Berbeda dari sembilan draf
 * CREATE DBV-022 (yang dipindah begitu DBV menyetujui), migration ini baru dipindah ke app/Database/Migrations dengan
 * nama `<timestamp>_AddFkNipLayananFase4.php` setelah DUA prasyarat terpenuhi:
 *   (1) DB Validator menyetujui FK-nya (DQ-3, DQ-4, DQ-12, DQ-17 — DBV boleh mencoret baris FOREIGN_KEYS yang
 *       diputuskan tanpa FK, mis. `disiplin_ticket.nip`);
 *   (2) hasil audit `sql/DBV-022-audit-layanan.sql` Bagian 1, 5, 6, 7 di lingkungan target = 0 yatim (impor
 *       `pegawai` + tabel Modul C selesai dan dinormalkan). Migration ini fail-closed selama masih ada NIP yatim.
 *
 * FK masuk ke `pegawai.nip` dari kolom NIP yang di legacy TIDAK ber-FK (V4: semua sudah VARCHAR(30) di CREATE):
 *   - `riwayat_cuti.nip_atasan_langsung`, `riwayat_cuti.nip_yang_menyetujui` (NULL-able; DQ-3; DoD 064-4);
 *   - `petugas_layanan.nip` (DQ-12); `pa_layanan.nip` (NULL-able; DQ-17);
 *   - `disiplin_ticket.nip` (DQ-4: pelapor tiket bisa sudah tidak ada di `pegawai`; usulan coder: tetap ditahan di sini,
 *     bukan tanpa FK, supaya keputusan akhir cukup mencoret satu baris).
 * Semua ON DELETE RESTRICT ON UPDATE RESTRICT (K1; ganti NIP lewat B-06). KEY penopang FK sudah dibuat di CREATE
 * (`nip_atasan_langsung`, `nip_yang_menyetujui`, `nip`), sehingga ALTER hanya ADD CONSTRAINT.
 *
 * up() fail-closed: sebelum ALTER apa pun, NIP yatim per kolom dihitung (NULL dan string kosong diabaikan — string
 * kosong harus di-NULL-kan saat impor, audit Bagian 1 `nip_al_kosong`); bila ada, migration berhenti dengan pesan jumlah
 * per kolom. FK yang sudah ada dilewati; bila ALTER berikutnya gagal, FK yang dipasang PADA RUN ITU dilepas lagi sebelum
 * error dilempar ulang. down() hanya melepas FK yang ada (KEY tetap, seperti sebelum up()).
 */
class AddFkNipLayananFase4 extends Migration
{
    /**
     * FK masuk: nama => [tabel anak, kolom, keterangan yatim untuk pesan error].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const FOREIGN_KEYS = [
        'fk_nip_atasan_langsung_rwycuti_to_pegawai' => ['riwayat_cuti', 'nip_atasan_langsung', 'pengajuan cuti dengan NIP atasan langsung'],
        'fk_nip_yang_menyetujui_rwycuti_to_pegawai' => ['riwayat_cuti', 'nip_yang_menyetujui', 'pengajuan cuti dengan NIP pejabat yang berwenang'],
        'fk_nip_petugas_layanan_to_pegawai'         => ['petugas_layanan', 'nip', 'petugas layanan (PIC)'],
        'fk_nip_pa_layanan_to_pegawai'              => ['pa_layanan', 'nip', 'aturan petugas approval per NIP'],
        'fk_nip_disiplin_ticket_to_pegawai'         => ['disiplin_ticket', 'nip', 'tiket Halo Simpeg (pelapor)'],
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
                    // Error asli tetap dilempar; sisa FK dibersihkan manual (dokumen DBV-022 Bagian 9).
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
     * Pra-cek yatim (fail-closed). ADD FOREIGN KEY sendiri juga menolak yatim (1452), tetapi tanpa jumlah per kolom;
     * pesan ini memberi operator angka yang bisa ditindaklanjuti sebelum impor diulang.
     */
    private function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::FOREIGN_KEYS as [$table, $column, $label]) {
            $row = $this->first(
                "SELECT COUNT(*) AS n FROM {$this->t($table)} c
                 WHERE c.`{$column}` IS NOT NULL AND c.`{$column}` <> ''
                   AND NOT EXISTS (SELECT 1 FROM {$this->t('pegawai')} p WHERE p.`nip` = c.`{$column}`)",
            );
            $orphans = (int) ($row['n'] ?? 0);

            if ($orphans > 0) {
                $problems[] = "{$table}.{$column}: {$orphans} {$label} dengan NIP yang tidak ada di pegawai";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-022: FK NIP layanan ke pegawai.nip tidak dipasang — ' . implode('; ', $problems)
                . '. Impor pegawai dulu atau perbaiki/NULL-kan baris tersebut (audit sql/DBV-022-audit-layanan.sql), '
                . 'lalu jalankan ulang migrate.',
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

            throw new RuntimeException('DBV-022: query gagal (' . $error['code'] . '): ' . $error['message']);
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
            throw new RuntimeException('DBV-022: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-022: ALTER FK NIP layanan gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
