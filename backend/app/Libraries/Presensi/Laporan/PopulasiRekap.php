<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\Database\BaseConnection;

/**
 * Populasi pegawai rekap bulan kalender (PRD 5B §7.3.3, §7.4) — port `L_employee::get_all_pegawai_dh_spe` /
 * `get_all_pegawai_il_spe` legacy `0cf7e67` [K], dengan jumlah kueri tetap per {@see self::BATCH_NIP} NIP.
 *
 * - **DH** (`L_employee.php:6398-6615`): `pegawai` `status = 1` atau (`status = 2` dan `tmt_status >` awal bulan),
 *   `DATE(created_at) ≤` akhir bulan, `id_jenis_pegawai ∈ jenis`. Jabatan/unit/satker/kelas = baris `riwayat_mutasi_jabatan`
 *   `status = 1`, `jenis_jabatan = 1`, `tmtsk ≤` akhir bulan terbaru (seri → id terbesar [V2]); pangkat = `riwayat_kp`
 *   `status = 1`, `tmtsk ≤` awal bulan terbaru. Lingkup unit/satker diterapkan pada jabatan berlaku (tanpa jabatan → hanya
 *   lingkup kementerian) [K `L_presensi.php:505-541`].
 * - **IL** (`:6617-6775`): `pegawai` ⋈ `pegawai_mutasi_jabatan` `jenis_jabatan = 2`, status sama, `DATE(created_at) ≤`
 *   **awal** bulan [K]; jabatan dari `riwayat_mutasi_jabatan` `jenis_jabatan = 2`, `tmtsk ≤` awal bulan; tanpa filter jenis
 *   pegawai & lingkup.
 * - Urutan [K `:6607-6611`]: `kelas_jabatan` turun, `gol_ruang` turun (teks), TMT pangkat naik, `id_satker` naik; lalu NIP
 *   naik [V2] agar stabil. Daftar NIP tester legacy tidak di-port (F5B-12).
 */
final class PopulasiRekap
{
    public const BATCH_NIP = 500;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<int> $jenisPegawai
     *
     * @return list<array{nip: string, nama: string, jabatan: string, id_unit: int|null, id_satker: int|null, unit: string, satker: string}>
     */
    public function dh(string $awal, string $akhir, LingkupLaporan $lingkup, array $jenisPegawai): array
    {
        $pegawai = $this->db->table('pegawai')
            ->select('nip, nama, glr_awal, glr_akhir')
            ->groupStart()->where('status', 1)->orGroupStart()->where('status', 2)->where('tmt_status >', $awal)->groupEnd()->groupEnd()
            ->where('DATE(created_at) <=', $akhir)
            ->whereIn('id_jenis_pegawai', $jenisPegawai === [] ? [0] : $jenisPegawai)
            ->orderBy('nip', 'ASC')
            ->get()->getResultArray();

        $hasil = $this->lengkapi($pegawai, 1, $akhir, $awal);

        return array_values(array_filter($hasil, static fn (array $p): bool => $lingkup->mencakup($p)));
    }

    /**
     * @return list<array{nip: string, nama: string, jabatan: string, id_unit: int|null, id_satker: int|null, unit: string, satker: string}>
     */
    public function il(string $awal): array
    {
        $pegawai = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.nama, peg.glr_awal, peg.glr_akhir')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'inner')
            ->groupStart()->where('peg.status', 1)->orGroupStart()->where('peg.status', 2)->where('peg.tmt_status >', $awal)->groupEnd()->groupEnd()
            ->where('DATE(peg.created_at) <=', $awal)
            ->where('pmj.jenis_jabatan', 2)
            ->orderBy('peg.nip', 'ASC')
            ->get()->getResultArray();

        return $this->lengkapi($pegawai, 2, $awal, $awal);
    }

    /**
     * @param list<array<string, mixed>> $pegawai
     *
     * @return list<array{nip: string, nama: string, jabatan: string, id_unit: int|null, id_satker: int|null, unit: string, satker: string}>
     */
    private function lengkapi(array $pegawai, int $jenisJabatan, string $batasJabatan, string $batasPangkat): array
    {
        $hasil = [];

        foreach (array_chunk($pegawai, self::BATCH_NIP) as $batch) {
            $nip     = array_map(static fn (array $p): string => (string) $p['nip'], $batch);
            $jabatan = $this->terbaru('riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', 'nip, jabatan, id_unit, id_satker, unit, satker, kelas_jabatan', $nip, $batasJabatan, ['jenis_jabatan' => $jenisJabatan]);
            $pangkat = $this->terbaru('riwayat_kp', 'id_riwayat_kp', 'nip, gol_ruang, tmtsk', $nip, $batasPangkat, []);

            foreach ($batch as $p) {
                $n = (string) $p['nip'];
                $j = $jabatan[$n] ?? [];
                $k = $pangkat[$n] ?? [];

                $hasil[] = [
                    'nip'       => $n,
                    'nama'      => StrukturService::namaGelar($p['glr_awal'] ?? null, (string) ($p['nama'] ?? ''), $p['glr_akhir'] ?? null),
                    'jabatan'   => (string) ($j['jabatan'] ?? ''),
                    'id_unit'   => isset($j['id_unit']) ? (int) $j['id_unit'] : null,
                    'id_satker' => isset($j['id_satker']) ? (int) $j['id_satker'] : null,
                    'unit'      => (string) ($j['unit'] ?? ''),
                    'satker'    => (string) ($j['satker'] ?? ''),
                    '_kelas'    => (int) ($j['kelas_jabatan'] ?? 0),
                    '_gol'      => (string) ($k['gol_ruang'] ?? ''),
                    '_tmt'      => (string) ($k['tmtsk'] ?? '9999-12-31'),
                ];
            }
        }

        usort($hasil, static fn (array $a, array $b): int => [$b['_kelas'], $b['_gol'], $a['_tmt'], $a['id_satker'] ?? PHP_INT_MAX, $a['nip']]
            <=> [$a['_kelas'], $a['_gol'], $b['_tmt'], $b['id_satker'] ?? PHP_INT_MAX, $b['nip']]);

        return array_map(static fn (array $p): array => array_diff_key($p, ['_kelas' => 1, '_gol' => 1, '_tmt' => 1]), $hasil);
    }

    /**
     * Baris riwayat status 1 terbaru (`tmtsk DESC`, id DESC) per NIP dengan `tmtsk ≤ $batas`.
     *
     * @param list<string>          $nip
     * @param array<string, mixed>  $syarat
     *
     * @return array<string, array<string, mixed>>
     */
    private function terbaru(string $tabel, string $pk, string $kolom, array $nip, string $batas, array $syarat): array
    {
        $baris = $this->db->table($tabel)->select($kolom)->whereIn('nip', $nip)->where('status', 1)->where('tmtsk <=', $batas)->where($syarat)
            ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'DESC')->orderBy($pk, 'DESC')->get()->getResultArray();
        $hasil = [];

        foreach ($baris as $b) {
            $hasil[(string) $b['nip']] ??= $b;
        }

        return $hasil;
    }
}
