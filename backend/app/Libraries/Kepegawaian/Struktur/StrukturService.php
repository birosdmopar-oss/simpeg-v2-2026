<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Struktur;

use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\StrukturServiceInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * B-19 Struktur Organisasi (WS-2 M4 MAKE-012).
 *
 * Sumber [K]: `controllers/hr/So.php` full(), `controllers/hr/rwy/Jabatan.php` so(), `libraries/hr/rwy/L_jabatan.php`
 * so_012025() (:6402), helper `getPLT()` (`helpers/function_helper.php:1946`), `formatNamaGelar()` (:102).
 *
 * [V2] Legacy merender templat bagan yang di-hard-code per bentuk organisasi (kementerian/sesmen/deputi/inspektorat/
 * UPT/BPO, tiga generasi templat) dan bisa mundur per bulan sejak 2022. v2 membangun pohon generik dari master
 * `struktur_jabatan` periode aktif (`periode_struktur_jabatan` status 1 terbaru, legacy `list_all_atasan`): simpul =
 * jabatan, induk = `id_jabatan_atasan`; urut `level_struktur`, `urutan`. Hanya kondisi hari ini; mundur per bulan &
 * ekspor PDF di luar M4.
 *
 * Pejabat simpul (legacy so_012025 :6431-6460 + getPLT):
 *  1. definitif = `pegawai_mutasi_jabatan` jenis 1 dengan `id_jabatan` simpul, pegawai berstatus aktif (1); lebih dari
 *     satu → TMT terbaru;
 *  2. bila kosong: Plt (`pegawai_plt`) lalu Plh (`pegawai_plh`) jenis 1 dengan `id_jabatan` simpul yang berlaku hari ini
 *     (`tgl_mulai <= hari ini <= tgl_akhir`) dan [V2] berstatus 1 (legacy getPLT tidak menyaring status; v2 punya
 *     status 2 Tidak Aktif dan 10 Dihapus).
 * Golongan dari snapshot `pegawai_kp`. Teks `pejabat_so` = format legacy "Nama, Gol, NIP" (Plt./Plh. di depan).
 */
