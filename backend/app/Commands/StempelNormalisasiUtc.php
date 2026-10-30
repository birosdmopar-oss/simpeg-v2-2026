<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Waktu\NormalisasiStempel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * Audit dan normalisasi stempel audit yang terisi zona sesi DB sebelum CR-086 (kontrak: UTC diisi aplikasi):
 *
 *   php spark stempel:normalisasi-utc --audit
 *   php spark stempel:normalisasi-utc <tabel|semua> --dari="Y-m-d H:i:s" --sampai="Y-m-d H:i:s" [--offset=7] [--eksekusi]
 *
 * Bawaan **dry-run** (hanya menghitung); baris baru digeser bila `--eksekusi` diberikan. Jendela `--dari/--sampai` wajib
 * (nilai `created_at` tersimpan) karena baris zona-sesi tidak dapat dibedakan otomatis dari baris UTC; lihat
 * {@see NormalisasiStempel} dan `Libraries/Waktu/README.md`. Jalankan hanya setelah disetujui DBV/Horii dan di DB yang benar.
 */
class StempelNormalisasiUtc extends BaseCommand
{
    protected $group       = 'Waktu';
    protected $name        = 'stempel:normalisasi-utc';
    protected $description = 'Audit dan normalisasi created_at/updated_at yang terisi zona sesi DB (CR-086); bawaan dry-run.';
    protected $usage       = 'stempel:normalisasi-utc [--audit] | <tabel|semua> --dari=<Y-m-d H:i:s> --sampai=<Y-m-d H:i:s> [--offset=7] [--eksekusi]';
    protected $arguments   = ['tabel' => 'Nama tabel di daftar normalisasi, atau "semua"'];
    protected $options     = [
        '--audit'    => 'Hanya cetak ringkasan per tabel (tanpa data pribadi)',
        '--dari'     => 'Batas bawah created_at tersimpan (inklusif)',
        '--sampai'   => 'Batas atas created_at tersimpan (inklusif)',
        '--offset'   => 'Selisih jam zona sesi terhadap UTC (bawaan 7 = WIB)',
        '--eksekusi' => 'Benar-benar menulis (tanpa ini = dry-run)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['audit' => false, 'dari' => true, 'sampai' => true, 'offset' => true, 'eksekusi' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $service = new NormalisasiStempel(db_connect());

        if (isset($opsi['audit'])) {
            foreach ($service->audit(gmdate('Y-m-d H:i:s')) as $tabel => $r) {
                CLI::write(sprintf('%-20s total=%d terkecil=%s terbesar=%s masa_depan=%d', $tabel, $r['total'], $r['terkecil'] ?? '-', $r['terbesar'] ?? '-', $r['masa_depan']));
            }

            return EXIT_SUCCESS;
        }

        $dari   = $opsi['dari'] ?? null;
        $sampai = $opsi['sampai'] ?? null;
        $tabel  = $argumen[0] ?? null;

        if (! is_string($dari) || ! is_string($sampai) || $tabel === null || count($argumen) > 1) {
            CLI::error('Tabel, --dari, dan --sampai wajib diisi. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $offset = $opsi['offset'] ?? '7';
        $dryRun = ! isset($opsi['eksekusi']);
        $daftar = $tabel === 'semua' ? array_keys(NormalisasiStempel::TABEL) : [$tabel];

        try {
            foreach ($daftar as $t) {
                $r = $service->normalisasi($t, $dari, $sampai, is_string($offset) && ctype_digit($offset) ? (int) $offset : 0, $dryRun);
                CLI::write(sprintf(
                    '%-20s terkena=%d updated_at_ikut=%d updated_at_tak_pasti=%d%s',
                    $r['tabel'],
                    $r['terkena'],
                    $r['updated_at_ikut'],
                    $r['updated_at_tak_pasti'],
                    $r['dry_run'] ? ' (dry-run, tidak menulis)' : ' (ditulis)',
                ), $r['dry_run'] ? 'yellow' : 'green');
            }
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_USER_INPUT;
        }

        return EXIT_SUCCESS;
    }
}
