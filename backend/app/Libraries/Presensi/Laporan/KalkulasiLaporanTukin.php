<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Presensi\Rekap\PenyesuaianTukin;
use App\Libraries\Presensi\Tukin\HasilTukin;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\DataTukinBelumTersedia;
use App\Libraries\Presensi\TukinData\GalatDataTukin;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinTidakLengkap;
use App\Libraries\Presensi\TukinData\RentangPuasaGanda;
use App\Libraries\Presensi\TukinData\TukinDataService;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use InvalidArgumentException;

/**
 * Mesin bersama laporan per pegawai dan rekap Tukin (PRD 5B §7.3.2–7.3.3) — **satu** kalkulasi rekap (K-1, [V2] dicatat:
 * angka layar per pegawai = baris rekap). Per NIP: `TukinDataService::siapkanBanyak()` + `KalkulasiTukin::hitung()`
 * (dipotong hari ini), lalu data keterangan per batch dengan jumlah kueri tetap: `pegawai.id_jenis_status` (CLTN = 3),
 * `penyesuaian_tukin` (DBV-024), `riwayat_cuti` status 1 di periode, `riwayat_tb` status 1 (institusi) — susunan teks di
 * {@see PenyesuaianTukin}.
 *
 * Galat terkendali adapter → kode snake_case (keputusan field `code` 27-10): `konfigurasi_tukin_tidak_lengkap`,
 * `rentang_puasa_ganda`, `data_tukin_belum_tersedia`, `data_tukin_tidak_sah`.
 */
final class KalkulasiLaporanTukin
{
    private ?TukinDataService $data;

    public function __construct(private readonly BaseConnection $db, ?TukinDataService $data = null)
    {
        $this->data = $data;
    }

    public function periode(int $tahun, int $bulan): PeriodeTukin
    {
        return $this->data()->periode($tahun, $bulan);
    }

    /**
     * @param list<string> $nip
     *
     * @return array<array-key, array{hasil: HasilTukin, penyesuaian: array{persen: string, alasan: string}|null, keterangan: string}|array{galat: array{code: string, message: string}}>
     */
    public function hitung(array $nip, int $tahun, int $bulan, string $hariIni): array
    {
        $argumen = $this->data()->siapkanBanyak($nip, $tahun, $bulan, $hariIni);
        $periode = $this->periode($tahun, $bulan);
        $bulanTk = sprintf('%04d-%02d-01', $tahun, $bulan);
        $hasil   = [];

        foreach (array_chunk(array_map('strval', array_keys($argumen)), max(1, config(Presensi::class)->tukinBatchNip)) as $batch) {
            $info = $this->info($batch, $periode);

            foreach ($batch as $n) {
                $a = $argumen[$n];

                if ($a instanceof GalatDataTukin) {
                    $hasil[$n] = ['galat' => self::galat($a)];

                    continue;
                }

                try {
                    $h = KalkulasiTukin::hitung($a->periode, $a->hariLibur, $a->harian, $a->konfigurasi, $hariIni, $a->pegawai);
                } catch (InvalidArgumentException $e) {
                    $hasil[$n] = ['galat' => ['code' => 'data_tukin_tidak_sah', 'message' => 'Data Tukin pegawai tidak sah: ' . $e->getMessage()]];

                    continue;
                }

                $adaTb       = array_filter($h->harian, static fn ($hh): bool => $hh->status === 'tugas_belajar') !== [];
                $penyesuaian = PenyesuaianTukin::aktif($info['penyesuaian'][$n] ?? [], $bulanTk);

                $hasil[$n] = [
                    'hasil'       => $h,
                    'penyesuaian' => $penyesuaian,
                    'keterangan'  => PenyesuaianTukin::keterangan($penyesuaian, ($info['cltn'][$n] ?? false), $adaTb ? ($info['tb'][$n] ?? '') : null, $info['cuti'][$n] ?? []),
                ];
            }
        }

        return $hasil;
    }

    /**
     * Basis poin → persen dua desimal (`250` → `"2.50"`).
     */
    public static function persen(int $bp): string
    {
        return ($bp < 0 ? '-' : '') . intdiv(abs($bp), 100) . '.' . str_pad((string) (abs($bp) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{code: string, message: string}
     */
    public static function galat(GalatDataTukin $e): array
    {
        $kode = match (true) {
            $e instanceof KonfigurasiTukinTidakLengkap => 'konfigurasi_tukin_tidak_lengkap',
            $e instanceof RentangPuasaGanda            => 'rentang_puasa_ganda',
            $e instanceof DataTukinBelumTersedia       => 'data_tukin_belum_tersedia',
            default                                    => 'data_tukin_tidak_sah',
        };

        return ['code' => $kode, 'message' => $e->getMessage()];
    }

    /**
     * @param list<string> $nip
     *
     * @return array{cltn: array<array-key, bool>, penyesuaian: array<array-key, list<array<string, mixed>>>, cuti: array<array-key, list<array{0: string, 1: string, 2: string}>>, tb: array<array-key, string>}
     */
    private function info(array $nip, PeriodeTukin $periode): array
    {
        $hasil = ['cltn' => [], 'penyesuaian' => [], 'cuti' => [], 'tb' => []];

        if ($nip === []) {
            return $hasil;
        }

        foreach ($this->db->table('pegawai')->select('nip, id_jenis_status')->whereIn('nip', $nip)->get()->getResultArray() as $b) {
            $hasil['cltn'][(string) $b['nip']] = (int) ($b['id_jenis_status'] ?? 0) === 3;
        }

        foreach ($this->db->table('penyesuaian_tukin')->select('id_penyesuaian_tukin, nip, bulan_mulai, bulan_akhir, persen, alasan, status')
            ->whereIn('nip', $nip)->where('status', 1)->get()->getResultArray() as $b) {
            $hasil['penyesuaian'][(string) $b['nip']][] = $b;
        }

        foreach ($this->db->table('riwayat_cuti')->select('nip, nama_jenis_cuti, tgl_mulai, tgl_akhir')->whereIn('nip', $nip)->where('status', 1)
            ->where('tgl_mulai <=', $periode->akhir)->where('tgl_akhir >=', $periode->awal)
            ->orderBy('nip', 'ASC')->orderBy('tgl_mulai', 'ASC')->orderBy('id_riwayat_cuti', 'ASC')->get()->getResultArray() as $b) {
            $hasil['cuti'][(string) $b['nip']][] = [(string) $b['nama_jenis_cuti'], (string) $b['tgl_mulai'], (string) $b['tgl_akhir']];
        }

        foreach ($this->db->table('riwayat_tb')->select('nip, institusi_pendidikan')->whereIn('nip', $nip)->where('status', 1)
            ->where('tgl_mulai <=', $periode->akhir)
            ->orderBy('nip', 'ASC')->orderBy('tgl_mulai', 'ASC')->orderBy('id_riwayat_tb', 'ASC')->get()->getResultArray() as $b) {
            $hasil['tb'][(string) $b['nip']] = (string) ($b['institusi_pendidikan'] ?? '');
        }

        return $hasil;
    }

    private function data(): TukinDataService
    {
        return $this->data ??= TukinDataService::bawaan($this->db);
    }
}
