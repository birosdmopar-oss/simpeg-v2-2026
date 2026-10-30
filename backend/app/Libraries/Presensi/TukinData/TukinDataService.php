<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use LogicException;

/**
 * Adapter data v2 → argumen `KalkulasiTukin::hitung()` (PRD 5A §7.7, sisi integrasi D-04/D-05/D-07). Library murni
 * `Presensi/Tukin` tidak diubah; 5A tidak membuat endpoint Tukin (D-08 = 5B).
 *
 * Rentang data = periode sebelumnya (lookback cuti sakit, README Tukin "Kontrak data") s.d. akhir periode berjalan,
 * untuk hari libur maupun harian. Seluruh galat sumber dilempar sebagai {@see GalatDataTukin}.
 *
 * {@see self::siapkanBanyak()} (MAKE-039, PRD 5B §7.1.4): jalur massal untuk laporan/rekap — periode, libur, dan
 * konfigurasi dihitung sekali; harian & data pegawai dimuat per batch `Config\Presensi::$tukinBatchNip` NIP dengan jumlah
 * kueri tetap; galat per pegawai tidak menggagalkan seluruh laporan. Urutan galat per NIP sama dengan `siapkan()`
 * (harian → konfigurasi → data pegawai).
 *
 * [K T-01, CR-083] Pegawai yang `created_at`-nya tidak sebelum akhir periode BKN (hari terakhir periode, jam apa pun)
 * tidak dimuat data hariannya (`harian = []`, semua hari kerja TK), seperti legacy; lihat
 * {@see SumberDataPegawaiTukin::tanpaDataHarian()}. Rekap IL juga untuk pegawai keluar dengan `tmt_status ≤ de_tukin`
 * ({@see SumberDataPegawaiTukin::keluarTanpaDataIl()}, keputusan Horii 30-10): harian & riwayat cuti kosong.
 */
final class TukinDataService
{
    public function __construct(
        private readonly KonfigurasiTukinDariWebConfig $konfigurasi,
        private readonly SumberPeriodeTukin $periode,
        private readonly SumberHariLibur $hariLibur,
        private readonly SumberDataPegawaiTukin $dataPegawai,
        private readonly SumberHarianTukin $harian,
    ) {
    }

    /**
     * Rakitan bawaan dari service aplikasi; sumber cuti/TB dipilih lewat `Config\Presensi::$sumberCutiTb`.
     */
    public static function bawaan(?BaseConnection $db = null): self
    {
        $db ??= db_connect();

        return new self(
            new KonfigurasiTukinDariWebConfig(service('webConfigService')),
            new SumberPeriodeTukin($db),
            new SumberHariLibur(service('hariLiburService')),
            new SumberDataPegawaiTukin($db),
            new SumberHarianTukin($db, self::sumberCutiTb(config(Presensi::class))),
        );
    }

    public static function sumberCutiTb(Presensi $config): SumberCutiTbInterface
    {
        $kelas = $config->sumberCutiTb;

        if (! class_exists($kelas) || ! is_subclass_of($kelas, SumberCutiTbInterface::class)) {
            throw new LogicException("Config\\Presensi::\$sumberCutiTb harus kelas yang mengimplementasikan SumberCutiTbInterface: {$kelas}.");
        }

        return new $kelas();
    }

