<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Bawahan atasan di beranda pegawai role 2 (`L_user::dashboard_pns` :1100-1180; view `dashboard_pns.php:207-232`, :331-385) [K]:
 *
 * Penentuan bawahan dari snapshot jabatan atasan (`pegawai_mutasi_jabatan`), pegawai bawahan `status = 1`:
 * - struktural (`id_group_jabatan` 1) atau grup 5 sub 34: sub 4 → `pmj.id_atasan_es_4` = jabatan atasan; sub 3 →
 *   `id_atasan_es_3` = jabatan dan `id_atasan_es_4` NULL; sub 2 atau 34 → `id_atasan_es_2` = jabatan dan es_3/es_4 NULL.
 *   (Cabang Eselon I legacy `== 2` sesudah `== '2'` tidak pernah tercapai — dipertahankan: Eselon I tanpa bawahan.)
 * - selain itu koordinator: `jenis_jabatan_koord` 2 → `id_atasan_es_4_koord` = `id_jabatan_koord`; 1 → `id_atasan_es_3_koord`
 *   = `id_jabatan_koord` dan `id_atasan_es_4`/`id_atasan_es_4_koord` NULL.
 *
 * `presensi`: per bawahan — nama bergelar, jam masuk/pulang `dh_online` hari ini (kosong bila belum presensi).
 * `ak`: hanya bawahan JF (`id_group_jabatan` 2) — AK `getAKAccu` ({@see AkPermenpanrb}); `kurang` = AK sekarang − target bila
 * ≤ 0, selain itu 0 (view); urut `order` = target − sekarang, 1000 bila keduanya 0 (ASC).
 */
final class BawahanPegawai
{
    public function __construct(private readonly BaseConnection $db, private readonly string $hariIni, private readonly ?AkPermenpanrb $ak = null)
    {
    }

    /**
     * @return array{atasan: bool, presensi: list<array{nama: string, jam_masuk: string, jam_pulang: string}>, ak: list<array<string, mixed>>}
     */
    public function untuk(string $nipAtasan): array
    {
        $kosong = ['atasan' => false, 'presensi' => [], 'ak' => []];
        $j      = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nipAtasan)->get()->getRowArray();

        if ($j === null) {
            return $kosong;
        }

        $b = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, pmj.id_group_jabatan')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('peg.status', 1);

        if (! self::saringBawahan($b, $j)) {
            return $kosong;
        }

        $rows = $b->orderBy('peg.nip', 'ASC')->get()->getResultArray();

        if ($rows === []) {
            return $kosong;
        }

        $dh = [];

        foreach ($this->db->table('dh_online')->select('nip, jam_masuk, jam_pulang')->where('tgl', $this->hariIni)->whereIn('nip', array_column($rows, 'nip'))->get()->getResultArray() as $r) {
            $dh[(string) $r['nip']] = $r;
        }

        $presensi = [];
        $ak       = [];
        $kelasAk  = $this->ak ?? new AkPermenpanrb($this->db);

        foreach ($rows as $r) {
            $nip        = (string) $r['nip'];
            $presensi[] = [
                'nama'       => PenyajiStat::namaGelar($r['glr_awal'], $r['nama'], $r['glr_akhir']),
                'jam_masuk'  => (string) ($dh[$nip]['jam_masuk'] ?? ''),
                'jam_pulang' => (string) ($dh[$nip]['jam_pulang'] ?? ''),
            ];

            if ((int) ($r['id_group_jabatan'] ?? 0) === PrediksiPegawai::ID_GROUP_JF) {
                $h      = $kelasAk->hitung($nip);
                $now    = (float) $h['current_ak'];
                $target = (float) $h['ak_for_kp'];
                $ak[]   = [
                    'order'     => $target == 0.0 && $now == 0.0 ? 1000.0 : $target - $now,
                    'nama'      => $h['nama'],
                    'nip'       => $h['nip'],
                    'gol_ruang' => $h['gol_ruang'],
                    'satker'    => $h['satker'],
                    'jabatan'   => $h['jabatan'],
                    'ak_now'    => $now,
                    'ak_kp'     => $target,
                    'kurang'    => $now - $target > 0 ? 0.0 : $now - $target,
                ];
            }
        }

        usort($ak, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return ['atasan' => true, 'presensi' => $presensi, 'ak' => $ak];
    }

    /**
     * @param array<string, mixed> $j snapshot jabatan atasan
     */
    private static function saringBawahan(BaseBuilder $b, array $j): bool
    {
        $grup = (string) ($j['id_group_jabatan'] ?? '');
        $sub  = (string) ($j['id_sub_group_jabatan'] ?? '');
        $jab  = $j['id_jabatan'] ?? null;

        if ($grup === '1' || ($grup === '5' && $sub === '34')) {
            if ($sub === '4') {
                $b->where('pmj.id_atasan_es_4', $jab);
            } elseif ($sub === '3') {
                $b->where('pmj.id_atasan_es_3', $jab)->where('pmj.id_atasan_es_4', null);
            } elseif ($sub === '2' || $sub === '34') {
                $b->where('pmj.id_atasan_es_2', $jab)->where('pmj.id_atasan_es_3', null)->where('pmj.id_atasan_es_4', null);
            } else {
                return false;
            }

            return $jab !== null;
        }

        $koord = $j['id_jabatan_koord'] ?? null;

        if ((string) ($j['jenis_jabatan_koord'] ?? '') === '2') {
            $b->where('pmj.id_atasan_es_4_koord', $koord);
        } elseif ((string) ($j['jenis_jabatan_koord'] ?? '') === '1') {
            $b->where('pmj.id_atasan_es_3_koord', $koord)->where('pmj.id_atasan_es_4', null)->where('pmj.id_atasan_es_4_koord', null);
        } else {
            return false;
        }

        return $koord !== null;
    }
}