final class StrukturService implements StrukturServiceInterface
{
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function pohon(AuthContext $auth, array $filter = []): array
    {
        $idUnit   = $this->idFilter($filter, 'id_unit');
        $idSatker = $this->idFilter($filter, 'id_satker');
        $db       = $this->db();

        /** @var array{id_periode_struktur_jabatan: int|string, periode_struktur_jabatan: string}|null $periode */
        $periode = $db->table('periode_struktur_jabatan')->where('status', 1)
            ->orderBy('id_periode_struktur_jabatan', 'DESC')->limit(1)->get()->getRowArray();

        $hasil = ['periode' => null, 'filter' => ['id_unit' => $idUnit, 'id_satker' => $idSatker], 'nodes' => []];

        if ($periode === null) {
            return $hasil;
        }

        $hasil['periode'] = [
            'id_periode_struktur_jabatan' => (int) $periode['id_periode_struktur_jabatan'],
            'periode_struktur_jabatan'    => (string) $periode['periode_struktur_jabatan'],
        ];

        $baris = $this->simpul((int) $periode['id_periode_struktur_jabatan'], $idUnit, $idSatker);

        if ($baris === []) {
            return $hasil;
        }

        $pejabat = $this->pejabat(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['id_jabatan'], $baris))));

        // Simpul per id_jabatan (struktur unik per periode + jabatan + atasan; jabatan ganda atasan → simpul pertama).
        $nodes = [];

        foreach ($baris as $r) {
            $id = (int) $r['id_jabatan'];

            if (isset($nodes[$id])) {
                continue;
            }

            $nodes[$id] = [
                'id'             => 'sj-' . $r['id_struktur_jabatan'],
                'id_jabatan'     => $id,
                'id_atasan'      => $r['id_jabatan_atasan'] === null ? null : (int) $r['id_jabatan_atasan'],
                'jabatan'        => (string) $r['jabatan'],
                'level_struktur' => (int) $r['level_struktur'],
                'id_unit'        => $r['id_unit'] === null ? null : (int) $r['id_unit'],
                'unit'           => $r['unit'],
                'id_satker'      => $r['id_satker'] === null ? null : (int) $r['id_satker'],
                'satker'         => $r['satker'],
                'pejabat'        => $pejabat[$id] ?? null,
                'children'       => [],
            ];
        }

        $hasil['nodes'] = $this->rangkai($nodes);

        return $hasil;
    }

    /**
     * Baris struktur_jabatan aktif periode itu + jabatan/satker/unit, disaring unit/satker jabatan.
     *
     * @return list<array<string, mixed>>
     */
    private function simpul(int $idPeriode, ?int $idUnit, ?int $idSatker): array
    {
        $builder = $this->db()->table('struktur_jabatan sj')
            ->select('sj.id_struktur_jabatan, sj.id_jabatan, sj.id_jabatan_atasan, sj.level_struktur, sj.urutan, jab.jabatan, '
                . 'jab.id_satker, s.satker, s.id_unit, u.unit')
            ->join('jabatan jab', 'jab.id_jabatan = sj.id_jabatan', 'inner')
            ->join('satker s', 's.id_satker = jab.id_satker', 'left')
            ->join('unit u', 'u.id_unit = s.id_unit', 'left')
            ->where('sj.id_periode_struktur_jabatan', $idPeriode)
            ->where('sj.status', 1)
            ->where('jab.status', 1);

        if ($idSatker !== null) {
            $builder->where('jab.id_satker', $idSatker);
        } elseif ($idUnit !== null) {
            $builder->where('s.id_unit', $idUnit);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy('sj.level_struktur', 'ASC')->orderBy('sj.urutan', 'ASC')->orderBy('sj.id_struktur_jabatan', 'ASC')
            ->get()->getResultArray();

        return $rows;
    }

    /**
     * Pejabat per id_jabatan: definitif, lalu Plt, lalu Plh.
     *
     * @param list<int> $idJabatan
     *
     * @return array<int, array{nip: string, nama_lengkap: string, gol_ruang: string|null, jenis: string, pejabat_so: string}>
     */
    private function pejabat(array $idJabatan): array
    {
        $db    = $this->db();
        $hasil = [];

        /** @var list<array<string, mixed>> $definitif */
        $definitif = $db->table('pegawai_mutasi_jabatan pmj')
            ->select('pmj.id_jabatan, pmj.tmtsk, p.nip, p.glr_awal, p.nama, p.glr_akhir, kp.gol_ruang')
            ->join('pegawai p', 'p.nip = pmj.nip', 'inner')
            ->join('pegawai_kp kp', 'kp.nip = p.nip', 'left')
            ->whereIn('pmj.id_jabatan', $idJabatan)
            ->where('pmj.jenis_jabatan', 1)
            ->where('p.status', 1)
            ->orderBy('pmj.tmtsk', 'DESC')->orderBy('p.nip', 'ASC')
            ->get()->getResultArray();

        foreach ($definitif as $r) {
            $hasil[(int) $r['id_jabatan']] ??= $this->orang($r, 'definitif');
        }

        $hariIni = Time::today()->toDateString();

        foreach (['plt' => 'nip_plt', 'plh' => 'nip_plh'] as $jenis => $kolomNip) {
            $sisa = array_values(array_diff($idJabatan, array_keys($hasil)));

            if ($sisa === []) {
                break;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $db->table("pegawai_{$jenis} t")
                ->select('t.id_jabatan, p.nip, p.glr_awal, p.nama, p.glr_akhir, kp.gol_ruang')
                ->join('pegawai p', "p.nip = t.{$kolomNip}", 'inner')
                ->join('pegawai_kp kp', 'kp.nip = p.nip', 'left')
                ->whereIn('t.id_jabatan', $sisa)
                ->where('t.jenis_jabatan', 1)
                ->where('t.status', 1)
                ->where('t.tgl_mulai <=', $hariIni)
                ->where('t.tgl_akhir >=', $hariIni)
                ->orderBy('t.tgl_mulai', 'DESC')->orderBy("t.id_pegawai_{$jenis}", 'DESC')
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $hasil[(int) $r['id_jabatan']] ??= $this->orang($r, $jenis);
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{nip: string, nama_lengkap: string, gol_ruang: string|null, jenis: string, pejabat_so: string}
     */
    private function orang(array $r, string $jenis): array
    {
        $nama = self::namaGelar($r['glr_awal'] ?? null, (string) $r['nama'], $r['glr_akhir'] ?? null);
        $gol  = trim((string) ($r['gol_ruang'] ?? ''));
        $teks = implode(', ', array_filter([$nama, $gol, (string) $r['nip']], static fn (string $v): bool => $v !== ''));

        return [
            'nip'          => (string) $r['nip'],
            'nama_lengkap' => $nama,
            'gol_ruang'    => $gol === '' ? null : $gol,
            'jenis'        => $jenis,
            'pejabat_so'   => match ($jenis) {
                'plt'   => "Plt. {$teks}",
                'plh'   => "Plh. {$teks}",
                default => $teks,
            },
        ];
    }

    /**
     * Legacy `formatNamaGelar()`: "<gelar depan> <nama>, <gelar belakang>".
     */
    public static function namaGelar(?string $awal, string $nama, ?string $akhir): string
    {
        $hasil = trim(trim((string) $awal) . ' ' . trim($nama));

        return trim((string) $akhir) === '' ? $hasil : "{$hasil}, " . trim((string) $akhir);
    }

    /**
     * Rangkai simpul menjadi hutan: induk = id_atasan bila atasan ada di himpunan (setelah filter); selain itu akar.
     * Siklus data (A atasan B atasan A) diputus: simpul yang sudah dikunjungi tidak dirangkai ulang.
     *
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function rangkai(array $nodes): array
    {
        $anak = [];
        $akar = [];

        foreach ($nodes as $id => $node) {
            $atasan = $node['id_atasan'];

            if ($atasan !== null && $atasan !== $id && isset($nodes[$atasan])) {
                $anak[$atasan][] = $id;
            } else {
                $akar[] = $id;
            }
        }

        $dikunjungi = [];
        $bangun     = function (int $id) use (&$bangun, &$dikunjungi, $nodes, $anak): array {
            $dikunjungi[$id] = true;
            $node            = $nodes[$id];

            foreach ($anak[$id] ?? [] as $idAnak) {
                if (! isset($dikunjungi[$idAnak])) {
                    $node['children'][] = $bangun($idAnak);
                }
            }

            return $node;
        };

        $hasil = [];

        foreach ($akar as $id) {
            $hasil[] = $bangun($id);
        }

        // Simpul dalam siklus tanpa akar tetap ditampilkan sebagai akar.
        foreach (array_keys($nodes) as $id) {
            if (! isset($dikunjungi[$id])) {
                $hasil[] = $bangun($id);
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function idFilter(array $filter, string $kunci): ?int
    {
        $nilai = $filter[$kunci] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (! is_string($nilai) || preg_match('/^[1-9][0-9]{0,9}$/', $nilai) !== 1 || (int) $nilai > 2147483647) {
            throw ValidationException::forField($kunci, 'Filter tidak valid.');
        }

        return (int) $nilai;
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
