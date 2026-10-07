<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

/**
 * Data per pegawai × periode yang dibutuhkan rekap legacy `laporan_tukin_us_skp` selain input harian.
 *
 * - `predikatSkp`: `riwayat_skp_periodik.hasil_akhir` periode BKN itu (:12394-12410); `null` = tidak ada data.
 * - `jumlahAnak`: jumlah `detail_anak` dengan `tgl_lahir` ≤ akhir periode pada `riwayat_keluarga` aktif (:12170-12198);
 *   syarat potongan cuti melahirkan `> 3` (:12527).
 * - `tmtMasuk`: hari sebelum tanggal ini di luar masa kerja (pegawai baru, :12699-12716, :12738-12742). Adapter mengisi
 *   sesuai syarat legacy: CPNS (`gol_ruang` diawali "CPNS"), atau PPPK dengan TMT setelah hari kerja pertama periode.
 * - `tmtKeluar`: [V2] hari pada/sesudah `pegawai.tmt_status` (status 2) di luar masa kerja; legacy tidak membatasinya.
 */
final readonly class DataPegawaiTukin
{
    public function __construct(
        public ?string $predikatSkp = null,
        public int $jumlahAnak = 0,
        public ?string $tmtMasuk = null,
        public ?string $tmtKeluar = null,
    ) {
        if ($jumlahAnak < 0) {
            throw new InvalidArgumentException('Jumlah anak tidak boleh negatif.');
        }
        if ($tmtMasuk !== null) {
            TanggalBisnis::wajibValid($tmtMasuk, 'TMT masuk');
        }
        if ($tmtKeluar !== null) {
            TanggalBisnis::wajibValid($tmtKeluar, 'TMT keluar');
        }
        if ($tmtMasuk !== null && $tmtKeluar !== null && $tmtKeluar <= $tmtMasuk) {
            throw new InvalidArgumentException('TMT keluar harus setelah TMT masuk.');
        }
    }

    /** Tanggal berada dalam masa kerja: ≥ TMT masuk [K] dan < TMT keluar [V2]. */
    public function dalamMasaKerja(string $tanggal): bool
    {
        return ($this->tmtMasuk === null || $tanggal >= $this->tmtMasuk)
            && ($this->tmtKeluar === null || $tanggal < $this->tmtKeluar);
    }

    /**
     * Potongan SKP periodik (:12400-12407, :12636): "sangat baik"/"baik"/"butuh perbaikan" = 0, "kurang" =
     * `SKP_KURANG`, lainnya atau tidak ada data = `SKP_TIDAK_ADA`. Perbandingan `strtolower` tanpa trim, seperti legacy.
     */
    public function potonganSkp(KonfigurasiTukin $config): int
    {
        $predikat = $this->predikatSkp === null ? null : strtolower($this->predikatSkp);

        return match ($predikat) {
            'sangat baik', 'baik', 'butuh perbaikan' => 0,
            'kurang'                                 => $config->tarif('SKP_KURANG'),
            default                                  => $config->tarif('SKP_TIDAK_ADA'),
        };
    }
}