    /**
     * Argumen siap pakai untuk satu pegawai × satu bulan Tukin.
     *
     * @throws GalatDataTukin
     */
    public function siapkan(string $nip, int $tahun, int $bulan, ?string $hariIni = null, bool $tanpaPegawaiBaru = false): ArgumenTukin
    {
        $bkn     = $this->periode->barisBkn($tahun, $bulan);
        $periode = SumberPeriodeTukin::periode($tahun, $bulan, $bkn);

        [$tahunLalu, $bulanLalu] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        $sebelumnya              = SumberPeriodeTukin::periode($tahunLalu, $bulanLalu, $this->periode->barisBkn($tahunLalu, $bulanLalu));

        $awal  = min($sebelumnya->awal, $periode->awal);
        $libur = $this->hariLibur->untuk($awal, $periode->akhir);

        $tanpa = $this->dataPegawai->tanpaDataHarian([$nip], $periode) !== []
            || ($tanpaPegawaiBaru && $this->dataPegawai->keluarTanpaDataIl([$nip], self::deTukin($periode, $hariIni)) !== []);

        try {
            $pegawai = $this->dataPegawai->untuk($nip, $periode, $bkn['id'] ?? null, $libur, $tanpaPegawaiBaru, $tanpaPegawaiBaru ? self::deTukin($periode, $hariIni) : null);
        } catch (GalatDataTukin $e) {
            $pegawai = $e; // dilempar setelah harian & konfigurasi: urutan galat tetap harian → konfigurasi → pegawai
        }

        $harian      = $tanpa ? [] : $this->harian->untuk($nip, $awal, $periode->akhir, $pegawai instanceof DataPegawaiTukin && $pegawai->lewatiLoopHarian);
        $konfigurasi = $this->konfigurasi->untuk($periode);

        if ($pegawai instanceof GalatDataTukin) {
            throw $pegawai;
        }

        $riwayat = null;

        if ($tanpaPegawaiBaru) {
            $riwayat = $tanpa ? [] : $this->riwayatIl([$nip], $periode, $hariIni)[$nip] ?? null;
            $libur   = $this->liburIl($libur, [$harian], $awal, $periode->akhir, [$riwayat ?? []]);
        }

        return new ArgumenTukin($periode, $libur, $harian, $konfigurasi, $pegawai, $tanpa, $riwayat);
    }

    /**
     * Argumen banyak pegawai × satu bulan Tukin; hasil per NIP = `siapkan()` NIP itu, atau galatnya.
     *
     * @param list<string> $nip
     *
     * @return array<array-key, ArgumenTukin|GalatDataTukin> setiap NIP masukan menjadi kunci
     */
    public function siapkanBanyak(array $nip, int $tahun, int $bulan, ?string $hariIni = null, ?int $ukuranBatch = null, bool $tanpaPegawaiBaru = false): array
    {
        $unik = array_values(array_unique(array_map('strval', $nip)));

        if ($unik === []) {
            return [];
        }

        $bkn     = $this->periode->barisBkn($tahun, $bulan);
        $periode = SumberPeriodeTukin::periode($tahun, $bulan, $bkn);

        [$tahunLalu, $bulanLalu] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        $sebelumnya              = SumberPeriodeTukin::periode($tahunLalu, $bulanLalu, $this->periode->barisBkn($tahunLalu, $bulanLalu));

        $awal  = min($sebelumnya->awal, $periode->awal);
        $libur = $this->hariLibur->untuk($awal, $periode->akhir);

        try {
            $konfigurasi = $this->konfigurasi->untuk($periode);
        } catch (GalatDataTukin $e) {
            $konfigurasi = $e;
        }

        $batas = max(1, $ukuranBatch ?? config(Presensi::class)->tukinBatchNip);
        $tanpa = $this->dataPegawai->tanpaDataHarian($unik, $periode);

        if ($tanpaPegawaiBaru) {
            $tanpa = array_values(array_unique([...$tanpa, ...$this->dataPegawai->keluarTanpaDataIl($unik, self::deTukin($periode, $hariIni))]));
        }
        $hasil = [];

        foreach (array_chunk($unik, $batas) as $batch) {
            $pegawai = $this->dataPegawai->untukBanyak($batch, $periode, $bkn['id'] ?? null, $libur, $tanpaPegawaiBaru, $tanpaPegawaiBaru ? self::deTukin($periode, $hariIni) : null);
            $lewati  = array_values(array_filter($batch, static fn (string $n): bool => ($pegawai[$n] ?? null) instanceof DataPegawaiTukin && $pegawai[$n]->lewatiLoopHarian));
            $harian  = $this->harian->untukBanyak(array_values(array_diff($batch, $tanpa)), $awal, $periode->akhir, $lewati);
            $riwayat = $tanpaPegawaiBaru ? $this->riwayatIl(array_values(array_diff($batch, $tanpa)), $periode, $hariIni) + array_fill_keys($tanpa, []) : [];
            $liburN  = $tanpaPegawaiBaru ? $this->liburIl($libur, $harian, $awal, $periode->akhir, array_map(static fn (?array $r): array => $r ?? [], $riwayat)) : $libur;

            foreach ($batch as $n) {
                $h = $harian[$n] ?? [];
                $p = $pegawai[$n] ?? new DataTukinTidakSah("Pegawai {$n} tidak ditemukan.");

                $hasil[$n] = match (true) {
                    $h instanceof GalatDataTukin           => $h,
                    $konfigurasi instanceof GalatDataTukin => $konfigurasi,
                    $p instanceof GalatDataTukin           => $p,
                    default                                => new ArgumenTukin($periode, $liburN, $h, $konfigurasi, $p, in_array($n, $tanpa, true), $tanpaPegawaiBaru ? ($riwayat[$n] ?? null) : null),
                };
            }
        }

        return $hasil;
    }

