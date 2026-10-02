<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

/**
 * Jadwal potongan cuti yang dikenakan **sekali per bulan**, bukan per hari (legacy `laporan_tukin` :4304-4555,
 * diterapkan pada tanggal jadwal di :4605-4607).
 *
 * - Cuti besar: bulan ke-1 selalu, ke-2 bila lama kalender > 35 hari, ke-3 bila > 65 hari (:4430-4478).
 * - Cuti melahirkan: hanya bila anak yang sudah lahir sebelum cuti ≥ 3 (anak ke-4 dst.); bulan ke-1 bila lama > 0,
 *   ke-2 bila lama ≥ 32, ke-3 bila lama ≥ 62 (:4488-4548).
 * - Bulan ke-1 = bulan mulai bila tanggal mulai ≤ separuh jumlah hari bulan itu (dibulatkan ke bawah), selain itu bulan
 *   berikutnya; tanggal jadwal selalu tanggal 1 (:4436-4447).
 * - Cuti alasan penting: bila lama > 14 hari, potongan sekali pada hari kerja pertama dari bulan yang memuat hari kerja
 *   cuti terbanyak (seri → bulan paling awal) (:4310-4360).
 */
final class PotonganCutiBulanan
{
    /**
     * @param array<string, array<string, mixed>> $harian
     * @param array<string, true>                 $libur
     *
     * @return array<string, int> tanggal → potongan basis poin
     */
    public static function jadwal(array $harian, array $libur, KonfigurasiTukin $config): array
    {
        $jadwal = [];

        foreach (self::riwayat($harian) as $cuti) {
            foreach (self::untuk($cuti, $harian, $libur, $config) as $tanggal => $potongan) {
                $jadwal[$tanggal] = ($jadwal[$tanggal] ?? 0) + $potongan;
            }
        }

        return $jadwal;
    }

    /**
     * @param array{jenis: string, mulai: string, akhir: string, lama: int, anak_sebelumnya: int} $cuti
     * @param array<string, array<string, mixed>>                                                $harian
     * @param array<string, true>                                                                $libur
     *
     * @return array<string, int>
     */
    private static function untuk(array $cuti, array $harian, array $libur, KonfigurasiTukin $config): array
    {
        if ($cuti['jenis'] === 'besar') {
            $hari = count(HariKerja::kalender($cuti['mulai'], $cuti['akhir']));

            return self::bertahap($cuti['mulai'], [
                [$config->tarif('CUTI_BESAR_1'), true],
                [$config->tarif('CUTI_BESAR_2'), $hari > 35],
                [$config->tarif('CUTI_BESAR_3'), $hari > 65],
            ]);
        }
        if ($cuti['jenis'] === 'melahirkan') {
            if ($cuti['anak_sebelumnya'] < 3) {
                return [];
            }

            return self::bertahap($cuti['mulai'], [
                [$config->tarif('CUTI_MELAHIRKAN_1'), $cuti['lama'] > 0],
                [$config->tarif('CUTI_MELAHIRKAN_2'), $cuti['lama'] >= 32],
                [$config->tarif('CUTI_MELAHIRKAN_3'), $cuti['lama'] >= 62],
            ]);
        }

        // alasan_penting
        if ($cuti['lama'] <= 14) {
            return [];
        }
        $perBulan = [];

        foreach (HariKerja::kalender($cuti['mulai'], $cuti['akhir']) as $tanggal) {
            if (! HariKerja::hariKerja($tanggal, $libur) || StatusHarian::tugasBelajar($harian[$tanggal] ?? []) !== null) {
                continue;
            }
            $bulan = substr($tanggal, 0, 7);
            $perBulan[$bulan] ??= ['tanggal' => $tanggal, 'hari' => 0];
            $perBulan[$bulan]['hari']++;
        }
        $terpilih = null;

        foreach ($perBulan as $bulan) {
            if ($terpilih === null || $bulan['hari'] > $terpilih['hari']) {
                $terpilih = $bulan;
            }
        }

        return $terpilih === null ? [] : [$terpilih['tanggal'] => $config->tarif('CUTI_ALASAN_PENTING')];
    }

    /**
     * Tahap berhenti pada syarat pertama yang tidak terpenuhi (legacy mensyaratkan tahap sebelumnya ada).
     *
     * @param list<array{0: int, 1: bool}> $tahap pasangan [tarif, syarat] untuk bulan ke-1, ke-2, ke-3
     *
     * @return array<string, int>
     */
    private static function bertahap(string $mulai, array $tahap): array
    {
        [$tahun, $bulan, $hari] = TanggalBisnis::urai($mulai);
        $tanggal                = TanggalBisnis::susun($tahun, $bulan, 1);
        if ($hari > intdiv(TanggalBisnis::jumlahHari($tahun, $bulan), 2)) {
            $tanggal = TanggalBisnis::tambahBulan($tanggal, 1);
        }
        $hasil = [];

        foreach ($tahap as [$tarif, $syarat]) {
            if (! $syarat) {
                break;
            }
            $hasil[$tanggal] = $tarif;
            $tanggal         = TanggalBisnis::tambahBulan($tanggal, 1);
        }

        return $hasil;
    }

    /**
     * Riwayat cuti bulanan unik dari input harian (boleh mencakup tanggal di luar periode).
     *
     * @param array<string, array<string, mixed>> $harian
     *
     * @return list<array{jenis: string, mulai: string, akhir: string, lama: int, anak_sebelumnya: int}>
     */
    private static function riwayat(array $harian): array
    {
        $hasil = [];

        foreach ($harian as $input) {
            $jenis = StatusHarian::jenisCuti($input);
            if (! in_array($jenis, ['besar', 'melahirkan', 'alasan_penting'], true)) {
                continue;
            }
            $cuti  = $input['cuti'];
            $mulai = TanggalBisnis::wajibValid((string) ($cuti['mulai'] ?? ''), "awal cuti {$jenis}");
            $akhir = TanggalBisnis::wajibValid((string) ($cuti['akhir'] ?? ''), "akhir cuti {$jenis}");
            if ($mulai > $akhir) {
                throw new InvalidArgumentException("Awal cuti {$jenis} tidak boleh setelah akhirnya.");
            }
            $hasil["{$jenis}|{$mulai}|{$akhir}"] = [
                'jenis'           => $jenis,
                'mulai'           => $mulai,
                'akhir'           => $akhir,
                'lama'            => $jenis === 'besar' ? 0 : self::bilangan($cuti, 'lama', $jenis),
                'anak_sebelumnya' => $jenis === 'melahirkan' ? self::bilangan($cuti, 'anak_sebelumnya', $jenis) : 0,
            ];
        }

        return array_values($hasil);
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private static function bilangan(array $cuti, string $kunci, string $jenis): int
    {
        $nilai = $cuti[$kunci] ?? null;
        if (! is_int($nilai) || $nilai < 0) {
            throw new InvalidArgumentException("Cuti {$jenis} wajib memiliki {$kunci} berupa bilangan bulat non-negatif.");
        }

        return $nilai;
    }
}
