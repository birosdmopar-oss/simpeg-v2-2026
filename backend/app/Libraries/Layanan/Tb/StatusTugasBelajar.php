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
