<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use InvalidArgumentException;

final class JamKerja
{
    /** @return array{masuk: string, pulang: string} */
    public static function untuk(string $tanggal, KonfigurasiTukin $config): array
    {
        TanggalBisnis::wajibValid($tanggal, 'tanggal kerja');
        $puasa = $config->puasaMulai !== null && $tanggal >= $config->puasaMulai && $tanggal <= $config->puasaSelesai;
        $jumat = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal)->format('N') === 5;
        return [
            'masuk' => $puasa ? $config->jamMasukPuasa : $config->jamMasukNormal,
            'pulang' => $puasa ? ($jumat ? $config->jamPulangJumatPuasa : $config->jamPulangPuasa) : ($jumat ? $config->jamPulangJumatNormal : $config->jamPulangNormal),
        ];
    }

    public static function periksaJam(string $jam): void
    {
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $jam) !== 1) {
            throw new InvalidArgumentException("Jam tidak valid: {$jam}");
        }
    }

    public static function selisihMenit(string $standar, string $aktual): int
    {
        self::periksaJam($standar);
        self::periksaJam($aktual);
        [$jam, $menit] = array_map('intval', explode(':', $standar));
        [$jamAktual, $menitAktual] = array_map('intval', explode(':', $aktual));
        return ($jamAktual * 60 + $menitAktual) - ($jam * 60 + $menit);
    }
}
