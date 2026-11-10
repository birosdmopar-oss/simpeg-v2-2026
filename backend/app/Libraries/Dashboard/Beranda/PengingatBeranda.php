<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Libraries\Integrasi\Stat\KalenderStat;
use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Integrasi\Stat\RekomendasiKgb;
use App\Libraries\Integrasi\Stat\RekomendasiKp;
use App\Libraries\Integrasi\Stat\RekomendasiPensiun;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * 4 kartu pengingat beranda admin — Pensiun, Naik Pangkat, Tanda Jasa, KGB (`views/user/dashboard_ad.php:8-37`) [K].
 * Hitungan `libraries/hr/L_user.php` `dashboard_sa` (kementerian) / `dashboard_satker` / `dashboard_unit` atas
 * `L_employee::get_{all|*_satker|*_unit}_{pensiun,kp,tj,kgb}`. Kalkulasi per pegawai memakai ulang kelas murni port legacy
 * MAKE-061 (`Integrasi\Stat\Rekomendasi*`, dibaca saja) dan {@see ProyeksiTandaJasa}; populasi ditulis di sini per lingkup.
 *
 * Aturan per kartu [K]:
 * - Pensiun: PNS aktif ber-snapshot jabatan; tahun TMT pensiun = tahun berjalan.
 * - KP: PNS aktif; tahun `tgl_kp` (deteksi `get_all_kp`) = tahun berjalan. **[I] beda tepi legacy:** `get_kp_satker|unit`
 *   menghitung percepatan juga untuk `gol_ruang` kosong (indeks tak terdefinisi), `get_all_kp` tidak; di sini semua lingkup
 *   memakai perilaku `get_all_kp` (PNS tanpa golongan sangat jarang; dicatat di README).
 * - Tanda Jasa: PNS aktif. Kementerian: tahun acuan = tahun berjalan bila bulan < 9, selain itu tahun depan; dihitung bila
 *   tahun penghargaan ≤ tahun acuan. Satker/unit: proyeksi tahun berjalan; dihitung bila tanggal penghargaan TEPAT
 *   `{tahun}-08-01` (bulan < 9) atau `{tahun+1}-08-01` (bulan ≥ 9).
 * - KGB: kementerian PNS + PPPK (`id_jenis_pegawai` 1, 6), satker/unit PNS saja; tahun TMT KGB berikutnya ≤ tahun berjalan.
 * Pengecualian NIP (`$whereHide_nip`) TIDAK dipakai di kartu ini (legacy tidak memakainya).
 */
final class PengingatBeranda
{
    private const BATCH = 1000;

    public function __construct(private readonly BaseConnection $db, private readonly string $hariIni)
    {
    }

    /**
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     *
     * @return array{pensiun: int, kp: int, tj: int, kgb: int}
     */
    public function hitung(array $lingkup): array
    {
        if ($lingkup['kosong']) {
            return ['pensiun' => 0, 'kp' => 0, 'tj' => 0, 'kgb' => 0];
        }

        return [
            'pensiun' => $this->pensiun($lingkup),
            'kp'      => $this->kp($lingkup),
            'tj'      => $this->tandaJasa($lingkup),
            'kgb'     => $this->kgb($lingkup),
        ];
    }

    private function tahun(): int
    {
        return (int) substr($this->hariIni, 0, 4);
    }

