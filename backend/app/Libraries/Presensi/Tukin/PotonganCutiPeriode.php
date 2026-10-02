<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

/**
 * Potongan cuti besar dan cuti melahirkan yang dijadwalkan **per periode tukin**, mengikuti rekap legacy
 * `laporan_tukin_us_skp` (:12470-12575). Nilainya **menggantikan** total, bukan dijumlahkan (lihat `KalkulasiTukin`).
 *
 * - Hanya cuti yang beririsan dengan periode `[awal, akhir]` (:12475-12479, :12528-12532).
 * - Tanggal potong ke-1 = tanggal 16 bulan `mulai` bila hari `mulai` ≥ 16, selain itu tanggal 16 bulan sebelumnya
 *   (:12481-12493). Ke-2/ke-3 = +1/+2 bulan, bila `lama` (`riwayat_cuti.lama_cuti`) > 35 / > 65 (:12496-12518).
 * - Cuti besar: 5 / 5 / 5 (`CUTI_BESAR_1..3`).
 * - Cuti melahirkan: hanya bila jumlah anak > 3 (:12527); 40 / 70 / 80 (`CUTI_MELAHIRKAN_1..3`).
 * - Potongan berlaku pada periode yang **awal**-nya sama dengan tanggal potong (`$listPotCB[$nip][$strCM_begin]`,
 *   :13033, :13041).
 * - Cuti alasan penting tidak dipotong: rekap menghitung `$listPotonganCuti` (:12415-12466) tetapi tidak memakainya.
 */
final class PotonganCutiPeriode
{
    /**
     * @param array<string, array<string, mixed>> $harian
     *
     * @return array{besar: int|null, melahirkan: int|null} potongan basis poin, `null` bila tidak ada jadwal
     */
    public static function untuk(PeriodeTukin $periode, array $harian, int $jumlahAnak, KonfigurasiTukin $config): array
    {
        $hasil = ['besar' => null, 'melahirkan' => null];

        foreach (self::riwayat($harian) as $cuti) {
            if ($cuti['mulai'] > $periode->akhir || $cuti['akhir'] < $periode->awal) {
                continue;
            }
            if ($cuti['jenis'] === 'melahirkan' && $jumlahAnak <= 3) {
                continue;
            }
            $prefix = $cuti['jenis'] === 'besar' ? 'CUTI_BESAR_' : 'CUTI_MELAHIRKAN_';

            foreach (self::tanggalPotong($cuti['mulai'], $cuti['lama']) as $ke => $tanggal) {
                if ($tanggal === $periode->awal) {
                    // Riwayat diurutkan menurut tanggal mulai; yang terakhir menimpa, seperti `$listPotCM[$nip][$tgl]`.
                    $hasil[$cuti['jenis']] = $config->tarif($prefix . ($ke + 1));
                }
            }
        }

        return $hasil;
    }

    /**
     * @return list<string> tanggal potong ke-1, ke-2, ke-3
     */
    public static function tanggalPotong(string $mulai, int $lama): array
    {
        [$tahun, $bulan, $hari] = TanggalBisnis::urai($mulai);
        if ($hari < 16) {
            [$tahun, $bulan] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        }
        $pertama = TanggalBisnis::susun($tahun, $bulan, 16);
        $jumlah  = $lama > 65 ? 3 : ($lama > 35 ? 2 : 1);
        $hasil   = [];

        for ($i = 0; $i < $jumlah; $i++) {
            $hasil[] = TanggalBisnis::tambahBulan($pertama, $i);
        }

        return $hasil;
    }

    /**
     * Riwayat cuti besar/melahirkan unik dari input harian (boleh mencakup tanggal di luar periode), urut tanggal mulai.
     *
     * @param array<string, array<string, mixed>> $harian
     *
     * @return list<array{jenis: string, mulai: string, akhir: string, lama: int}>
     */
    private static function riwayat(array $harian): array
    {
        $hasil = [];

        foreach ($harian as $input) {
            $jenis = StatusHarian::jenisCuti($input);
            if ($jenis !== 'besar' && $jenis !== 'melahirkan') {
                continue;
            }
            $cuti  = $input['cuti'];
            $mulai = TanggalBisnis::wajibValid((string) ($cuti['mulai'] ?? ''), "awal cuti {$jenis}");
            $akhir = TanggalBisnis::wajibValid((string) ($cuti['akhir'] ?? ''), "akhir cuti {$jenis}");
            if ($mulai > $akhir) {
                throw new InvalidArgumentException("Awal cuti {$jenis} tidak boleh setelah akhirnya.");
            }
            $lama = $cuti['lama'] ?? null;
            if (! is_int($lama) || $lama < 0) {
                throw new InvalidArgumentException("Cuti {$jenis} wajib memiliki lama berupa bilangan bulat non-negatif.");
            }
            $hasil["{$mulai}|{$jenis}|{$akhir}"] = ['jenis' => $jenis, 'mulai' => $mulai, 'akhir' => $akhir, 'lama' => $lama];
        }
        ksort($hasil);

        return array_values($hasil);
    }
}
