<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use InvalidArgumentException;

/** Konfigurasi Tukin yang seluruh nilainya diinjeksikan oleh pemanggil. */
final readonly class KonfigurasiTukin
{
    /** @var array<string, float> */
    public array $tarif;

    /** @param array<string, int|float|string> $tarif */
    public function __construct(
        array $tarif,
        public string $jamMasukNormal = '07:30',
        public string $jamPulangNormal = '16:00',
        public string $jamPulangJumatNormal = '16:30',
        public string $jamMasukPuasa = '08:00',
        public string $jamPulangPuasa = '15:00',
        public string $jamPulangJumatPuasa = '15:30',
        public ?string $puasaMulai = null,
        public ?string $puasaSelesai = null,
    ) {
        $wajib = ['TL1/PSW1', 'TL2/PSW2', 'TL3/PSW3', 'TA', 'TK', 'LKH'];
        foreach ($wajib as $key) {
            if (! array_key_exists($key, $tarif) || ! is_numeric($tarif[$key])) {
                throw new InvalidArgumentException("Tarif {$key} wajib numerik.");
            }
        }
        $this->tarif = array_map(static fn (int|float|string $value): float => (float) $value, $tarif);
        foreach ([$jamMasukNormal, $jamPulangNormal, $jamPulangJumatNormal, $jamMasukPuasa, $jamPulangPuasa, $jamPulangJumatPuasa] as $jam) {
            JamKerja::periksaJam($jam);
        }
        if (($puasaMulai === null) !== ($puasaSelesai === null)) {
            throw new InvalidArgumentException('Rentang puasa harus memiliki tanggal mulai dan selesai.');
        }
    }

    public function tarif(string $key): float
    {
        return $this->tarif[$key] ?? throw new InvalidArgumentException("Tarif {$key} tidak tersedia.");
    }
}
