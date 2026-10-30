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
 *
 * Varian massal {@see self::untukBanyak()} (MAKE-039, keputusan reviewer CR M0 F1 — deviasi aditif PRD 5B §3.3): aturan
 * pemetaan baris sama dengan jalur tunggal (helper bersama), satu kueri `dh_online` + satu kueri `absen_ijin` per
 * {@see self::BATCH_NIP} NIP; data tidak sah satu pegawai menjadi {@see GalatDataTukin} NIP itu saja.
 */
final class SumberHarianTukin
{
    public const BATCH_NIP = 500;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly SumberCutiTbInterface $cutiTb,
    ) {
    }

    /**
     * @param bool $hanyaCutiTb pegawai `lewatiLoopHarian` [K, CR-083, keputusan Horii 30-10]: legacy tidak pernah membaca
     *                          presensi/konket pegawai yang semua hari loopnya di-`continue` (`L_presensi.php:12739-12743`),
     *                          jadi keduanya tidak dimuat (data tidak sah di sana bukan galat); cuti & TB tetap dimuat dan
     *                          divalidasi (cuti sepanjang periode, `PotonganCutiPeriode`, pemutihan TB; F5B-17)
     *
     * @return array<string, array<string, mixed>> per tanggal YYYY-MM-DD (hanya tanggal yang punya data)
     */
    public function untuk(string $nip, string $awal, string $akhir, bool $hanyaCutiTb = false): array
    {
        $harian = [];

        foreach ($hanyaCutiTb ? [] : $this->presensi($nip, $awal, $akhir) as $tanggal => $presensi) {
            $harian[$tanggal]['presensi'] = $presensi;
        }

        foreach ($hanyaCutiTb ? [] : $this->konket($nip, $awal, $akhir) as $tanggal => $konket) {
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
     * Masukan harian banyak pegawai sekaligus; hasil per NIP = {@see self::untuk()} NIP itu, atau galatnya.
     *
     * @param list<string> $nip
     * @param list<string> $hanyaCutiTb NIP yang presensi & konketnya tidak dimuat (lihat {@see self::untuk()})
     *
     * @return array<array-key, array<string, array<string, mixed>>|GalatDataTukin> setiap NIP masukan menjadi kunci
     */
    public function untukBanyak(array $nip, string $awal, string $akhir, array $hanyaCutiTb = []): array
    {
        $unik = array_values(array_unique(array_map('strval', $nip)));

        if ($unik === []) {
            return [];
        }

        $harian = array_fill_keys($unik, []);
        $galat  = [];

        foreach (array_chunk(array_values(array_diff($unik, $hanyaCutiTb)), self::BATCH_NIP) as $batch) {
            foreach ($this->barisPresensi($batch, $awal, $akhir) as $n => $baris) {
                try {
                    foreach (self::petakanPresensi((string) $n, $baris) as $tanggal => $presensi) {
                        $harian[$n][$tanggal]['presensi'] = $presensi;
                    }
                } catch (GalatDataTukin $e) {
                    $galat[$n] ??= $e;
                }
            }

            foreach ($this->barisKonket($batch, $awal, $akhir) as $n => $baris) {
                foreach (self::petakanKonket($baris, $awal, $akhir) as $tanggal => $konket) {
                    $harian[$n][$tanggal]['konket'] = $konket;
                }
            }
        }

        foreach ($this->cutiTbBanyak($unik, $awal, $akhir) as $n => $data) {
            if ($data instanceof GalatDataTukin) {
                $galat[$n] ??= $data;

                continue;
            }

            foreach ($data as $tanggal => $isi) {
                foreach (['tugas_belajar', 'cuti'] as $kunci) {
                    if (array_key_exists($kunci, $isi)) {
                        $harian[$n][$tanggal][$kunci] = $isi[$kunci];
                    }
                }
            }
        }

        foreach ($harian as $n => $perTanggal) {
            if (isset($galat[$n])) {
                $harian[$n] = $galat[$n];

                continue;
            }

            ksort($perTanggal, SORT_STRING);
            $harian[$n] = $perTanggal;
        }

        return $harian;
    }

    /**
     * Riwayat cuti utuh per NIP bila sumber cuti/TB mendukungnya ({@see SumberRiwayatCutiInterface}, rekap IL CR-085);
     * selain itu `null` (kalkulasi IL merekonstruksi dari input harian).
     *
     * @param list<string> $nip
     *
     * @return array<string, list<array{id: int, jenis: string, mulai: string, akhir: string, lama: int}>>|null
     */
    public function riwayatCutiBanyak(array $nip, string $awal, string $akhir): ?array
    {
        return $this->cutiTb instanceof SumberRiwayatCutiInterface ? $this->cutiTb->riwayatCutiBanyak($nip, $awal, $akhir) : null;
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

        return self::petakanPresensi($nip, $baris);
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

        return self::petakanKonket($baris, $awal, $akhir);
    }

    /**
     * Baris `dh_online` banyak NIP dikelompokkan per NIP, urut `tgl` naik (sama dengan {@see self::presensi()}).
     *
     * @param list<string> $nip
     *
     * @return array<array-key, list<array<string, mixed>>>
     */
    private function barisPresensi(array $nip, string $awal, string $akhir): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = $this->db->table('dh_online')
            ->select(['nip', 'tgl', 'jam_masuk', 'jam_pulang'])
            ->whereIn('nip', $nip)
            ->where('tgl >=', $awal)
            ->where('tgl <=', $akhir)
            ->orderBy('nip', 'ASC')
            ->orderBy('tgl', 'ASC')
            ->get()
            ->getResultArray();

        return self::kelompokkan($baris);
    }

    /**
     * Baris `absen_ijin` banyak NIP dikelompokkan per NIP, urut `date_start`, `id` naik (sama dengan {@see self::konket()}).
     *
     * @param list<string> $nip
     *
     * @return array<array-key, list<array<string, mixed>>>
     */
    private function barisKonket(array $nip, string $awal, string $akhir): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = $this->db->table('absen_ijin')
            ->select(['nip', 'date_start', 'date_end', 'affect_tukin', 'kategori'])
            ->whereIn('nip', $nip)
            ->where('status', 'V')
            ->where('affect_tukin', 1)
            ->where('DATE(date_start) <=', $akhir)
            ->where('DATE(date_end) >=', $awal)
            ->orderBy('nip', 'ASC')
            ->orderBy('date_start', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return self::kelompokkan($baris);
    }

    /**
     * Cuti/TB banyak NIP: varian massal bila sumbernya mendukung, selain itu `untuk()` per NIP (galat per NIP).
     *
     * @param list<string> $nip
     *
     * @return array<array-key, array<string, array<string, mixed>>|GalatDataTukin>
     */
    private function cutiTbBanyak(array $nip, string $awal, string $akhir): array
    {
        if ($this->cutiTb instanceof SumberCutiTbMassalInterface) {
            return $this->cutiTb->untukBanyak($nip, $awal, $akhir);
        }

        $hasil = [];

        foreach ($nip as $n) {
            try {
                $hasil[$n] = $this->cutiTb->untuk($n, $awal, $akhir);
            } catch (GalatDataTukin $e) {
                $hasil[$n] = $e;
            }
        }

        return $hasil;
    }

    /**
     * @param list<array<string, mixed>> $baris
     *
     * @return array<array-key, list<array<string, mixed>>>
     */
    private static function kelompokkan(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $b) {
            $hasil[(string) $b['nip']][] = $b;
        }

        return $hasil;
    }

    /**
     * @param list<array<string, mixed>> $baris baris `dh_online` satu pegawai, urut `tgl` naik
     *
     * @return array<string, array{masuk: ?string, pulang: ?string}>
     *
     * @throws DataTukinTidakSah
     */
    private static function petakanPresensi(string $nip, array $baris): array
    {
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
     * @param list<array<string, mixed>> $baris baris `absen_ijin` satu pegawai, urut `date_start`, `id` naik
     *
     * @return array<string, array{affect_tukin: int, kategori: int}>
     */
    private static function petakanKonket(array $baris, string $awal, string $akhir): array
    {
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
