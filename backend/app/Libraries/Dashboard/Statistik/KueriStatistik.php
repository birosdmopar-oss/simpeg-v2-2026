<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Kueri agregat statistik F-03 (PRD Fase 7 §7.1.3), padanan `libraries/hr/L_chart.php` legacy.
 *
 * Sumber = `pegawai` + snapshot aktif `pegawai_*` (PK nip), bukan `riwayat_*` [K]. Populasi: `pegawai.status = 1`
 * AND `id_jenis_pegawai IN (1 PNS, 6 PPPK)` [K `L_chart.php:41`]; PTT tidak dihitung (F7-4); tanpa pengecualian akun
 * uji (F7-5). Kategori (sumbu X) = unit status 1 (lingkup kementerian), satker status 1 di bawah unit (lingkup unit),
 * atau satu satker; pegawai di unit/satker nonaktif tidak dihitung (F7-10). Agregat selalu `COUNT(DISTINCT nip)`.
 * `PegawaiScope::terapkanKeQuery` dipasang di setiap builder (F7-3).
 */
final class KueriStatistik
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly PegawaiScopeInterface $scope,
    ) {
    }

    /**
     * Kategori sumbu X untuk lingkup (urut `order`, lalu id).
     *
     * @return list<array{id: string, label: string}>
     */
    public function kategori(LingkupStatistik $lingkup): array
    {
        if ($lingkup->tipe === LingkupStatistik::KEMENTERIAN) {
            $rows = $this->db->table('unit')->select('id_unit AS id, unit AS label')->where('status', 1)
                ->orderBy('order', 'ASC')->orderBy('id_unit', 'ASC')->get()->getResultArray();
        } elseif ($lingkup->tipe === LingkupStatistik::UNIT) {
            $rows = $this->db->table('satker')->select('id_satker AS id, satker AS label')->where('status', 1)
                ->where('id_unit', $lingkup->id)->orderBy('order', 'ASC')->orderBy('id_satker', 'ASC')->get()->getResultArray();
        } else {
            $rows = $this->db->table('satker')->select('id_satker AS id, satker AS label')->where('status', 1)
                ->where('id_satker', $lingkup->id)->get()->getResultArray();
        }

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'label' => (string) $r['label']], $rows);
    }

    /**
     * Seri + matriks [kategori_id][seri_key] => jumlah untuk satu jenis statistik.
     *
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    public function hitung(AuthContext $auth, string $jenis, LingkupStatistik $lingkup, string $hariIni): array
    {
        return match ($jenis) {
            StatistikService::UNIT_KERJA    => $this->unitKerja($auth, $lingkup),
            StatistikService::JENIS_KELAMIN => $this->jenisKelamin($auth, $lingkup),
            StatistikService::JABATAN       => $this->jabatan($auth, $lingkup),
            StatistikService::ESELON        => $this->eselon($auth, $lingkup),
            StatistikService::PENDIDIKAN    => $this->pendidikan($auth, $lingkup),
            StatistikService::USIA          => $this->usia($auth, $lingkup, $hariIni),
            StatistikService::GOLONGAN      => $this->golongan($auth, $lingkup),
            default                         => throw new InvalidArgumentException("Jenis statistik tidak dikenal: {$jenis}."),
        };
    }

    // ------------------------------------------------------------------
    // Per jenis
    // ------------------------------------------------------------------

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function unitKerja(AuthContext $auth, LingkupStatistik $l): array
    {
        $peta = ['1' => 'pns', '6' => 'pppk'];

        return [
            'seri'    => [['key' => 'pns', 'label' => 'PNS'], ['key' => 'pppk', 'label' => 'PPPK']],
            'matriks' => $this->agregat($this->dasar($auth, $l), $l, 'peg.id_jenis_pegawai', static fn (string $d): ?string => $peta[$d] ?? null),
        ];
    }

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function jenisKelamin(AuthContext $auth, LingkupStatistik $l): array
    {
        // 1 = Laki-laki, selain itu Perempuan [K `L_chart.php:489`].
        return [
            'seri'    => [['key' => 'l', 'label' => 'Laki-laki'], ['key' => 'p', 'label' => 'Perempuan']],
            'matriks' => $this->agregat($this->dasar($auth, $l), $l, "CASE WHEN peg.jenis_kelamin = 1 THEN 'l' ELSE 'p' END", static fn (string $d): string => $d),
        ];
    }

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function jabatan(AuthContext $auth, LingkupStatistik $l): array
    {
        // group_jabatan 1 Struktural, 2 Fungsional, 3 Pelaksana, lainnya (termasuk NULL) Lain-lain [K `L_chart.php:1931`].
        $dim = "CASE pmj.id_group_jabatan WHEN 1 THEN 'struktural' WHEN 2 THEN 'fungsional' WHEN 3 THEN 'pelaksana' ELSE 'lain' END";

        return [
            'seri' => [
                ['key' => 'struktural', 'label' => 'Struktural'],
                ['key' => 'fungsional', 'label' => 'Fungsional'],
                ['key' => 'pelaksana', 'label' => 'Pelaksana'],
                ['key' => 'lain', 'label' => 'Lain-lain'],
            ],
            'matriks' => $this->agregat($this->dasar($auth, $l), $l, $dim, static fn (string $d): string => $d),
        ];
    }

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function eselon(AuthContext $auth, LingkupStatistik $l): array
    {
        // Seri = master sub_group_jabatan group 1 (struktural) status 1 urut id, label apa adanya (F7-8).
        $seri = [];

        foreach ($this->db->table('sub_group_jabatan')->where('id_group_jabatan', 1)->where('status', 1)->orderBy('id_sub_group_jabatan', 'ASC')->get()->getResultArray() as $r) {
            $seri[] = ['key' => 'e' . $r['id_sub_group_jabatan'], 'label' => (string) $r['sub_group_jabatan']];
        }

        $b = $this->dasar($auth, $l)
            ->join('sub_group_jabatan sgj', 'sgj.id_sub_group_jabatan = pmj.id_sub_group_jabatan')
            ->where('pmj.id_group_jabatan', 1)
            ->where('sgj.status', 1);

        return ['seri' => $seri, 'matriks' => $this->agregat($b, $l, 'sgj.id_sub_group_jabatan', static fn (string $d): string => 'e' . $d)];
    }

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function pendidikan(AuthContext $auth, LingkupStatistik $l): array
    {
        // LEFT JOIN + kategori "Tanpa data" agar total = statistik unit kerja (F7-9; legacy INNER JOIN).
        $seri = [];

        foreach ($this->db->table('jenjang_pendidikan')->where('status', 1)->orderBy('order', 'ASC')->orderBy('id_jenjang_pendidikan', 'ASC')->get()->getResultArray() as $r) {
            $seri[] = ['key' => 'j' . $r['id_jenjang_pendidikan'], 'label' => (string) $r['jenjang_pendidikan_singkat']];
        }

        $seri[] = ['key' => 'tanpa', 'label' => 'Tanpa data'];

        $b = $this->dasar($auth, $l)
            ->join('pegawai_pendidikan ppen', 'ppen.nip = peg.nip', 'left')
            ->join('jenjang_pendidikan jpen', 'jpen.id_jenjang_pendidikan = ppen.id_jenjang_pendidikan AND jpen.status = 1', 'left');

        return ['seri' => $seri, 'matriks' => $this->agregat($b, $l, 'COALESCE(jpen.id_jenjang_pendidikan, 0)', static fn (string $d): string => $d === '0' ? 'tanpa' : 'j' . $d)];
    }

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function usia(AuthContext $auth, LingkupStatistik $l, string $hariIni): array
    {
        $dim = 'TIMESTAMPDIFF(YEAR, peg.tgl_lahir, ' . $this->db->escape($hariIni) . ')';

        return [
            'seri'    => BucketUsia::seri(),
            'matriks' => $this->agregat($this->dasar($auth, $l), $l, $dim, static fn (string $d): string => BucketUsia::key((int) $d)),
        ];
    }

    /**
     * @return array{seri: list<array{key: string, label: string}>, matriks: array<string, array<string, int>>}
     */
    private function golongan(AuthContext $auth, LingkupStatistik $l): array
    {
        // PNS: pangkat aktif dari snapshot pegawai_kp; PPPK: gol_pppk dari snapshot pmj (F7-7). Dua kueri, digabung.
        $pns = $this->agregat(
            $this->dasar($auth, $l)
                ->join('pegawai_kp pkp', 'pkp.nip = peg.nip')
                ->join('pangkat pang', 'pang.id_pangkat = pkp.id_pangkat')
                ->where('pang.status', 1)
                ->where('peg.id_jenis_pegawai', 1),
            $l,
            'pang.id_pangkat',
            static fn (string $d): string => 'p' . $d,
        );
        $pppk = $this->agregat(
            $this->dasar($auth, $l)
                ->join('gol_pppk gpp', 'gpp.id_gol_pppk = pmj.id_gol_pppk')
                ->where('gpp.status', 1)
                ->where('peg.id_jenis_pegawai', 6),
            $l,
            'gpp.id_gol_pppk',
            static fn (string $d): string => 'k' . $d,
        );

        $idPangkat = [];
        $idGol     = [];

        foreach ($pns as $perSeri) {
            foreach (array_keys($perSeri) as $k) {
                $idPangkat[] = (int) substr((string) $k, 1);
            }
        }

        foreach ($pppk as $perSeri) {
            foreach (array_keys($perSeri) as $k) {
                $idGol[] = (int) substr((string) $k, 1);
            }
        }

        $pangkat = $idPangkat === [] ? [] : $this->db->table('pangkat')->select('id_pangkat, gol_ruang, `order`')->whereIn('id_pangkat', array_unique($idPangkat))->get()->getResultArray();
        $gol     = $idGol === [] ? [] : $this->db->table('gol_pppk')->select('id_gol_pppk, gol_pppk, `order`')->whereIn('id_gol_pppk', array_unique($idGol))->get()->getResultArray();
        $urutan  = UrutanGolongan::susun($pangkat, $gol);

        $matriks = [];

        foreach ([$pns, $pppk] as $sumber) {
            foreach ($sumber as $kategori => $perSeri) {
                foreach ($perSeri as $k => $n) {
                    $key = $urutan['peta'][$k] ?? null;

                    if ($key !== null) {
                        $matriks[$kategori][$key] = ($matriks[$kategori][$key] ?? 0) + $n;
                    }
                }
            }
        }

        return ['seri' => $urutan['seri'], 'matriks' => $matriks];
    }

    // ------------------------------------------------------------------
    // Builder dasar & agregasi
    // ------------------------------------------------------------------

    /**
     * `pegawai peg` JOIN `pegawai_mutasi_jabatan pmj` + unit/satker status 1 sesuai lingkup + populasi + PegawaiScope.
     */
    private function dasar(AuthContext $auth, LingkupStatistik $l): BaseBuilder
    {
        $b = $this->db->table('pegawai peg')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip')
            ->where('peg.status', 1)
            ->whereIn('peg.id_jenis_pegawai', [1, 6]);

        if ($l->tipe === LingkupStatistik::KEMENTERIAN) {
            $b->join('unit unt', 'unt.id_unit = pmj.id_unit')->where('unt.status', 1);
        } else {
            $b->join('satker sat', 'sat.id_satker = pmj.id_satker')->where('sat.status', 1);
            $l->tipe === LingkupStatistik::UNIT ? $b->where('pmj.id_unit', $l->id) : $b->where('pmj.id_satker', $l->id);
        }

        $this->scope->terapkanKeQuery($b, $auth, 'peg.nip');

        return $b;
    }

    /**
     * GROUP BY kategori × dimensi → [kategori_id][seri_key] => COUNT(DISTINCT nip). $keSeri memetakan nilai dimensi ke
     * key seri (null = tidak dihitung).
     *
     * @param callable(string): ?string $keSeri
     *
     * @return array<string, array<string, int>>
     */
    private function agregat(BaseBuilder $b, LingkupStatistik $l, string $dimensi, callable $keSeri): array
    {
        $kategori = $l->tipe === LingkupStatistik::KEMENTERIAN ? 'pmj.id_unit' : 'pmj.id_satker';
        $rows     = $b->select("{$kategori} AS kategori, {$dimensi} AS dimensi, COUNT(DISTINCT peg.nip) AS total", false)
            ->groupBy(['kategori', 'dimensi'])
            ->get()
            ->getResultArray();

        $matriks = [];

        foreach ($rows as $r) {
            if ($r['kategori'] === null || $r['dimensi'] === null) {
                continue;
            }

            $key = $keSeri((string) $r['dimensi']);

            if ($key === null) {
                continue;
            }

            $kat                 = (string) $r['kategori'];
            $matriks[$kat][$key] = ($matriks[$kat][$key] ?? 0) + (int) $r['total'];
        }

        return $matriks;
    }
}
