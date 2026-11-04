<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use App\Libraries\Siasn\Arsip\UnggahArsip;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\SiasnTidakSiap;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * `php spark siasn:unggah-arsip [--langkah=semua|antre|unggah] [--limit=N] [--dry-run]` (H-03, F6-14; MAKE-067 M3) —
 * antre dokumen riwayat jabatan & sertifikasi yang sudah terkirim ke SIASN, lalu unggah N baris antrean (bawaan 1, legacy
 * "every minute"). Padanan `set_queue_arsip` + `set_queue_arsip_sertifikasi` + `SS/Arsip::upload_siasn` [K]. Jadwal di
 * runbook (PN-5).
 *
 * Kode keluar: 0 berhasil (termasuk sebagian baris gagal — tercatat di `error_msg`), 1 konfigurasi/akun sistem belum siap,
 * driver bukan `bkn`, sedang berjalan, atau galat DB/lainnya (kelas & kode saja), 7 masukan salah. Keluaran hanya jumlah.
 */
class SiasnUnggahArsip extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'siasn:unggah-arsip';
    protected $description = 'Antre dan unggah dokumen riwayat ke SIASN BKN (ikut legacy SS/Arsip).';
    protected $usage       = 'siasn:unggah-arsip [--langkah=semua|antre|unggah] [--limit=N] [--dry-run]';
    protected $options     = [
        '--langkah' => 'semua (bawaan: antre lalu unggah), antre, atau unggah.',
        '--limit'   => 'Jumlah baris antrean yang diunggah (bawaan 1).',
        '--dry-run' => 'Hanya hitung, tanpa mengunggah dan tanpa menulis.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['langkah' => true, 'limit' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $langkah = $opsi['langkah'] ?? 'semua';
        $limit   = $opsi['limit'] ?? (string) UnggahArsip::LIMIT_BAWAAN;

        if ($argumen !== [] || ! in_array($langkah, ['semua', 'antre', 'unggah'], true) || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            CLI::error('Isi --langkah semua|antre|unggah dan --limit 1-100. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $h = UnggahArsip::dariLayanan()->jalankan($langkah, (int) $limit, isset($opsi['dry-run']));
        } catch (KonfigurasiIntegrasiTidakLengkap|AkunSistemTidakSiap|SiasnTidakSiap|SiasnTarikSibuk $e) {
            CLI::error('Unggah arsip SIASN GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('Unggah arsip SIASN GAGAL: ' . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }

        $awal = $h['dry_run'] ? '[dry-run] ' : '';

        if ($h['antre'] !== null) {
            CLI::write(sprintf('%sAntre arsip SIASN: baru %d, sudah ada %d, path tidak sah %d.', $awal, $h['antre']['baru'], $h['antre']['sudah_ada'], $h['antre']['path_tidak_sah']), 'green');
        }

        if ($h['unggah'] !== null) {
            $u = $h['unggah'];
            CLI::write(sprintf(
                '%sUnggah arsip SIASN: %s %d; sukses %d, gagal %d, berkas hilang %d, tidak sah %d, status tidak diketahui %d.',
                $awal,
                $h['dry_run'] ? 'akan diproses' : 'diproses',
                $u['diproses'],
                $u['sukses'],
                $u['gagal'],
                $u['berkas_hilang'],
                $u['tidak_sah'],
                $u['status_tidak_diketahui'],
            ), 'green');
        }

        return EXIT_SUCCESS;
    }
}
