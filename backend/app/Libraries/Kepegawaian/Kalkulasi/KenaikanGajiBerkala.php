<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

/**
 * CR-025 (B-21 "kalkulasi jarak KGB 2 tahun") — validasi jarak TMT KGB dan proyeksi KGB berikutnya.
 *
 * - Jarak minimal 2 tahun dari TMT KGB/KP terakhir = aturan [V2] SRS-FR-026. Legacy tidak memvalidasinya
 *   (`libraries/hr/rwy/L_kgb.php:429-506`, hanya wajib isi + TMT kembar) dan mengakui KGB berjarak 1 tahun
 *   (`L_employee.php:10408-10419`), jadi hanya untuk input pengguna (K-CR025-10).
 * - Proyeksi [K] setia legacy `next_kgb` (`libraries/hr/L_employee.php:4023-4056`; logika sama di `get_all_kgb`
 *   :4180-4200), dengan deviasi terdokumentasi K-CR025-8.
 */
final class KenaikanGajiBerkala
{
    /**
     * Jarak minimal antar-KGB (SRS-FR-026) = siklus KGB legacy `L_employee.php:4041`.
     */
    public const JARAK_MINIMAL_TAHUN = 2;

    /**
     * Masa sejak acuan (bulan) yang masih dianggap jatuh tempo "hari ini" (legacy `L_employee.php:4030-4038`).
     */
    public const BATAS_JATUH_TEMPO_BULAN = 36;

    public const STATUS_BELUM_JATUH_TEMPO = 'belum_jatuh_tempo';
    public const STATUS_JATUH_TEMPO       = 'jatuh_tempo';
    public const STATUS_SIKLUS_TERLEWAT   = 'siklus_terlewat';

    public const LABEL_TMT = 'TMT KGB';

    private function __construct()
    {
    }

    /**
     * Acuan jarak untuk `$tmtBaru` = acuan ber-TMT ≤ `$tmtBaru` yang paling akhir (pendahulu), bukan yang paling akhir
     * secara absolut, agar riwayat lama tetap bisa diisi (K-CR025-7). TMT sama → prioritas {@see JenisReferensiTmt}.
     * `null` bila tidak ada pendahulu.
     *
     * @param list<ReferensiTmt> $referensi
     */
    public static function referensiTerakhir(string $tmtBaru, array $referensi): ?ReferensiTmt
    {
        TanggalBisnis::wajibValid($tmtBaru, 'TMT KGB baru');

        $terpilih = null;

        foreach ($referensi as $ref) {
            if ($ref->tmt > $tmtBaru) {
                continue;
            }

            if ($terpilih === null
                || $ref->tmt > $terpilih->tmt
                || ($ref->tmt === $terpilih->tmt && $ref->jenis->prioritas() < $terpilih->jenis->prioritas())) {
                $terpilih = $ref;
            }
        }

        return $terpilih;
    }

    /**
     * Validasi TMT KGB masukan pengguna: minimal {@see self::JARAK_MINIMAL_TAHUN} tahun (dijepit akhir bulan) dari acuan
     * pendahulu. TMT sama dengan acuan → ditolak. Tanpa pendahulu → valid dengan `referensi_jenis = null`.
     * `detail`: `referensi_jenis`, `referensi_tmt`, `batas_minimal`.
     *
     * @param list<ReferensiTmt> $referensi
     */
    public static function validasiJarak(string $tmtBaru, array $referensi): HasilKalkulasi
    {
        $gagal = TanggalBisnis::periksa($tmtBaru, self::LABEL_TMT);

        if ($gagal !== null) {
            return $gagal;
        }

        $ref = self::referensiTerakhir($tmtBaru, $referensi);

        if ($ref === null) {
            return HasilKalkulasi::berhasil($tmtBaru, ['referensi_jenis' => null, 'referensi_tmt' => null, 'batas_minimal' => null]);
        }

        $batas  = self::jatuhTempo($ref->tmt);
        $detail = ['referensi_jenis' => $ref->jenis->value, 'referensi_tmt' => $ref->tmt, 'batas_minimal' => $batas];

        if ($tmtBaru >= $batas) {
            return HasilKalkulasi::berhasil($tmtBaru, $detail);
        }

        return HasilKalkulasi::gagal(
            'jarak_kgb_kurang_dari_2_tahun',
            sprintf(
                'TMT KGB minimal %d tahun dari %s (%s). TMT KGB paling cepat %s.',
                self::JARAK_MINIMAL_TAHUN,
                $ref->jenis->label(),
                TanggalBisnis::formatIndonesia($ref->tmt),
                TanggalBisnis::formatIndonesia($batas),
            ),
            $detail,
        );
    }

