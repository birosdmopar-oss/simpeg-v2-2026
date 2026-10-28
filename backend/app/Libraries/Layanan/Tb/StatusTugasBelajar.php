<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Interfaces\Layanan\StatusTugasBelajarInterface;
use App\Libraries\Layanan\Studi\StatusStudi;
use CodeIgniter\Database\BaseConnection;

/**
 * Implementasi ber-DB `StatusTugasBelajarInterface` (PRD Fase 4 §6.7.5, D15; konsumen Fase 5 D-06) —
 * `Config\Services::statusTugasBelajar()`. Memuat `riwayat_tb` status 1 milik NIP beserta `riwayat_tb_perpan`-nya (dua
 * kueri, tanpa N+1), lalu mendelegasikan aturan ke `BebasPresensiTb` (murni): bebas bila tanggal dalam rentang TB ATAU
 * salah satu rentang perpanjangan (inklusif); celah di antaranya tidak bebas (keputusan coder #1 MAKE-024, disetujui).
 */
class StatusTugasBelajar implements StatusTugasBelajarInterface
{
    /** Jumlah NIP (dan id TB untuk perpanjangan) per kueri WHERE IN pada versi massal (MAKE-044). */
    public const BATCH_NIP = 1000;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function bebasPresensiPenuh(string $nip, string $tanggal): bool
    {
        return BebasPresensiTb::bebasPresensiPenuh($this->daftarTb($nip), $tanggal);
    }

    public function rentangBebasPresensi(string $nip, string $dari, string $sampai): array
    {
        return BebasPresensiTb::rentangBebasPresensi($this->daftarTb($nip), $dari, $sampai);
    }

    public function rentangBebasPresensiBanyak(array $nip, string $dari, string $sampai): array
    {
        $unik = array_values(array_unique(array_map('strval', $nip)));

        if ($unik === []) {
            BebasPresensiTb::rentangBebasPresensi([], $dari, $sampai);

            return [];
        }

        $daftar = [];

        foreach (array_chunk($unik, self::BATCH_NIP) as $potongan) {
            $daftar += $this->daftarTbBanyak($potongan);
        }

        return BebasPresensiTb::rentangBebasPresensiBanyak($unik, $daftar, $dari, $sampai);
    }

    /**
     * TB disetujui untuk satu batch NIP, dikelompokkan per NIP — dua kueri per batch (`riwayat_tb` WHERE IN NIP, lalu
     * `riwayat_tb_perpan` WHERE IN id, dipotong per {@see self::BATCH_NIP} id). Bentuk baris sama dengan
     * {@see self::daftarTb()}.
     *
     * @param list<string> $nip
     *
     * @return array<array-key, list<array{status: int, tgl_mulai: string, tgl_akhir: string, perpanjangan: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}>>
     */
    private function daftarTbBanyak(array $nip): array
    {
        $db = $this->db ?? db_connect();
        $tb = $db->table('riwayat_tb')->select('id_riwayat_tb, nip, status, tgl_mulai, tgl_akhir')
            ->whereIn('nip', $nip)->where('status', StatusStudi::DISETUJUI)->orderBy('id_riwayat_tb')->get()->getResultArray();

        if ($tb === []) {
            return [];
        }

        $perpan = [];

        foreach (array_chunk(array_map(static fn (array $r): int => (int) $r['id_riwayat_tb'], $tb), self::BATCH_NIP) as $id) {
            $rows = $db->table('riwayat_tb_perpan')->select('id_riwayat_tb, tgl_mulai_perpan, tgl_akhir_perpan')
                ->whereIn('id_riwayat_tb', $id)->get()->getResultArray();

            foreach ($rows as $r) {
                $perpan[(int) $r['id_riwayat_tb']][] = ['tgl_mulai_perpan' => (string) $r['tgl_mulai_perpan'], 'tgl_akhir_perpan' => (string) $r['tgl_akhir_perpan']];
            }
        }

        $hasil = [];

        foreach ($tb as $r) {
            $hasil[(string) $r['nip']][] = [
                'status'       => (int) $r['status'],
                'tgl_mulai'    => (string) $r['tgl_mulai'],
                'tgl_akhir'    => (string) $r['tgl_akhir'],
                'perpanjangan' => $perpan[(int) $r['id_riwayat_tb']] ?? [],
            ];
        }

        return $hasil;
    }

    /**
     * TB disetujui milik NIP dalam bentuk masukan `BebasPresensiTb`.
     *
     * @return list<array{status: int, tgl_mulai: string, tgl_akhir: string, perpanjangan: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}>
     */
    private function daftarTb(string $nip): array
    {
        $db = $this->db ?? db_connect();
        $tb = $db->table('riwayat_tb')->select('id_riwayat_tb, status, tgl_mulai, tgl_akhir')
            ->where('nip', $nip)->where('status', StatusStudi::DISETUJUI)->get()->getResultArray();

        if ($tb === []) {
            return [];
        }

        $perpan = [];
        $rows   = $db->table('riwayat_tb_perpan')->select('id_riwayat_tb, tgl_mulai_perpan, tgl_akhir_perpan')
            ->whereIn('id_riwayat_tb', array_map(static fn (array $r): int => (int) $r['id_riwayat_tb'], $tb))->get()->getResultArray();

        foreach ($rows as $r) {
            $perpan[(int) $r['id_riwayat_tb']][] = ['tgl_mulai_perpan' => (string) $r['tgl_mulai_perpan'], 'tgl_akhir_perpan' => (string) $r['tgl_akhir_perpan']];
        }

        return array_map(static fn (array $r): array => [
            'status'       => (int) $r['status'],
            'tgl_mulai'    => (string) $r['tgl_mulai'],
            'tgl_akhir'    => (string) $r['tgl_akhir'],
            'perpanjangan' => $perpan[(int) $r['id_riwayat_tb']] ?? [],
        ], $tb);
    }
}
