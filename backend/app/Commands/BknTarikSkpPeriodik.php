<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Bkn\Ekinerja\PemilihPeriode;
use App\Libraries\Bkn\Ekinerja\TarikEkinerja;
use App\Libraries\Bkn\GalatTokenBkn;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * `php spark bkn:tarik-skp-periodik [--tahun=YYYY --bulan=M] [--dry-run]` (H-10, F6-15) — tarik penilaian SKP periodik
 * satu periode e-Kinerja [K `Ekin_bkn::cron_skp_periodik`]: tanpa opsi = periode terpilih (tanggal WIB > 19 → bulan
 * berikutnya), periode `SKPManual*` dilewati; INSERT kunci baru, UPDATE bila `hasil_akhir` berubah. `--tahun` dan
 * `--bulan` wajib berpasangan (tarik ulang periode tertentu). `--sebelumnya` = tarik juga satu periode sebelum periode
 * terpilih/diminta. PRD §6.4 "periode berjalan + sebelumnya" = JADWAL (runbook memakai `--sebelumnya`); command bawaan =
 * legacy (satu periode, `EB:205`). Jadwal runbook 02:00 WIB.
 * Kode keluar: 0 berhasil / dilewati manual, 1 gagal / periode belum ada, 3 masukan salah.
 */
class BknTarikSkpPeriodik extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'bkn:tarik-skp-periodik';
    protected $description = 'Tarik penilaian SKP periodik e-Kinerja BKN untuk satu periode.';
    protected $usage       = 'bkn:tarik-skp-periodik [--tahun=YYYY --bulan=M] [--sebelumnya] [--dry-run]';
    protected $options     = [
        '--tahun'      => 'Tahun periode (berpasangan dengan --bulan).',
        '--bulan'      => 'Bulan periode 1-12 (berpasangan dengan --tahun).',
        '--sebelumnya' => 'Tarik juga satu periode sebelum periode terpilih/diminta (jadwal PRD §6.4).',
        '--dry-run'    => 'Hanya hitung, tanpa menulis.',
    ];

    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['tahun' => true, 'bulan' => true, 'sebelumnya' => false, 'dry-run' => false]);
            [$tahun, $bulan]                         = $this->tahunBulan($argumen, $opsi);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $dryRun = isset($opsi['dry-run']);

        try {
            $tarik = TarikEkinerja::dariLayanan();
            $h     = $tarik->skpPeriodik($tahun, $bulan, null, $dryRun);
            $kode  = $this->laporkan($h, $dryRun);

            if (isset($opsi['sebelumnya'])) {
                $lalu = PemilihPeriode::sebelum($h['tahun'], (int) $h['bulan']);
                $kode = max($kode, $this->laporkan($tarik->skpPeriodik($lalu['tahun'], (int) $lalu['bulan'], null, $dryRun), $dryRun));
            }
        } catch (GalatTokenBkn|GalatTransport|KonfigurasiIntegrasiTidakLengkap $e) {
            CLI::error('Tarik SKP periodik GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return $kode;
    }

    /**
     * @param array{status: string, http: int|null, periode_id: string|null, tahun: int, bulan: string, baru: int, diperbarui: int, tetap: int, tidak_terpasang: int, tidak_dikenal: int, ditolak: int} $h
     */
    private function laporkan(array $h, bool $dryRun): int
    {
        $label   = ($dryRun ? '[dry-run] ' : '') . "Periode {$h['tahun']}-{$h['bulan']}";
        $periode = $h['periode_id'] ?? '-';

        return match ($h['status']) {
            TarikEkinerja::STATUS_OK            => $this->tulis("{$label} ({$periode}): baru {$h['baru']}, diperbarui {$h['diperbarui']}, tetap {$h['tetap']}, ditolak {$h['ditolak']}, tidak terpasang {$h['tidak_terpasang']}, tidak dikenal {$h['tidak_dikenal']}."),
            TarikEkinerja::STATUS_MANUAL        => $this->tulis("{$label} ({$periode}) periode manual — dilewati."),
            TarikEkinerja::STATUS_TANPA_PERIODE => $this->galat("{$label}: periode belum ada di bkn_periode_ekinper (jalankan bkn:tarik-periode)."),
            default                             => $this->galat("{$label} ({$periode}) GAGAL: HTTP " . ($h['http'] ?? '-') . '.'),
        };
    }

    /**
     * @param list<string>               $argumen
     * @param array<string, string|true> $opsi
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function tahunBulan(array $argumen, array $opsi): array
    {
        if ($argumen !== []) {
            throw new InvalidArgumentException('Command ini tidak menerima argumen.');
        }

        $tahun = $opsi['tahun'] ?? null;
        $bulan = $opsi['bulan'] ?? null;

        if ($tahun === null && $bulan === null) {
            return [null, null];
        }

        if (! is_string($tahun) || ! is_string($bulan) || preg_match('/^\d{4}$/', $tahun) !== 1 || ! ctype_digit($bulan) || (int) $bulan < 1 || (int) $bulan > 12) {
            throw new InvalidArgumentException('--tahun (YYYY) dan --bulan (1-12) wajib diisi berpasangan.');
        }

        return [(int) $tahun, (int) $bulan];
    }

    private function tulis(string $pesan): int
    {
        CLI::write($pesan);

        return EXIT_SUCCESS;
    }

    private function galat(string $pesan): int
    {
        CLI::error($pesan);

        return EXIT_ERROR;
    }
}
