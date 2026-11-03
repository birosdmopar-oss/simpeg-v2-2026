<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

use App\Exceptions\ValidationException;
use App\Jobs\BknTarikJob;
use Closure;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use RuntimeException;

/**
 * Tombol admin "tarik ulang e-Kinerja" (PRD Fase 6 §6.2, H-10): validasi masukan lalu antre job `bkn-tarik` — tidak
 * memanggil BKN secara sinkron di request HTTP (padanan pemicu URL cron legacy). Masukan `{jenis: periode|skp_periodik,
 * tahun?, bulan?}`; untuk `skp_periodik`, `tahun`+`bulan` berpasangan (kosong = periode terpilih seperti cron).
 */
final class AntreTarikEkinerja
{
    public const JENIS = ['periode', 'skp_periodik'];

    /** @var Closure(): QueueInterface */
    private Closure $antrean;

    /**
     * @param (Closure(): QueueInterface)|null $antrean override antrean (test)
     */
    public function __construct(?Closure $antrean = null)
    {
        $this->antrean = $antrean ?? static fn (): QueueInterface => service('queue');
    }

    /**
     * @param array<string, mixed> $masukan
     *
     * @return array{jenis: string, tahun: int|null, bulan: int|null}
     */
    public function antre(array $masukan): array
    {
        $data = $this->validasi($masukan);

        if (! ($this->antrean)()->push(BknTarikJob::ANTREAN, BknTarikJob::NAMA, $data)->getStatus()) {
            throw new RuntimeException('Antrean menolak job tarik e-Kinerja.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $masukan
     *
     * @return array{jenis: string, tahun: int|null, bulan: int|null}
     */
    public function validasi(array $masukan): array
    {
        $jenis = $masukan['jenis'] ?? null;

        if (! is_string($jenis) || ! in_array($jenis, self::JENIS, true)) {
            throw ValidationException::forField('jenis', 'Jenis wajib periode atau skp_periodik.');
        }

        $tahun = $masukan['tahun'] ?? null;
        $bulan = $masukan['bulan'] ?? null;

        if ($jenis === 'periode' || ($this->kosong($tahun) && $this->kosong($bulan))) {
            return ['jenis' => $jenis, 'tahun' => null, 'bulan' => null];
        }

        if (! $this->bilangan($tahun) || (int) $tahun < 2000 || (int) $tahun > 2100) {
            throw ValidationException::forField('tahun', 'Tahun wajib 4 digit bila bulan diisi.');
        }

        if (! $this->bilangan($bulan) || (int) $bulan < 1 || (int) $bulan > 12) {
            throw ValidationException::forField('bulan', 'Bulan wajib 1-12 bila tahun diisi.');
        }

        return ['jenis' => $jenis, 'tahun' => (int) $tahun, 'bulan' => (int) $bulan];
    }

    private function kosong(mixed $nilai): bool
    {
        return $nilai === null || $nilai === '';
    }

    private function bilangan(mixed $nilai): bool
    {
        return is_int($nilai) || (is_string($nilai) && ctype_digit($nilai));
    }
}
