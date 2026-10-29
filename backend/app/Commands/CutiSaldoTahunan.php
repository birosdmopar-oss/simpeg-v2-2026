<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Layanan\Cuti\SaldoTahunanService;
use App\Models\AuditLogModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Cuti as CutiConfig;
use InvalidArgumentException;

/**
 * Membentuk saldo cuti tahunan tahun baru (PRD Fase 4 v1.3 §6.3.6, Q-02):
 *
 *   php spark cuti:saldo-tahunan 2027 --dry-run
 *   php spark cuti:saldo-tahunan 2027 [--nip=<nip>]
 *
 * Idempoten; `--dry-run` hanya mencetak ringkasan. Eksekusi tulis hanya untuk tahun 2027 ke atas. Jadwal berada di
 * runbook deploy, bukan di kode. `--nip` menerima `--nip=<nip>` maupun `--nip <nip>`; opsi tak dikenal, `--nip` tanpa
 * nilai (dulu diam-diam berarti semua pegawai), atau argumen berlebih ditolak dengan EXIT_USER_INPUT (CR-080, `OpsiCli`).
 */
class CutiSaldoTahunan extends BaseCommand
{
    protected $group       = 'Cuti';
    protected $name        = 'cuti:saldo-tahunan';
    protected $description = 'Membentuk saldo cuti tahunan (pegawai_sisa_cuti) untuk satu tahun; idempoten.';
    protected $usage       = 'cuti:saldo-tahunan <tahun> [--dry-run] [--nip=<nip>]';
    protected $arguments   = ['tahun' => 'Tahun saldo yang dibentuk (mis. 2027)'];
    protected $options     = [
        '--dry-run' => 'Hanya mencetak ringkasan, tanpa menulis ke database',
        '--nip'     => 'Batasi pada satu NIP',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['dry-run' => false, 'nip' => true]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $tahun = $argumen[0] ?? null;

        if ($tahun === null || ! ctype_digit($tahun) || count($argumen) > 1) {
            CLI::error('Tahun wajib diisi (angka), mis. 2027. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $nip     = $opsi['nip'] ?? null;
        $service = new SaldoTahunanService(db_connect(), config(CutiConfig::class)->aturan(), new AuditLogModel());

        try {
            $r = $service->jalankan((int) $tahun, isset($opsi['dry-run']), is_string($nip) ? $nip : null);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_USER_INPUT;
        }

        CLI::write(sprintf('Saldo cuti tahun %d%s', $r['tahun'], $r['dry_run'] ? ' (dry-run, tidak menulis)' : ''), 'green');
        CLI::write(sprintf('Populasi: %d, dibuat: %d, dilewati (sudah ada): %d, tanpa baris tahun lalu: %d', $r['populasi'], $r['dibuat'], $r['dilewati'], $r['tanpa_tahun_lalu']));

        foreach (['distribusi_n' => 'N', 'distribusi_n1' => 'N-1', 'distribusi_n2' => 'N-2'] as $kunci => $label) {
            $baris = [];

            foreach ($r[$kunci] as $nilai => $jumlah) {
                $baris[] = "{$nilai}={$jumlah}";
            }

            CLI::write("Distribusi {$label}: " . ($baris === [] ? '-' : implode(', ', $baris)));
        }

        return EXIT_SUCCESS;
    }
}
