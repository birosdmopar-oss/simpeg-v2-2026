<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-031 (ISSUE-056 P6, DBV-026 §13.2 A7) — isi master `simapi_jd` 14 baris dengan id EKSPLISIT sama dengan legacy (id
 * dipakai klien dan penulis `simapi_ch`). Data referensi, tanpa data pribadi. Dokumen: backend/docs/db-review/DBV-031-simapi-d1.md.
 *
 * Pra-cek: tabel harus kosong; isi berbeda → gagal jelas tanpa mengubah data (baris yang sudah sama persis dilewati, jadi
 * aman diulang). down() menghapus hanya 14 id ini dan gagal bila sudah dirujuk `simapi_ch`/`simapi_auth_jd` (FK RESTRICT).
 */
class SeedSimapiJd extends Migration
{
    /** [K D1] id => jenis_data. */
    public const ISI = [
        1  => 'Data Umum',
        2  => 'Alamat',
        3  => 'Keluarga',
        4  => 'Pendidikan',
        5  => 'Pelatihan',
        6  => 'Kursus Seminar',
        7  => 'Organisasi',
        8  => 'SKP',
        9  => 'Pangkat',
        10 => 'Jabatan',
        11 => 'KGB',
        12 => 'Tanda Jasa',
        13 => 'Hukuman Disiplin',
        14 => 'Anak',
    ];

    public function up(): void
    {
        $ada = [];

        foreach ($this->db->table('simapi_jd')->select('id_simapi_jd, jenis_data')->get()->getResultArray() as $r) {
            $ada[(int) $r['id_simapi_jd']] = (string) $r['jenis_data'];
        }

        $beda = array_diff_assoc($ada, self::ISI);

        if ($beda !== []) {
            throw new RuntimeException('DBV-031: simapi_jd sudah berisi baris yang berbeda dari master legacy (id ' . implode(', ', array_keys($beda)) . '); data tidak diubah.');
        }


        foreach (self::ISI as $id => $jenis) {
            if (isset($ada[$id])) {
                continue;
            }

            if ($this->db->table('simapi_jd')->insert(['id_simapi_jd' => $id, 'jenis_data' => $jenis]) === false) {
                throw new RuntimeException("DBV-031: seed simapi_jd id {$id} gagal: " . $this->db->error()['message']);
            }
        }
    }

    public function down(): void
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-031: koneksi database tidak didukung.');
        }

        if ($this->db->table('simapi_jd')->whereIn('id_simapi_jd', array_keys(self::ISI))->delete() === false) {
            throw new RuntimeException('DBV-031: hapus seed simapi_jd gagal: ' . $this->db->error()['message']);
        }
    }
}
