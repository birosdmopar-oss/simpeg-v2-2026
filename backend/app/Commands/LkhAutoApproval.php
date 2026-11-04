<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Lkh\AutoApproval\AutoApprovalService;
use App\Libraries\Kepegawaian\Lkh\AutoApproval\AutoApprovalSibuk;
use App\Libraries\Kepegawaian\Lkh\AutoApproval\PenentuAutoApproval;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * Auto-approval LKH bulan lalu (H-12, MAKE-068, PRD Fase 6 §7.10, F6-25, CR-093):
 *
 *   php spark lkh:auto-approval [--bulan=YYYY-MM] [--dry-run]
 *
 * Jadwal runbook: tanggal 5 pukul 00:30 WIB, ulang 01:00/01:30/02:00 (PRD §6.4, ADR-037); tiap jalan aman diulang.
 * Bawaan `--bulan` = bulan lalu dari tanggal bisnis WIB hari ini. Run nyata hanya untuk bulan lalu; `--dry-run` boleh bulan
 * lampau lain (dihitung terhadap data saat ini); bulan berjalan dan masa depan ditolak. Kode keluar ≠ 0 bila ada LKH gagal
 * diproses. Keluaran hanya jumlah, tanpa NIP/nama.
 */
class LkhAutoApproval extends BaseCommand
{
    protected $group       = 'LKH';
    protected $name        = 'lkh:auto-approval';
    protected $description = 'Menyetujui otomatis LKH bulan lalu yang masih menunggu (status 0) dengan auto_approval = 1; idempoten.';
    protected $usage       = 'lkh:auto-approval [--bulan=YYYY-MM] [--dry-run]';
    protected $options     = [
        '--bulan'   => 'Bulan laporan, YYYY-MM. Bawaan: bulan lalu. Run nyata hanya bulan lalu.',
        '--dry-run' => 'Hanya menghitung LKH yang akan disetujui, tanpa menulis dan tanpa akun sistem.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['bulan' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $hariIni = TanggalBisnis::hariIni();
        $dryRun  = isset($opsi['dry-run']);
        $bulan   = $opsi['bulan'] ?? PenentuAutoApproval::bulanLalu($hariIni);

        if ($argumen !== [] || ! is_string($bulan)) {
            CLI::error('Argumen berlebih atau --bulan tanpa nilai. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $tolak = PenentuAutoApproval::tolakBulan($bulan, $hariIni, $dryRun);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        if ($tolak !== null) {
            CLI::error($tolak . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $db     = KoneksiCli::buka();
            $pelaku = $dryRun ? null : (new AkunSistemIntegrasi($db))->id();
            $hasil  = (new AutoApprovalService($db))->jalankan($bulan, $dryRun, $pelaku);
        } catch (AkunSistemTidakSiap|AutoApprovalSibuk $e) {
            // Pesan kelas-kelas ini disusun aplikasi tanpa NIP/nilai rahasia.
            CLI::error('Auto-approval LKH GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            // Pesan galat DB bisa memuat NIP → hanya kelas & kode.
            CLI::error('Auto-approval LKH GAGAL: ' . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }

        $ringkas = sprintf(
            '%sAuto-approval LKH %s: kandidat %d, %s %d, sudah berubah saat proses %d, gagal %d.',
            $hasil['dry_run'] ? '[dry-run] ' : '',
            $hasil['bulan'],
            $hasil['kandidat'],
            $hasil['dry_run'] ? 'akan disetujui' : 'disetujui',
            $hasil['dry_run'] ? $hasil['kandidat'] : $hasil['disetujui'],
            $hasil['berubah_saat_proses'],
            $hasil['gagal'],
        );

        CLI::write($ringkas);
        log_message('info', 'lkh:auto-approval ' . trim(str_replace('[dry-run] ', 'dry-run: ', $ringkas)));

        if ($hasil['gagal'] > 0) {
            CLI::error('Ada LKH yang gagal diproses; jalankan ulang (idempoten).');

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
