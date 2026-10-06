<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

/**
 * Konfigurasi Tukin yang seluruh nilainya diinjeksikan oleh pemanggil (adapter `web_config` menyusul).
 *
 * Tarif diterima dalam **persen** seperti nilai `web_config` legacy (int atau string desimal, mis. `'1.75'`) lalu
 * dikonversi tepat ke **basis poin** integer (1% = 100 bp) tanpa float. Presisi maksimal dua desimal; nilai dengan
 * lebih dari dua desimal ditolak, bukan dibulatkan.
 *
 * Potongan harian (TL/PSW/TA/TK dan cuti sakit) dikalikan faktor presensi 0,2 seperti rekap legacy
 * `laporan_tukin_us_skp` (`L_presensi.php:12799`, `:12854`, `:12888-13016`). Hasil perkalian wajib bulat dalam basis
 * poin; tarif yang tidak habis dibagi (mis. `0.01`) ditolak saat konstruksi, bukan dibulatkan diam-diam.
 */
final readonly class KonfigurasiTukin
{
    /** Kunci `web_config` legacy (`L_presensi.php:11812`); wajib ada. `LKH` tidak dipakai jalur aktif. */
    public const KUNCI_WAJIB = ['TL1/PSW1', 'TL2/PSW2', 'TL3/PSW3', 'TA', 'TK'];

    /**
     * Tarif yang di rekap legacy di-hard-code (persen). Boleh ditimpa lewat konfigurasi.
     *
     * - `CUTI_SAKIT`           :12799 (0,2% = 1% × faktor presensi, per hari kerja mulai hari ke-15 berurutan)
     * - `CUTI_BESAR_1..3`      :12502-12518 (menggantikan total presensi pada periode jadwal)
     * - `CUTI_MELAHIRKAN_1..3` :12555-12571 (menggantikan total potongan pada periode jadwal)
     * - `SKP_KURANG`           :12406 (predikat SKP periodik "kurang")
     * - `SKP_TIDAK_ADA`        :12400, :12636 (predikat lain atau tidak ada data)
     */
    public const TARIF_BAWAAN = [
        'CUTI_SAKIT'        => 1,
        'CUTI_BESAR_1'      => 5,
        'CUTI_BESAR_2'      => 5,
        'CUTI_BESAR_3'      => 5,
        'CUTI_MELAHIRKAN_1' => 40,
        'CUTI_MELAHIRKAN_2' => 70,
        'CUTI_MELAHIRKAN_3' => 80,
        'SKP_KURANG'        => 16,
        'SKP_TIDAK_ADA'     => 32,
    ];

    /** Faktor presensi rekap legacy (`* 0.2`), dalam persen. */
    public const FAKTOR_PRESENSI_PERSEN = 20;

    /** Kunci tarif yang dikalikan faktor presensi. */
    public const KUNCI_BERFAKTOR = ['TL1/PSW1', 'TL2/PSW2', 'TL3/PSW3', 'TA', 'TK', 'CUTI_SAKIT'];

    /** @var array<string, int> Tarif dalam basis poin (1% = 100). */
    public array $tarif;

    public string $jamMasukNormal;
    public string $jamPulangNormal;
    public string $jamPulangJumatNormal;
    public string $jamMasukPuasa;
    public string $jamPulangPuasa;
    public string $jamPulangJumatPuasa;

    /**
     * @param array<string, int|string> $tarifPersen
     */
    public function __construct(
        array $tarifPersen,
        string $jamMasukNormal = '07:30',
        string $jamPulangNormal = '16:00',
        string $jamPulangJumatNormal = '16:30',
        string $jamMasukPuasa = '08:00',
        string $jamPulangPuasa = '15:00',
        string $jamPulangJumatPuasa = '15:30',
        public ?string $puasaMulai = null,
        public ?string $puasaSelesai = null,
    ) {
        foreach (self::KUNCI_WAJIB as $kunci) {
            if (! array_key_exists($kunci, $tarifPersen)) {
                throw new InvalidArgumentException("Tarif {$kunci} wajib ada.");
            }
        }

        $tarif = [];

        foreach ($tarifPersen + self::TARIF_BAWAAN as $kunci => $nilai) {
            $tarif[$kunci] = self::persenKeBasisPoin($nilai, $kunci);
        }
        $this->tarif = $tarif;

        foreach (self::KUNCI_BERFAKTOR as $kunci) {
            if ($tarif[$kunci] * self::FAKTOR_PRESENSI_PERSEN % 100 !== 0) {
                throw new InvalidArgumentException("Tarif {$kunci} dikali faktor presensi 0,2 tidak bulat dalam basis poin.");
            }
        }

        $this->jamMasukNormal       = JamKerja::normalisasiJam($jamMasukNormal);
        $this->jamPulangNormal      = JamKerja::normalisasiJam($jamPulangNormal);
        $this->jamPulangJumatNormal = JamKerja::normalisasiJam($jamPulangJumatNormal);
        $this->jamMasukPuasa        = JamKerja::normalisasiJam($jamMasukPuasa);
        $this->jamPulangPuasa       = JamKerja::normalisasiJam($jamPulangPuasa);
        $this->jamPulangJumatPuasa  = JamKerja::normalisasiJam($jamPulangJumatPuasa);

        if (($puasaMulai === null) !== ($puasaSelesai === null)) {
            throw new InvalidArgumentException('Rentang puasa harus memiliki tanggal mulai dan selesai.');
        }
        if ($puasaMulai !== null && $puasaSelesai !== null) {
            TanggalBisnis::wajibValid($puasaMulai, 'awal puasa');
            TanggalBisnis::wajibValid($puasaSelesai, 'akhir puasa');
            if ($puasaMulai > $puasaSelesai) {
                throw new InvalidArgumentException('Awal puasa tidak boleh setelah akhir puasa.');
            }
        }
    }

    /** Tarif dalam basis poin. */
    public function tarif(string $kunci): int
    {
        return $this->tarif[$kunci] ?? throw new InvalidArgumentException("Tarif {$kunci} tidak tersedia.");
    }

    /** Potongan harian dalam basis poin: tarif × faktor presensi 0,2 (sudah dipastikan bulat saat konstruksi). */
    public function potonganHarian(string $kunci): int
    {
        if (! in_array($kunci, self::KUNCI_BERFAKTOR, true)) {
            throw new InvalidArgumentException("Tarif {$kunci} bukan potongan harian.");
        }

        return intdiv($this->tarif($kunci) * self::FAKTOR_PRESENSI_PERSEN, 100);
    }

    /**
     * Konversi persen (int, atau string desimal dengan maksimal dua angka di belakang titik/koma) ke basis poin tanpa
     * float. Float ditolak supaya tidak ada pembulatan tersembunyi.
     */
    public static function persenKeBasisPoin(mixed $persen, string $kunci = 'tarif'): int
    {
        if (is_int($persen)) {
            if ($persen < 0) {
                throw new InvalidArgumentException("Tarif {$kunci} tidak boleh negatif.");
            }

            return $persen * 100;
        }
        if (! is_string($persen) || preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', trim($persen), $cocok) !== 1) {
            throw new InvalidArgumentException("Tarif {$kunci} wajib persen non-negatif dengan maksimal dua desimal.");
        }

        return (int) $cocok[1] * 100 + (int) str_pad($cocok[2] ?? '', 2, '0');
    }
}
