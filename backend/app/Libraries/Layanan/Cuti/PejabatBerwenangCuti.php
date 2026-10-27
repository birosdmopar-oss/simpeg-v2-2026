<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\Database\BaseConnection;

/**
 * Daftar pejabat yang berwenang menyetujui (PyB) — port `getListPYB()` (`L_cuti.php:12-279`, PRD Fase 4 §6.4.1).
 *
 * Aturan petugas (`pa_layanan`, `id_jenis_pa = 1`, `status = 1`): cocok `id_unit_pemohon`, `id_group_jabatan_pemohon`, dan
 * - GJ pemohon ∈ {2, 3}: PNS golongan I/II → `gol_ruang_pemohon = golongan`; PNS lainnya → `gol/ruang` (mis. `III/d`);
 *   PPPK → `gol_pppk`;
 * - selain itu: `id_sub_group_jabatan_pemohon`;
 * serta satker UPT (unit 6/7 dan GJ ≠ 5) wajib `id_satker_petugas` = satker pemohon (kecuali `III/d`–`IV/e` pada unit 7).
 * Pejabat per aturan: koordinator (`id_jabatan_koord`) → struktural (Plh aktif, lalu definitif) → `pa_layanan.nip`; tanpa
 * pemegang aktif → Plt. Anomali kurung kurawal ganda (`L:2376, 2567`) tidak dibawa. Akun tidak pernah diperiksa (legacy):
 * keterjangkauan dihitung terpisah oleh {@see ApproverTerjangkau}.
 */
