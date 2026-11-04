<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Lkh\LkhAutoApproval as PenyetujuanOtomatisLkh;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * `php spark lkh:auto-approval [--bulan=YYYY-MM] [--dry-run]` (H-12, F6-25): LKH bulan lalu yang masih Menunggu
 * disetujui otomatis (`auto_approval = 1`). Bawaan `--bulan` = bulan lalu dari tanggal bisnis hari ini.
 * Jadwal: tanggal 5 setiap bulan (ikut legacy `queue_aa_lkh`, ADR-037).
 */
class LkhAutoApproval extends BaseCommand
{
    protected $group       = 'LKH';
    protected $name        = 'lkh:auto-approval';
    protected $description = 'Setujui otomatis LKH bulan lalu yang masih menunggu (ikut legacy, tanggal 5).';
    protected $usage       = 'lkh:auto-approval [--bulan=YYYY-MM] [--dry-run]';
    protected $options     = [
        '--bulan'   => 'Bulan laporan yang diproses, YYYY-MM (bawaan: bulan lalu).',
        '--dry-run' => 'Hanya hitung LKH yang akan disetujui.',
    ];

    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['bulan' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $bulan = $opsi['bulan'] ?? null;

        if ($argumen !== [] || ($bulan !== null && ! is_string($bulan))) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $bulan ??= (new DateTimeImmutable(TanggalBisnis::hariIni() . ' first day of last month'))->format('Y-m');

        try {
            $hasil = (new PenyetujuanOtomatisLkh(KoneksiCli::buka()))->jalankan($bulan, isset($opsi['dry-run']));
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        } catch (RuntimeException $e) {
            // Mencakup akun sistem integrasi belum siap dan lock yang dipegang proses lain; pesan tanpa data pribadi.
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(
            ($hasil['dry_run'] ? '[dry-run] ' : '') . "Auto-approval LKH bulan {$hasil['bulan']}: kandidat {$hasil['kandidat']}, disetujui {$hasil['disetujui']}.",
        );

        return EXIT_SUCCESS;
    }
}
