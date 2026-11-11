<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-032 (T4) — isi master `jenis_dibo` 8 baris dengan id EKSPLISIT sama dengan legacy [K D1, salinan dev]. Data referensi,
 * tanpa data pribadi. DRAF DITAHAN bersama CreateKontenInteraksiFase7; dipindah ke `Migrations/` (timestamp sesudahnya)
 * setelah approve DBV. Pola SeedSimapiJd (DBV-031). Dokumen: backend/docs/db-review/DBV-032-konten-interaksi-fase7.md §10.
 *
 * Pra-cek: baris yang sudah ada harus identik dengan master (subset identik dilewati, aman diulang); id berisi lain → gagal
 * jelas tanpa mengubah data. down() menghapus hanya 8 id ini dan gagal bila sudah dirujuk `dibo` (FK RESTRICT).
 */
class SeedJenisDibo extends Migration
{
    /** [K D1] id => [jenis_dibo, order]; status 1. */
    public const ISI = [
        1 => ['UU', 1],
        2 => ['PP', 2],
        3 => ['PERPRES', 3],
        4 => ['KEPPRES', 4],
        5 => ['PERMEN', 5],
        6 => ['KEPMEN', 6],
        7 => ['SURAT EDARAN', 7],
        8 => ['PERKA BKN', 8],
    ];

    public function up(): void
    {
        $ada = [];

        foreach ($this->db->table('jenis_dibo')->select('id_jenis_dibo, jenis_dibo, order, status')->get()->getResultArray() as $r) {
            $ada[(int) $r['id_jenis_dibo']] = [(string) $r['jenis_dibo'], (int) $r['order'], (int) $r['status']];
        }

        $beda = [];

        foreach ($ada as $id => $baris) {
            if (! isset(self::ISI[$id]) || $baris !== [self::ISI[$id][0], self::ISI[$id][1], 1]) {
                $beda[] = $id;
            }
        }

        if ($beda !== []) {
            throw new RuntimeException('DBV-032: jenis_dibo sudah berisi baris yang berbeda dari master legacy (id ' . implode(', ', $beda) . '); data tidak diubah.');
        }

        foreach (self::ISI as $id => [$jenis, $urut]) {
            if (isset($ada[$id])) {
                continue;
            }

            if ($this->db->table('jenis_dibo')->insert(['id_jenis_dibo' => $id, 'jenis_dibo' => $jenis, 'order' => $urut, 'status' => 1]) === false) {
                throw new RuntimeException("DBV-032: seed jenis_dibo id {$id} gagal: " . $this->db->error()['message']);
            }
        }
    }

    public function down(): void
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-032: koneksi database tidak didukung.');
        }

        if ($this->db->table('jenis_dibo')->whereIn('id_jenis_dibo', array_keys(self::ISI))->delete() === false) {
            throw new RuntimeException('DBV-032: hapus seed jenis_dibo gagal: ' . $this->db->error()['message']);
        }
    }
}
