<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\Database\BaseConnection;

/**
 * Atasan langsung (AL) pemohon cuti — port `getAL_es2()` (`function_helper.php:1700-1945`, PRD Fase 4 §6.4.1).
 *
 * Kolom atasan pertama yang terisi di `pegawai_mutasi_jabatan` menurut urutan `id_atasan_es_4 → es_3 → es_2 → es_1`
 * (legacy tidak turun ke tingkat berikutnya bila pemegangnya kosong). Pemegang = snapshot jabatan dengan jabatan itu,
 * pegawai aktif (`status = 1`, `id_jenis_status IN (1, 12)`), TMT SK terbaru; Plh aktif (tanggal berlaku, status 1)
 * pada jabatan itu menggantikannya; tanpa pemegang → Plt aktif; tanpa itu juga → kosong.
 *
 * Pejabat Es.I (`id_sub_group_jabatan = 1`) → `eselon_i = true` (AL = Menteri, tanpa NIP; teks diisi pemanggil dari
 * `WebConfigService`). Berbeda dari {@see \App\Libraries\Kepegawaian\Lkh\AtasanLangsung} (LKH): prioritas Plh, urutan
 * es4→es1 tanpa kolom koordinator, dan Plt wajib dalam rentang tanggal. `AtasanLangsung::untuk()` tidak diubah.
 */
class AtasanLangsungCuti
{
    private const KOLOM = ['id_atasan_es_4', 'id_atasan_es_3', 'id_atasan_es_2', 'id_atasan_es_1'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{eselon_i: bool, nip: string|null, nama: string|null, jabatan: string|null, id_jabatan: int|null}|null
     *                                                                                                                        null = pemohon tanpa data jabatan
     */
    public function untuk(string $nip, string $hariIni): ?array
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();

        if ($pmj === null) {
            return null;
        }

        $kosong = ['eselon_i' => false, 'nip' => null, 'nama' => null, 'jabatan' => null, 'id_jabatan' => null];

        if ((int) ($pmj['id_sub_group_jabatan'] ?? 0) === 1) {
            return ['eselon_i' => true] + $kosong;
        }

        foreach (self::KOLOM as $kolom) {
            $idJabatan = $pmj[$kolom] ?? null;

            if ($idJabatan === null || (string) $idJabatan === '') {
                continue;
            }

            return $this->pemegang((int) $idJabatan, $hariIni) ?? $kosong;
        }

        return $kosong;
    }

    /**
     * @return array{eselon_i: bool, nip: string, nama: string, jabatan: string|null, id_jabatan: int}|null
     */
    private function pemegang(int $idJabatan, string $hariIni): ?array
    {
        $definitif = $this->db->table('pegawai_mutasi_jabatan pmj')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
            ->join('pegawai p', 'p.nip = pmj.nip', 'inner')
            ->where('pmj.id_jabatan', $idJabatan)
            ->where('p.status', 1)
            ->whereIn('p.id_jenis_status', [1, 12])
            ->orderBy('pmj.tmtsk', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if ($definitif !== null) {
            $plh = $this->db->table('pegawai_plh plh')
                ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
                ->join('pegawai p', 'p.nip = plh.nip_plh', 'left')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = plh.nip_plh', 'left')
                ->where('plh.id_jabatan', $idJabatan)
                ->where('plh.status', 1)
                ->where('plh.tgl_mulai <=', $hariIni)
                ->where('plh.tgl_akhir >=', $hariIni)
                ->orderBy('plh.id_pegawai_plh', 'ASC')
                ->limit(1)
                ->get()->getRowArray();

            return $this->hasil($plh ?? $definitif, $idJabatan);
        }

        $plt = $this->db->table('pegawai_plt plt')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir')
            ->join('pegawai p', 'p.nip = plt.nip_plt', 'inner')
            ->where('plt.id_jabatan', $idJabatan)
            ->where('plt.status', 1)
            ->where('plt.tgl_mulai <=', $hariIni)
            ->where('plt.tgl_akhir >=', $hariIni)
            ->orderBy('plt.id_pegawai_plt', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        if ($plt === null) {
            return null;
        }

        $jabatan        = $this->db->table('jabatan')->select('jabatan')->where('id_jabatan', $idJabatan)->get()->getRowArray();
        $plt['jabatan'] = isset($jabatan['jabatan']) ? 'Plt. ' . $jabatan['jabatan'] : null;

        return $this->hasil($plt, $idJabatan);
    }

    /**
     * @param array<string, mixed> $baris
     *
     * @return array{eselon_i: bool, nip: string, nama: string, jabatan: string|null, id_jabatan: int}
     */
    private function hasil(array $baris, int $idJabatan): array
    {
        return [
            'eselon_i'   => false,
            'nip'        => (string) $baris['nip'],
            'nama'       => StrukturService::namaGelar($baris['glr_awal'] ?? null, (string) $baris['nama'], $baris['glr_akhir'] ?? null),
            'jabatan'    => isset($baris['jabatan']) ? (string) $baris['jabatan'] : null,
            'id_jabatan' => $idJabatan,
        ];
    }
}