    /**
     * Jatuh tempo pertama = acuan + {@see self::JARAK_MINIMAL_TAHUN} tahun (dijepit akhir bulan).
     */
    public static function jatuhTempo(string $tmtReferensi): string
    {
        return TanggalBisnis::tambahTahun(
            TanggalBisnis::wajibValid($tmtReferensi, 'TMT acuan KGB'),
            self::JARAK_MINIMAL_TAHUN,
        );
    }

    /**
     * Proyeksi TMT KGB berikutnya (legacy `L_employee.php:4023-4045`), `$hariIni` dari {@see TanggalBisnis::hariIni()}:
     *
     * - masa < 24 bulan → acuan + 2 tahun (`belum_jatuh_tempo`);
     * - 24–36 bulan → hari ini (`jatuh_tempo`);
     * - > 36 bulan → acuan + 4 tahun, lalu + 2 tahun selama masih sebelum hari ini (`siklus_terlewat`); tiap siklus
     *   dihitung dari acuan asli agar penjepitan akhir bulan tidak menumpuk.
     *
     * Deviasi [V2] (K-CR025-8): (i) tanpa jam — bila acuan + 4 tahun = hari ini hasilnya hari ini (legacy membandingkan
     * dengan jam sehingga meloncat 2 tahun); (ii) acuan di masa depan → acuan + 2 tahun, masa 0/0 (legacy memakai selisih
     * absolut). Masa sejak acuan memakai {@see TanggalBisnis::selisihBulanPenuh()} (legacy `DateTime::diff` :4051-4056).
     *
     * `detail`: `status`, `jatuh_tempo_awal` (acuan + 2 tahun), `masa_tahun`, `masa_bulan`.
     */
    public static function proyeksiBerikutnya(string $tmtReferensi, string $hariIni): HasilKalkulasi
    {
        $awal = self::jatuhTempo($tmtReferensi);
        TanggalBisnis::wajibValid($hariIni, 'Tanggal hari ini');

        if ($tmtReferensi > $hariIni) {
            return self::proyeksi($awal, self::STATUS_BELUM_JATUH_TEMPO, $awal, 0);
        }

        $masaBulan = TanggalBisnis::selisihBulanPenuh($tmtReferensi, $hariIni);

        if ($masaBulan > self::BATAS_JATUH_TEMPO_BULAN) {
            // Siklus kedua (acuan + 4 tahun) lalu kelipatan 2 tahun berikutnya yang belum lewat (legacy :4031-4035).
            $tahun   = 2 * self::JARAK_MINIMAL_TAHUN;
            $tanggal = TanggalBisnis::tambahTahun($tmtReferensi, $tahun);

            while ($tanggal < $hariIni) {
                $tahun += self::JARAK_MINIMAL_TAHUN;
                $tanggal = TanggalBisnis::tambahTahun($tmtReferensi, $tahun);
            }

            return self::proyeksi($tanggal, self::STATUS_SIKLUS_TERLEWAT, $awal, $masaBulan);
        }

        if ($masaBulan >= 12 * self::JARAK_MINIMAL_TAHUN) {
            return self::proyeksi($hariIni, self::STATUS_JATUH_TEMPO, $awal, $masaBulan);
        }

        return self::proyeksi($awal, self::STATUS_BELUM_JATUH_TEMPO, $awal, $masaBulan);
    }

    private static function proyeksi(string $tanggal, string $status, string $jatuhTempoAwal, int $masaBulan): HasilKalkulasi
    {
        return HasilKalkulasi::berhasil($tanggal, [
            'status'           => $status,
            'jatuh_tempo_awal' => $jatuhTempoAwal,
            'masa_tahun'       => intdiv($masaBulan, 12),
            'masa_bulan'       => $masaBulan % 12,
        ]);
    }
}
