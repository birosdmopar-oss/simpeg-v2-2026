<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Bkn\Ekinerja\TarikEkinerja;
use App\Libraries\Bkn\GalatTokenBkn;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * `php spark bkn:tarik-periode [--dry-run]` (H-10, F6-15) — tarik referensi periode e-Kinerja, INSERT periode baru saja
 * [K `Ekin_bkn::cron_periode_skp`]. Jadwal runbook 01:00 WIB setiap hari (PRD §6.4). Kode keluar 0 berhasil, 1 gagal.
 */
class BknTarikPeriode extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'bkn:tarik-periode';
    protected $description = 'Tarik referensi periode e-Kinerja BKN (insert periode baru).';
    protected $usage       = 'bkn:tarik-periode [--dry-run]';
    protected $options     = ['--dry-run' => 'Hanya hitung, tanpa menulis.'];

    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['dry-run' => false]);

            if ($argumen !== []) {
                throw new InvalidArgumentException('Command ini tidak menerima argumen.');
            }
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $hasil = TarikEkinerja::dariLayanan()->periode(isset($opsi['dry-run']));
        } catch (GalatTokenBkn|GalatTransport|KonfigurasiIntegrasiTidakLengkap|\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            CLI::error('Tarik periode e-Kinerja GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $label = isset($opsi['dry-run']) ? '[dry-run] ' : '';

        if ($hasil['status'] !== TarikEkinerja::STATUS_OK) {
            CLI::error("{$label}Tarik periode e-Kinerja GAGAL: HTTP " . ($hasil['http'] ?? '-') . '.');

            return EXIT_ERROR;
        }

        CLI::write("{$label}Periode e-Kinerja: baru {$hasil['baru']}, sudah ada {$hasil['sudah_ada']}, ditolak {$hasil['ditolak']}.");

        return EXIT_SUCCESS;
    }
}