    /**
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     */
    private function pensiun(array $lingkup): int
    {
        $kelas = new RekomendasiPensiun($this->hariIni);
        $b     = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.tgl_lahir, jab.umur_pensiun, pmj.id_group_jabatan, pmj.id_sub_group_jabatan')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'inner')
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->where('peg.status', 1)
            ->where('peg.id_jenis_pegawai', 1);
        $this->lingkupPmj($b, $lingkup);

        $n = 0;

        foreach ($b->get()->getResultArray() as $r) {
            $p = $kelas->hitung($r['tgl_lahir'] ?? null, $r['umur_pensiun'] ?? null, $r['id_group_jabatan'] ?? null, $r['id_sub_group_jabatan'] ?? null);

            if ($p['tgl_pensiun'] !== '' && KalenderStat::tahunLegacy($p['tgl_pensiun']) === $this->tahun()) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     */
    private function kp(array $lingkup): int
    {
        $perOrder = [];

        foreach ($this->db->table('pangkat')->select('id_pangkat, pangkat, gol_ruang, `order`')->where('status', 1)->orderBy('order', 'ASC')->get()->getResultArray() as $p) {
            $perOrder[(int) $p['order']] = ['id_pangkat' => $p['id_pangkat'], 'pangkat' => (string) $p['pangkat'], 'gol_ruang' => (string) $p['gol_ruang']];
        }

        $kelas = new RekomendasiKp($this->hariIni, $perOrder);
        $b     = $this->db->table('pegawai peg')
            ->select('peg.nip, pkp.gol_ruang, pkp.tmtsk AS tmtsk_pangkat, pkp.id_jenis_kp, pmj.id_sub_group_jabatan, ppen.jenjang_pendidikan_singkat')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->join('pegawai_pendidikan ppen', 'ppen.nip = peg.nip', 'left')
            ->where('peg.status', 1)
            ->where('peg.id_jenis_pegawai', 1);
        $this->lingkupPmj($b, $lingkup);

        $rows       = $b->get()->getResultArray();
        $struktural = $this->tmtStrukturalTerawal(array_column($rows, 'nip'));
        $n          = 0;

        foreach ($rows as $r) {
            $nip    = (string) $r['nip'];
            $eselon = PenyajiStat::eselon($r['id_sub_group_jabatan'] ?? null);
            $subId  = RekomendasiKp::SUB_GROUP_ESELON[$eselon] ?? null;
            $det    = $kelas->deteksi([
                'gol_ruang'     => $r['gol_ruang'] ?? '',
                'tmtsk_pangkat' => $r['tmtsk_pangkat'] ?? '',
                'id_jenis_kp'   => $r['id_jenis_kp'] ?? '',
                'strata'        => $r['jenjang_pendidikan_singkat'] ?? '',
                'eselon'        => $eselon,
            ], $subId === null ? null : ($struktural[$nip][$subId] ?? null));

            if ($det['tgl_kp'] !== '' && KalenderStat::tahunLegacy($det['tgl_kp']) === $this->tahun()) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     */
    private function tandaJasa(array $lingkup): int
    {
        $tahun       = $this->tahun();
        $bulan       = (int) substr($this->hariIni, 5, 2);
        $kementerian = $lingkup['jenis'] === RingkasanBeranda::LINGKUP_KEMENTERIAN;
        $acuan       = $kementerian && $bulan >= 9 ? $tahun + 1 : $tahun;

        $b = $this->db->table('pegawai peg')
            ->select('peg.nip, pcpns.tmtsk AS tmtsk_cpns')
            ->join('pegawai_cpns pcpns', 'pcpns.nip = peg.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('peg.status', 1)
            ->where('peg.id_jenis_pegawai', 1);
        $this->lingkupPmj($b, $lingkup);

        $rows     = $b->get()->getResultArray();
        $dimiliki = $this->tandaJasaDimiliki(array_column($rows, 'nip'));
        $sasaran  = sprintf('%04d-08-01', $bulan < 9 ? $tahun : $tahun + 1);
        $n        = 0;

        foreach ($rows as $r) {
            $p = ProyeksiTandaJasa::berikutnya($r['tmtsk_cpns'] ?? null, $acuan, $dimiliki[(string) $r['nip']] ?? []);

            if ($p === null) {
                continue;
            }

            if ($kementerian ? (int) substr($p['tgl_penghargaan'], 0, 4) <= $acuan : $p['tgl_penghargaan'] === $sasaran) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     */
    private function kgb(array $lingkup): int
    {
        $kelas = new RekomendasiKgb($this->hariIni);
        $b     = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.id_jenis_pegawai, pkgb.tmtsk AS tmtsk_kgb, pcpns.tmtsk AS tmtsk_cpns, pkp.gol_ruang, pmj.tmtsk AS tmtsk_pmj, pmj.gol_pppk')
            ->join('pegawai_kgb pkgb', 'pkgb.nip = peg.nip', 'left')
            ->join('pegawai_cpns pcpns', 'pcpns.nip = peg.nip', 'left')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('peg.status', 1)
            ->whereIn('peg.id_jenis_pegawai', $lingkup['jenis'] === RingkasanBeranda::LINGKUP_KEMENTERIAN ? [1, 6] : [1]);
        $this->lingkupPmj($b, $lingkup);

        $n = 0;

        foreach ($b->get()->getResultArray() as $r) {
            $k = $kelas->hitung($r);

            if ($k['tmtsk_kgb'] !== '' && KalenderStat::tahunLegacy($k['tmtsk_kgb']) <= $this->tahun()) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Saringan `pmj.id_satker` / `pmj.id_unit` (legacy `WHERE pegawai_mutasi_jabatan.id_* = ...`); kementerian tanpa saringan.
     *
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     */
    private function lingkupPmj(BaseBuilder $b, array $lingkup): void
    {
        if ($lingkup['jenis'] === RingkasanBeranda::LINGKUP_SATKER) {
            $b->where('pmj.id_satker', $lingkup['id']);
        } elseif ($lingkup['jenis'] === RingkasanBeranda::LINGKUP_UNIT) {
            $b->where('pmj.id_unit', $lingkup['id']);
        }
    }

    /**
     * TMT jabatan struktural terawal per eselon (salinan perilaku `StatService::tmtStrukturalTerawal`, MAKE-061).
     *
     * @param list<mixed> $nips
     *
     * @return array<string, array<int, string|null>>
     */
    private function tmtStrukturalTerawal(array $nips): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique(array_map('strval', $nips))), self::BATCH) as $potongan) {
            $rows = $this->db->table('riwayat_mutasi_jabatan')
                ->select('nip, id_sub_group_jabatan, tmtsk')
                ->whereIn('nip', $potongan)
                ->whereIn('id_sub_group_jabatan', [1, 2, 3, 4])
                ->where('status', 1)
                ->orderBy('nip', 'ASC')->orderBy('id_sub_group_jabatan', 'ASC')->orderBy('tmtsk', 'ASC')
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $nip = (string) $r['nip'];
                $sub = (int) $r['id_sub_group_jabatan'];

                if (! array_key_exists($sub, $hasil[$nip] ?? [])) {
                    $hasil[$nip][$sub] = $r['tmtsk'] === null ? null : (string) $r['tmtsk'];
                }
            }
        }

        return $hasil;
    }

    /**
     * Tanda jasa 26/27/28 berstatus 1 per NIP (`riwayat_tanda_jasa`, legacy `id_tanda_jasa IN (26,27,28) AND status='1'`).
     *
     * @param list<mixed> $nips
     *
     * @return array<string, list<int>>
     */
    private function tandaJasaDimiliki(array $nips): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique(array_map('strval', $nips))), self::BATCH) as $potongan) {
            $rows = $this->db->table('riwayat_tanda_jasa')->select('nip, id_tanda_jasa')
                ->whereIn('nip', $potongan)
                ->whereIn('id_tanda_jasa', [ProyeksiTandaJasa::ID_XXX, ProyeksiTandaJasa::ID_XX, ProyeksiTandaJasa::ID_X])
                ->where('status', 1)
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $hasil[(string) $r['nip']][] = (int) $r['id_tanda_jasa'];
            }
        }

        return $hasil;
    }
}
