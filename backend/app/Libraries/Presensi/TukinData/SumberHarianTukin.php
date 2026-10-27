<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\JamKerja;
use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Masukan harian `KalkulasiTukin` per tanggal (PRD 5A §7.7.6): `presensi`, `konket`, `cuti`, `tugas_belajar`.
 *
 * Presensi [K rekap `L_presensi.php:12376-12391`, `:12850-12851`]: baris `dh_online` pegawai itu per `tgl` (PK
 * `(nip, tgl)`) tanpa saring `sumber` (online, manual, dan impor legacy diperlakukan sama); jam lokal apa adanya →
 * `{masuk, pulang}`, kolom NULL → null (legacy: string kosong; CHECK `chk_dh_online_jam` menjamin minimal satu jam
 * terisi). Tanggal tanpa baris tidak diberi kunci `presensi` (library menghitungnya TK). Legacy memuat bulan kalender N−1 dan N; rentang
 * [awal, akhir] di sini mencakup periode berjalan seluruhnya, dan hari periode sebelumnya hanya dipakai lookback cuti
 * sakit yang tidak membaca presensi. Jam tidak sah (di luar 00:00:00–23:59:59) → {@see DataTukinTidakSah}.
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

        foreach ($this->presensi($nip, $awal, $akhir) as $tanggal => $presensi) {
            $harian[$tanggal]['presensi'] = $presensi;
        }

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
     * @return array<string, array{masuk: ?string, pulang: ?string}>
     *
     * @throws DataTukinTidakSah
     */
    public function presensi(string $nip, string $awal, string $akhir): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = $this->db->table('dh_online')
            ->select(['tgl', 'jam_masuk', 'jam_pulang'])
            ->where('nip', $nip)
            ->where('tgl >=', $awal)
            ->where('tgl <=', $akhir)
            ->orderBy('tgl', 'ASC')
            ->get()
            ->getResultArray();

        $hasil = [];

        foreach ($baris as $b) {
            $tanggal = (string) $b['tgl'];

            $hasil[$tanggal] = [
                'masuk'  => self::jam($b['jam_masuk'], $nip, $tanggal),
                'pulang' => self::jam($b['jam_pulang'], $nip, $tanggal),
            ];
        }

        return $hasil;
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
     * @throws DataTukinTidakSah
     */
    private static function jam(mixed $jam, string $nip, string $tanggal): ?string
    {
        if ($jam === null || $jam === '') {
            return null;
        }

        try {
            return JamKerja::normalisasiJam((string) $jam);
        } catch (InvalidArgumentException $e) {
            throw new DataTukinTidakSah("Jam presensi pegawai {$nip} tanggal {$tanggal} tidak sah.", 0, $e);
        }
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