class PejabatBerwenangCuti
{
    private const GOL_ATAS_UNIT_7 = ['III/d', 'IV/a', 'IV/b', 'IV/c', 'IV/d', 'IV/e'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $pemohon `id_jenis_pegawai`, `id_group_jabatan`, `id_sub_group_jabatan`, `id_unit`,
     *                                      `id_satker`, `gol`, `ruang`, `gol_pppk`
     *
     * @return list<array{nip: string, nama: string, jabatan: string|null}>
     */
    public function daftar(array $pemohon, string $hariIni): array
    {
        $gj      = (string) ($pemohon['id_group_jabatan'] ?? '');
        $sgj     = (string) ($pemohon['id_sub_group_jabatan'] ?? '');
        $unit    = (string) ($pemohon['id_unit'] ?? '');
        $satker  = (string) ($pemohon['id_satker'] ?? '');
        $jp      = (string) ($pemohon['id_jenis_pegawai'] ?? '');
        $gol     = (string) ($pemohon['gol'] ?? '');
        $ruang   = (string) ($pemohon['ruang'] ?? '');
        $golPppk = (string) ($pemohon['gol_pppk'] ?? '');

        if ($gj === '' || $sgj === '' || $unit === '' || $satker === '') {
            return [];
        }

        $pns  = $jp === '1' && $gol !== '' && $ruang !== '';
        $pppk = $jp === '6' && $golPppk !== '';

        if (! $pns && ! $pppk) {
            return [];
        }

        $b = $this->db->table('pa_layanan pa')
            ->select('pa.*, jabatan.jabatan, jak.jabatan AS jabatan_koord')
            ->join('jabatan', 'jabatan.id_jabatan = pa.id_jabatan', 'left')
            ->join('jabatan_koordinasi jak', 'jak.id_jabatan_koordinasi = pa.id_jabatan_koord', 'left')
            ->where('pa.id_jenis_pa', 1)
            ->where('pa.id_unit_pemohon', (int) $unit)
            ->where('pa.id_group_jabatan_pemohon', (int) $gj)
            ->where('pa.status', 1);

        $batasiSatker = in_array($unit, ['6', '7'], true) && $gj !== '5';

        if (in_array($gj, ['2', '3'], true)) {
            if ($pns) {
                $golRuang = "{$gol}/{$ruang}";

                if ($unit === '7' && in_array($golRuang, self::GOL_ATAS_UNIT_7, true)) {
                    $batasiSatker = false;
                }

                $b->where('pa.gol_ruang_pemohon', in_array($gol, ['I', 'II'], true) ? $gol : $golRuang);
            } else {
                $b->where('pa.gol_ruang_pemohon', $golPppk);
            }
        } else {
            $b->where('pa.id_sub_group_jabatan_pemohon', (int) $sgj);
        }

        if ($batasiSatker) {
            $b->where('pa.id_satker_petugas', (int) $satker);
        }

        $hasil = [];

        foreach ($b->orderBy('pa.id_pa_layanan', 'ASC')->get()->getResultArray() as $aturan) {
            $pejabat = $this->pejabat($aturan, $hariIni);

            if ($pejabat !== null) {
                $hasil[] = $pejabat;
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $aturan
     *
     * @return array{nip: string, nama: string, jabatan: string|null}|null
     */
    private function pejabat(array $aturan, string $hariIni): ?array
    {
        $koord = (string) ($aturan['id_jabatan_koord'] ?? '');
        $jab   = (string) ($aturan['id_jabatan'] ?? '');
        $nama  = $koord !== '' ? ($aturan['jabatan_koord'] ?? null) : ($aturan['jabatan'] ?? null);
        $row   = null;

        if ($koord !== '') {
            $row = $this->aktifMenurut('pmj.id_jabatan_koord', (int) $koord);
        } elseif ($jab !== '') {
            $plh = $this->db->table('pegawai_plh plh')
                ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
                ->join('pegawai p', 'p.nip = plh.nip_plh', 'left')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = plh.nip_plh', 'left')
                ->where('plh.id_jabatan', (int) $jab)->where('plh.status', 1)
                ->where('plh.tgl_mulai <=', $hariIni)->where('plh.tgl_akhir >=', $hariIni)
                ->orderBy('plh.id_pegawai_plh', 'ASC')->limit(1)->get()->getRowArray();

            if ($plh !== null) {
                return $this->hasil($plh, $plh['jabatan'] ?? null);
            }

            $row = $this->aktifMenurut('pmj.id_jabatan', (int) $jab);
        } elseif (($aturan['nip'] ?? null) !== null && $aturan['nip'] !== '') {
            $row = $this->db->table('pegawai p')
                ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
                ->where('p.status', 1)->where('p.nip', (string) $aturan['nip'])
                ->get()->getRowArray();

            if ($row !== null && ($row['jabatan'] ?? '') !== '') {
                $nama = $row['jabatan'];
            }
        }

        if ($row !== null) {
            return $this->hasil($row, $nama === null ? null : (string) $nama);
        }

        if ($koord === '' && $jab === '') {
            return null;
        }

        $plt = $this->db->table('pegawai_plt plt')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir')
            ->join('pegawai p', 'p.nip = plt.nip_plt', 'inner')
            ->where($koord !== '' ? 'plt.id_jabatan_koord' : 'plt.id_jabatan', (int) ($koord !== '' ? $koord : $jab))
            ->where('plt.status', 1)
            ->orderBy('plt.id_pegawai_plt', 'ASC')->limit(1)->get()->getRowArray();

        return $plt === null ? null : $this->hasil($plt, $nama === null ? null : 'Plt. ' . $nama);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function aktifMenurut(string $kolom, int $id): ?array
    {
        return $this->db->table('pegawai_mutasi_jabatan pmj')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
            ->join('pegawai p', 'p.nip = pmj.nip', 'inner')
            ->where($kolom, $id)->where('p.status', 1)
            ->orderBy('pmj.tmtsk', 'DESC')->limit(1)->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{nip: string, nama: string, jabatan: string|null}
     */
    private function hasil(array $row, ?string $jabatan): array
    {
        return [
            'nip'     => (string) $row['nip'],
            'nama'    => StrukturService::namaGelar($row['glr_awal'] ?? null, (string) $row['nama'], $row['glr_akhir'] ?? null),
            'jabatan' => $jabatan,
        ];
    }
}
