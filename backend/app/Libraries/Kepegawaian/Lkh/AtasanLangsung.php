<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\Database\BaseConnection;

/**
 * Atasan langsung pegawai (approver LKH B-12b — pengecualian keputusan #4: ikut legacy). Port helper legacy getAL()
 * (`helpers/function_helper.php:1479`):
 *  - jabatan Es.I (sub group 1) → Menteri (web_config `nama_menteri`, tanpa NIP; tidak bisa memverifikasi lewat aplikasi);
 *  - selain itu kolom atasan pertama yang terisi di snapshot jabatan: subkoordinator (`id_atasan_es_4_koord`), Es.IV,
 *    koordinator (`id_atasan_es_3_koord`), Es.III, Es.II, Es.I;
 *  - pemegang jabatan atasan itu = snapshot jabatan dengan jabatan (atau jabatan koordinasi) tersebut, pegawai aktif
 *    berjenis status 1/12, TMT terbaru; tidak ada → Plt berstatus 1 pada jabatan itu; tidak ada → kosong (legacy tidak
 *    turun ke level atasan berikutnya).
 */
final class AtasanLangsung
{
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return array{nip: string, nama: string}|null
     */
    public function untuk(string $nip): ?array
    {
        $db  = $this->db();
        $pmj = $db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();

        if ($pmj === null) {
            return null;
        }

        if ((int) ($pmj['id_sub_group_jabatan'] ?? 0) === 1) {
            $menteri = trim((string) ($this->webConfig('nama_menteri') ?? ''));

            return $menteri === '' ? null : ['nip' => '', 'nama' => mb_strtoupper($menteri)];
        }

        foreach ([
            ['id_atasan_es_4_koord', 'id_jabatan_koord'],
            ['id_atasan_es_4', 'id_jabatan'],
            ['id_atasan_es_3_koord', 'id_jabatan_koord'],
            ['id_atasan_es_3', 'id_jabatan'],
            ['id_atasan_es_2', 'id_jabatan'],
            ['id_atasan_es_1', 'id_jabatan'],
        ] as [$kolomAtasan, $kolomJabatan]) {
            $idJabatan = $pmj[$kolomAtasan] ?? null;

            if ($idJabatan === null || (string) $idJabatan === '') {
                continue;
            }

            return $this->pemegang($kolomJabatan, (int) $idJabatan);
        }

        return null;
    }

    /**
     * @return array{nip: string, nama: string}|null
     */
    private function pemegang(string $kolomJabatan, int $idJabatan): ?array
    {
        $db  = $this->db();
        $row = $db->table('pegawai_mutasi_jabatan pmj')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir')
            ->join('pegawai p', 'p.nip = pmj.nip', 'inner')
            ->where("pmj.{$kolomJabatan}", $idJabatan)
            ->where('p.status', 1)->whereIn('p.id_jenis_status', [1, 12])
            ->orderBy('pmj.tmtsk', 'DESC')->limit(1)->get()->getRowArray();

        if ($row === null) {
            $row = $db->table('pegawai_plt plt')
                ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir')
                ->join('pegawai p', 'p.nip = plt.nip_plt', 'inner')
                ->where("plt.{$kolomJabatan}", $idJabatan)->where('plt.status', 1)
                ->orderBy('plt.id_pegawai_plt', 'ASC')->limit(1)->get()->getRowArray();
        }

        return $row === null ? null : [
            'nip'  => (string) $row['nip'],
            'nama' => StrukturService::namaGelar($row['glr_awal'] ?? null, (string) $row['nama'], $row['glr_akhir'] ?? null),
        ];
    }

    private function webConfig(string $nama): ?string
    {
        $db  = $this->db();
        $ada = $db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$db->prefixTable('web_config')],
        )->getRowArray() !== null;

        if (! $ada) {
            return null;
        }

        $row = $db->table('web_config')->select('config_value')->where('config_name', $nama)->get()->getRowArray();

        return $row === null ? null : (string) $row['config_value'];
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
