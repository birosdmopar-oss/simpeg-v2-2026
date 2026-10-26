<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;

/**
 * DBV-022 (9/9) — DRAF, DITAHAN (lihat 2026-10-26-100000_CreateMasterLayananFase4.php untuk aturan pemindahan).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok H — kode `jenis_rwy` baru 43..50 untuk lampiran/surat Modul C di `document_attachment` (PRD §6.1.1 #21,
 * dokumen Bagian 2.21). Migration BARU karena seed lama `2026-09-30-130000_CreateJenisRwy.php` (kode 0..42, tertinggi
 * 42 `pmk`) sudah ada di `main` dan tidak boleh diedit (K3). Kode tidak ada di legacy ([V2]; lampiran cuti/TB/IB
 * legacy disimpan sebagai kolom `file_*`, bukan `document_attachment`).
 *
 * Usulan 8 kode (PRD mengusulkan 7): `surat_cuti` (44, belum TTE) dan `surat_cuti_tte` (45, ber-TTE) dipisah sebagai
 * jawaban DQ-21 — lebih murah daripada kolom `tag` baru di `document_attachment` Fase 3. `order` melanjutkan urutan
 * seed lama (20 → 21..28). Nomor final = keputusan DBV.
 *
 * up() fail-closed: berhenti bila salah satu id 43..50 sudah ada (seed ganda / nomor dipakai migration lain). INSERT =
 * satu statement multi-baris (atomik). down() menghapus kedelapan baris; DELETE ditolak (1451) selama ada
 * `document_attachment` yang merujuknya (FK `fk_id_riwayat_da_to_jenis_rwy`) — arsip harus dipindah/dihapus dulu.
 */
class SeedJenisRwyLayananFase4 extends Migration
{
    /**
     * id_jenis_rwy => [kode, jenis_rwy, tabel_entri, order].
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: int}>
     */
    public const ROWS = [
        43 => ['cuti', 'Lampiran Pengajuan Cuti', 'riwayat_cuti', 21],
        44 => ['surat_cuti', 'Surat Cuti (belum TTE)', 'riwayat_cuti', 22],
        45 => ['surat_cuti_tte', 'Surat Cuti (ber-TTE)', 'riwayat_cuti', 23],
        46 => ['tb', 'Lampiran Tugas Belajar', 'riwayat_tb', 24],
        47 => ['surat_tb', 'SK Tugas Belajar', 'riwayat_tb', 25],
        48 => ['tb_perpan', 'Berkas Perpanjangan Tugas Belajar', 'riwayat_tb_perpan', 26],
        49 => ['ib', 'Lampiran Izin Belajar', 'riwayat_ib', 27],
        50 => ['surat_ib', 'Surat Izin Belajar', 'riwayat_ib', 28],
    ];

    private const TABLE = 'jenis_rwy';

    public function up(): void
    {
        $ids = implode(', ', array_keys(self::ROWS));
        $row = $this->first("SELECT COUNT(*) AS n FROM {$this->t(self::TABLE)} WHERE `id_jenis_rwy` IN ({$ids})");

        if ((int) ($row['n'] ?? 0) > 0) {
            throw new RuntimeException(
                "DBV-022: seed jenis_rwy tidak dijalankan — sebagian id {$ids} sudah ada di jenis_rwy. "
                . 'Periksa apakah seed ini sudah pernah dijalankan atau nomornya dipakai migration lain.',
            );
        }

        $values = implode(', ', array_fill(0, count(self::ROWS), '(?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())'));
        $binds  = [];

        foreach (self::ROWS as $id => [$kode, $nama, $tabelEntri, $order]) {
            array_push($binds, $id, $kode, $nama, $tabelEntri, $order);
        }

        $this->exec(
            "INSERT INTO {$this->t(self::TABLE)} (`id_jenis_rwy`, `kode`, `jenis_rwy`, `tabel_entri`, `order`, `status`, `updated_at`) VALUES {$values}",
            $binds,
            'seed jenis_rwy 43..50',
        );
    }

    public function down(): void
    {
        $ids = implode(', ', array_keys(self::ROWS));

        $this->exec("DELETE FROM {$this->t(self::TABLE)} WHERE `id_jenis_rwy` IN ({$ids})", [], 'hapus seed jenis_rwy 43..50');
    }

    /**
     * @return array<string, mixed>
     */
    private function first(string $sql): array
    {
        $result = $this->db->query($sql);

        if (! $result instanceof ResultInterface) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-022: query gagal (' . $error['code'] . '): ' . $error['message']);
        }

        return $result->getRowArray() ?? [];
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-022: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    /**
     * @param list<int|string|null> $binds
     */
    private function exec(string $sql, array $binds, string $what): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan seed gagal diam-diam.
        $result = $binds === [] ? $this->db->query($sql) : $this->db->query($sql, $binds);

        if ($result === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-022: {$what} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
