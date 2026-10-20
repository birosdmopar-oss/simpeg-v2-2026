<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * Masukan harian `KalkulasiTukin` per tanggal (PRD 5A §7.7.6): `konket`, `cuti`, `tugas_belajar` (+ `presensi` dari
 * `dh_online` setelah DBV-023/D-01 merged).
 *
 * Konket [K rekap `L_presensi.php:12343-12373`]: `absen_ijin` `status = 'V'` dan `affect_tukin = 1` yang rentang
 * tanggalnya (`DATE(date_start)`..`DATE(date_end)`) beririsan dengan [awal, akhir]; setiap tanggal dalam rentang
 * mendapat `{affect_tukin, kategori}`; tanggal yang tercakup lebih dari satu konket memakai baris terakhir menurut
 * urutan `date_start` naik (legacy menimpa entri per tanggal). Cuti & tugas belajar lewat {@see SumberCutiTbInterface}
 * (5A: fail-closed).
 */
final class SumberHarianTukin
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SumberCutiTbInterface $cutiTb,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> per tanggal YYYY-MM-DD (hanya tanggal yang punya data)
     */
    public function untuk(string $nip, string $awal, string $akhir): array
    {
        $harian = [];

        foreach ($this->konket($nip, $awal, $akhir) as $tanggal => $konket) {
            $harian[$tanggal]['konket'] = $konket;
        }

        foreach ($this->cutiTb->untuk($nip, $awal, $akhir) as $tanggal => $data) {
            foreach (['tugas_belajar', 'cuti'] as $kunci) {
                if (array_key_exists($kunci, $data)) {
                    $harian[$tanggal][$kunci] = $data[$kunci];
                }
            }
        }

        ksort($harian, SORT_STRING);

        return $harian;
    }

    /**
     * @return array<string, array{affect_tukin: int, kategori: int}>
     */
    public function konket(string $nip, string $awal, string $akhir): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = $this->db->table('absen_ijin')
            ->select(['date_start', 'date_end', 'affect_tukin', 'kategori'])
            ->where('nip', $nip)
            ->where('status', 'V')
            ->where('affect_tukin', 1)
            ->where('DATE(date_start) <=', $akhir)
            ->where('DATE(date_end) >=', $awal)
            ->orderBy('date_start', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $hasil = [];

        foreach ($baris as $b) {
            $mulai   = max(substr((string) $b['date_start'], 0, 10), $awal);
            $selesai = min(substr((string) $b['date_end'], 0, 10), $akhir);

            foreach (self::tanggal($mulai, $selesai) as $tanggal) {
                $hasil[$tanggal] = ['affect_tukin' => (int) $b['affect_tukin'], 'kategori' => (int) $b['kategori']];
            }
        }

        return $hasil;
    }

    /**
     * @return list<string>
     */
    private static function tanggal(string $mulai, string $selesai): array
    {
        if ($mulai > $selesai) {
            return [];
        }

        $hasil = [];

        foreach (new DatePeriod(new DateTimeImmutable($mulai), new DateInterval('P1D'), (new DateTimeImmutable($selesai))->modify('+1 day')) as $hari) {
            $hasil[] = $hari->format('Y-m-d');
        }

        return $hasil;
    }
}