    /**
     * `de_tukin` rekap IL (`L_presensi.php:13640`): akhir periode dipotong ke hari ini.
     */
    private static function deTukin(PeriodeTukin $periode, ?string $hariIni): string
    {
        return $hariIni !== null && $hariIni < $periode->akhir ? $hariIni : $periode->akhir;
    }

    /**
     * Riwayat cuti utuh rekap IL per NIP (`$listCuti`, `L_presensi.php:13903-13911`): cuti status 1 yang beririsan
     * [awal periode, `de_tukin`]. Sumber tanpa dukungan riwayat → `null` per NIP (kalkulasi merekonstruksi dari harian);
     * NIP tanpa riwayat → `[]`. T-01 tidak dimuat (sama dengan data harian).
     *
     * @param list<string> $nip
     *
     * @return array<array-key, list<array{id: int, jenis: string, mulai: string, akhir: string, lama: int}>|null>
     */
    private function riwayatIl(array $nip, PeriodeTukin $periode, ?string $hariIni): array
    {
        if ($nip === []) {
            return [];
        }

        $riwayat = $this->harian->riwayatCutiBanyak($nip, $periode->awal, self::deTukin($periode, $hariIni));

        if ($riwayat === null) {
            return array_fill_keys($nip, null);
        }

        $hasil = [];

        foreach ($nip as $n) {
            $hasil[$n] = $riwayat[$n] ?? [];
        }

        return $hasil;
    }

    /**
     * Libur rekap IL mencakup rentang penuh cuti yang dimuat (`$listLibur_per` atas `[mind_cuti, maxd_cuti]`,
     * :14016-14036) — dibutuhkan jadwal potong cuti alasan penting (hari kerja per bulan di seluruh rentang cuti).
     *
     * @param list<string>                                                                   $libur
     * @param array<array-key, array<string, array<string, mixed>>|GalatDataTukin>             $harian  per NIP (galat diabaikan)
     * @param array<array-key, list<array{id: int, jenis: string, mulai: string, akhir: string, lama: int}>> $riwayat riwayat cuti utuh per NIP
     *
     * @return list<string>
     */
    private function liburIl(array $libur, array $harian, string $awal, string $akhir, array $riwayat = []): array
    {
        [$min, $max] = [$awal, $akhir];

        foreach ($riwayat as $daftar) {
            foreach ($daftar as $r) {
                $min = min($min, $r['mulai']);
                $max = max($max, $r['akhir']);
            }
        }

        foreach ($harian as $perTanggal) {
            if (! is_array($perTanggal)) {
                continue;
            }

            foreach ($perTanggal as $input) {
                if (isset($input['cuti']['mulai'], $input['cuti']['akhir'])) {
                    $min = min($min, (string) $input['cuti']['mulai']);
                    $max = max($max, (string) $input['cuti']['akhir']);
                }
            }
        }

        return $min === $awal && $max === $akhir ? $libur : $this->hariLibur->untuk($min, $max);
    }

    /**
     * Periode Tukin bulan (tahun, bulan) termasuk baris BKN (dipakai pemanggil 5B untuk judul laporan).
     */
    public function periode(int $tahun, int $bulan): PeriodeTukin
    {
        return SumberPeriodeTukin::periode($tahun, $bulan, $this->periode->barisBkn($tahun, $bulan));
    }
}
