<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Layanan\StatusHarian\StatusHarianService;
use App\Libraries\Layanan\StatusHarian\StatusHarianSibuk;
use App\Libraries\Layanan\Studi\RentangTanggal;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * Status TB/CLTN harian (H-11, MAKE-062, PRD Fase 6 §7.9, F6-20, Q6-24..Q6-27):
 *
 *   php spark layanan:status-harian [--dry-run] [--tanggal=YYYY-MM-DD]
 *
 * Jadwal runbook: 00:30 WIB setiap hari (PRD §6.4, ADR-037). Run nyata selalu hari ini (WIB); `--tanggal` masa depan ditolak,
 * `--tanggal` lampau hanya bersama `--dry-run` (dihitung terhadap status pegawai SAAT INI). Run pertama di lingkungan baru
 * WAJIB `--dry-run` dan jumlahnya direview Biro SDM. Idempoten; kode keluar ≠ 0 bila ada pegawai gagal diproses. Keluaran
 * hanya jumlah, tanpa NIP/nama.
 */
class LayananStatusHarian extends BaseCommand
{
    protected $group       = 'Layanan';
    protected $name        = 'layanan:status-harian';
    protected $description = 'Menyelaraskan pegawai.id_jenis_status (1 aktif, 2 TB, 3 CLTN) dengan riwayat TB/CLTN pada hari ini; idempoten.';
    protected $usage       = 'layanan:status-harian [--dry-run] [--tanggal=YYYY-MM-DD]';
    protected $options     = [
        '--dry-run' => 'Hanya melaporkan perubahan yang akan dilakukan, tanpa menulis',
        '--tanggal' => 'Tanggal proses (hanya bersama --dry-run bila lampau; masa depan ditolak). Bawaan: hari ini WIB',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['dry-run' => false, 'tanggal' => true]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $hariIni = TanggalBisnis::hariIni();
        $dryRun  = isset($opsi['dry-run']);
        $tanggal = $opsi['tanggal'] ?? $hariIni;

        if ($argumen !== [] || ! is_string($tanggal)) {
            CLI::error('Argumen berlebih atau --tanggal tanpa nilai. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            RentangTanggal::tanggal($tanggal);
        } catch (InvalidArgumentException) {
            CLI::error('--tanggal harus berformat YYYY-MM-DD yang sah. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        if ($tanggal > $hariIni) {
            CLI::error('--tanggal masa depan ditolak.');

            return EXIT_USER_INPUT;
        }

        if ($tanggal < $hariIni && ! $dryRun) {
            CLI::error('--tanggal lampau hanya boleh bersama --dry-run; run nyata selalu hari ini.');

            return EXIT_USER_INPUT;
        }

        try {
            $db     = KoneksiCli::buka();
            $pelaku = $dryRun ? null : (new AkunSistemIntegrasi($db))->id();
            $hasil  = (new StatusHarianService($db))->jalankan($tanggal, $dryRun, $pelaku);
        } catch (AkunSistemTidakSiap|StatusHarianSibuk $e) {
            // Pesan kelas-kelas ini disusun aplikasi tanpa NIP/nilai rahasia.
            log_message('error', 'layanan:status-harian gagal: ' . (new ReflectionClass($e))->getShortName() . '.');
            CLI::error('Status harian GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            // Pesan galat DB bisa memuat NIP → hanya kelas & kode.
            log_message('error', 'layanan:status-harian gagal: ' . (new ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');
            CLI::error('Status harian GAGAL: ' . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }

        $i = $hasil['info'];

        $ringkas = sprintf(
            '%sStatus harian %s: diperiksa %d, %s %d (TB aktif %d, CLTN aktif %d, pulih dari TB %d, pulih dari CLTN %d), gagal %d. '
            . 'INFO: bentrok TB+CLTN %d, celah TB (hari ini di antara dua segmen) %d, status 2/3 tanpa riwayat pendukung %d, segmen riwayat tidak sah %d, dilewati pegawai tidak aktif %d, dilewati status lain %d, berubah saat proses %d.',
            $dryRun ? '[dry-run] ' : '',
            $hasil['tanggal'],
            $hasil['diperiksa'],
            $dryRun ? 'akan berubah' : 'berubah',
            $hasil['berubah'],
            $hasil['tb_aktif'],
            $hasil['cltn_aktif'],
            $hasil['pulih_tb'],
            $hasil['pulih_cltn'],
            $hasil['gagal'],
            $i['bentrok'],
            $i['celah_tb'],
            $i['tanpa_riwayat'],
            $i['segmen_tidak_sah'],
            $i['dilewati_nonaktif'],
            $i['dilewati_status_lain'],
            $i['berubah_saat_proses'],
        );

        CLI::write($ringkas);

        if ($dryRun && $tanggal < $hariIni) {
            CLI::write('Catatan: tanggal lampau dihitung terhadap status pegawai SAAT INI, bukan status historis.', 'yellow');
        }

        log_message('info', 'layanan:status-harian ' . trim(str_replace('[dry-run] ', 'dry-run: ', $ringkas)));

        if ($hasil['gagal'] > 0) {
            CLI::error('Ada pegawai yang gagal diproses; jalankan ulang (idempoten).');

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
