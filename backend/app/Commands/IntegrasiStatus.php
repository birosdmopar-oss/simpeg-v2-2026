<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Integrasi\Log\StatusIntegrasi;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * `php spark integrasi:status [--jam=24]` (PRD Fase 6 F6-24): ringkasan sukses/gagal per layanan dari `integrasi_log`.
 * Hanya agregat; tanpa pesan/rujukan. Kode keluar 0 (informasi), 1 bila ada kegagalan dalam jendela (untuk pemantauan cron).
 */
class IntegrasiStatus extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'integrasi:status';
    protected $description = 'Ringkasan log integrasi per layanan (sukses/gagal, kegagalan terakhir).';
    protected $usage       = 'integrasi:status [--jam=24]';
    protected $options     = ['--jam' => 'Jendela waktu dalam jam (bawaan 24).'];

    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['jam' => true]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $jam = $opsi['jam'] ?? '24';

        if ($argumen !== [] || ! is_string($jam) || ! ctype_digit($jam) || (int) $jam < 1) {
            CLI::error('--jam harus angka >= 1. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $jam   = (int) $jam;
        $baris = (new StatusIntegrasi(db_connect()))->ringkasan($jam);

        if ($baris === []) {
            CLI::write("Tidak ada catatan integrasi dalam {$jam} jam terakhir.");

            return EXIT_SUCCESS;
        }

        CLI::table(array_map(static fn (array $b): array => [
            $b['layanan'],
            $b['arah'] === CatatanIntegrasi::ARAH_MASUK ? 'masuk' : 'keluar',
            (string) $b['sukses'],
            (string) $b['gagal'],
            $b['gagal_terakhir_at'] ?? '-',
            $b['kode_terakhir'] ?? '-',
        ], $baris), ['Layanan', 'Arah', 'Sukses', 'Gagal', 'Gagal terakhir (UTC)', 'Kode']);

        return array_sum(array_column($baris, 'gagal')) > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
