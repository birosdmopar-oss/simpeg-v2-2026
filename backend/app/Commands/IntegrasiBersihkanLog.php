<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\Log\PembersihLogIntegrasi;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Integrations;
use InvalidArgumentException;

/**
 * `php spark integrasi:bersihkan-log [--hari=N] [--dry-run]` (Q6-16): hapus `integrasi_log` lebih tua dari retensi
 * (bawaan `Config\Integrations::$logRetensiHari` = 180). Jadwal runbook: 04:00 WIB setiap Minggu (PRD §6.4).
 */
class IntegrasiBersihkanLog extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'integrasi:bersihkan-log';
    protected $description = 'Hapus log integrasi yang melewati masa retensi.';
    protected $usage       = 'integrasi:bersihkan-log [--hari=180] [--dry-run]';
    protected $options     = [
        '--hari'    => 'Retensi dalam hari (bawaan dari konfigurasi, 180).',
        '--dry-run' => 'Hanya hitung baris yang akan dihapus.',
    ];

    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['hari' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $hari = $opsi['hari'] ?? null;

        if ($argumen !== [] || ($hari !== null && (! is_string($hari) || ! ctype_digit($hari)))) {
            CLI::error('--hari harus angka. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $hari   = $hari === null ? config(Integrations::class)->logRetensiHari : (int) $hari;
        $dryRun = isset($opsi['dry-run']);

        if ($hari < 1) {
            CLI::error('--hari minimal 1. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $hasil = (new PembersihLogIntegrasi(db_connect()))->bersihkan($hari, $dryRun);
        CLI::write(($dryRun ? '[dry-run] Akan dihapus: ' : 'Dihapus: ') . $hasil['jumlah'] . " baris (created_at < {$hasil['batas']} UTC, retensi {$hari} hari).");

        return EXIT_SUCCESS;
    }
}
