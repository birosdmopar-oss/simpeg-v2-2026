<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Rekap;

use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\JamKerja;
use InvalidArgumentException;

/**
 * Jam kerja standar untuk rekap kehadiran (PRD 5B §7.4.1) — **murni**. Nilai dari `web_config` F5-16 + satu rentang
 * `rentang_puasa` yang beririsan dengan bulan (dimuat service), pengganti jam & tanggal Ramadan yang di-hard-code legacy
 * (`L_presensi.php:614-622`, `:679-684`) [V2]. Aturan pilih jam sama dengan `Tukin\JamKerja::untuk()` (Jumat & puasa).
 */
final readonly class JadwalKerjaRekap
{
    public function __construct(
        public string $masukNormal,
        public string $pulangNormal,
        public string $pulangJumatNormal,
        public string $masukPuasa,
        public string $pulangPuasa,
        public string $pulangJumatPuasa,
        public ?string $puasaMulai = null,
        public ?string $puasaSelesai = null,
    ) {
        foreach ([$masukNormal, $pulangNormal, $pulangJumatNormal, $masukPuasa, $pulangPuasa, $pulangJumatPuasa] as $jam) {
            JamKerja::periksaJam($jam);
        }

        if (($puasaMulai === null) !== ($puasaSelesai === null) || ($puasaMulai !== null && $puasaSelesai !== null && $puasaMulai > $puasaSelesai)) {
            throw new InvalidArgumentException('Rentang puasa tidak sah.');
        }
    }

    /**
     * @return array{masuk: string, pulang: string} `HH:MM:SS`
     */
    public function untuk(string $tanggal): array
    {
        $puasa = $this->puasaMulai !== null && $tanggal >= $this->puasaMulai && $tanggal <= (string) $this->puasaSelesai;
        $jumat = HariKerja::nomorHari($tanggal) === 5;

        return [
            'masuk'  => JamKerja::normalisasiJam($puasa ? $this->masukPuasa : $this->masukNormal),
            'pulang' => JamKerja::normalisasiJam($puasa
                ? ($jumat ? $this->pulangJumatPuasa : $this->pulangPuasa)
                : ($jumat ? $this->pulangJumatNormal : $this->pulangNormal)),
        ];
    }
}
