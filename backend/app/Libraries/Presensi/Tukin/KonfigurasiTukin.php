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
 */
final readonly class KonfigurasiTukin
{
    /** Kunci `web_config` legacy (`L_presensi.php:3990`); wajib ada. */
    public const KUNCI_WAJIB = ['TL1/PSW1', 'TL2/PSW2', 'TL3/PSW3', 'TA', 'TK', 'LKH'];

    /**
     * Tarif yang di `laporan_tukin` legacy di-hard-code (persen). Boleh ditimpa lewat konfigurasi.
     *
     * - `CUTI_SAKIT`           :4691 (1% per hari mulai hari ke-15 berurutan)
     * - `CUTI_ALASAN_PENTING`  :4355 (sekali bila lama cuti > 14 hari)
     * - `CUTI_BESAR_1..3`      :4449, :4461, :4473
     * - `CUTI_MELAHIRKAN_1..3` :4516, :4528, :4540 (anak ke-4 dan seterusnya)
     * - `TB`                   :4142, :4174 (tugas belajar, sekali per bulan kalender)
     */
    public const TARIF_BAWAAN = [
        'CUTI_SAKIT'          => 1,
        'CUTI_ALASAN_PENTING' => 50,
        'CUTI_BESAR_1'        => 50,
        'CUTI_BESAR_2'        => 75,
        'CUTI_BESAR_3'        => 90,
        'CUTI_MELAHIRKAN_1'   => 60,
        'CUTI_MELAHIRKAN_2'   => 30,
        'CUTI_MELAHIRKAN_3'   => 20,
        'TB'                  => 25,
    ];

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
