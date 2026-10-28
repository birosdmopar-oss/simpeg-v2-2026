<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use CodeIgniter\Database\BaseConnection;

/**
 * Pemuat data rekap kehadiran satu batch NIP × satu bulan kalender (PRD 5B §7.4.1): tiga SELECT tetap, bukan per pegawai
 * per hari. Cuti `riwayat_cuti` status 1 [K `L_presensi.php:547-563`]; konket `absen_ijin` status `V` tanpa saring
 * `affect_tukin` [K `:567-591`], urut `date_start`, id (baris belakangan menimpa tanggal yang sama); presensi `dh_online`.
 */
final class DataRekapBulan
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $nip
     *
     * @return array{
     *     cuti: array<array-key, list<array{0: string, 1: string}>>,
     *     konket: array<array-key, list<array{mulai: string, akhir: string, kategori: int}>>,
     *     presensi: array<array-key, array<string, array{masuk: ?string, pulang: ?string}>>
     * }
     */
    public static function muat(BaseConnection $db, array $nip, string $awal, string $akhir): array
    {
        $hasil = ['cuti' => [], 'konket' => [], 'presensi' => []];

        foreach ($db->table('riwayat_cuti')->select('nip, tgl_mulai, tgl_akhir')->whereIn('nip', $nip)->where('status', 1)
            ->where('tgl_mulai <=', $akhir)->where('tgl_akhir >=', $awal)->get()->getResultArray() as $b) {
            $hasil['cuti'][(string) $b['nip']][] = [(string) $b['tgl_mulai'], (string) $b['tgl_akhir']];
        }

        foreach ($db->table('absen_ijin')->select('nip, date_start, date_end, kategori')->whereIn('nip', $nip)->where('status', 'V')
            ->where('DATE(date_start) <=', $akhir)->where('DATE(date_end) >=', $awal)
            ->orderBy('nip', 'ASC')->orderBy('date_start', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray() as $b) {
            $hasil['konket'][(string) $b['nip']][] = ['mulai' => (string) $b['date_start'], 'akhir' => (string) $b['date_end'], 'kategori' => (int) $b['kategori']];
        }

        foreach ($db->table('dh_online')->select('nip, tgl, jam_masuk, jam_pulang')->whereIn('nip', $nip)
            ->where('tgl >=', $awal)->where('tgl <=', $akhir)->get()->getResultArray() as $b) {
            $hasil['presensi'][(string) $b['nip']][(string) $b['tgl']] = [
                'masuk'  => $b['jam_masuk'] === null ? null : (string) $b['jam_masuk'],
                'pulang' => $b['jam_pulang'] === null ? null : (string) $b['jam_pulang'],
            ];
        }

        return $hasil;
    }
}
