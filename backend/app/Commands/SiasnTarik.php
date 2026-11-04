<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\SiasnTidakSiap;
use App\Libraries\Siasn\Tarik\TarikSiasn;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * `php spark siasn:tarik --jenis=<jenis> [--limit=N] [--nip=<nip>] [--dry-run]` (H-03, F6-14 ikut legacy penuh; PRD Fase 6
 * §7.3, MAKE-067) — satu batch tarik SIASN per jenis, padanan `SS/*::get_siasn` [K]. Batch berikutnya otomatis melanjutkan
 * NIP yang belum diproses (flag/stempel `siasn_status_pegawai`); jadwal ada di runbook (crontab legacy, PN-5). `--nip`
 * menarik satu NIP tanpa syarat usang. Tanpa UI admin (legacy tidak punya).
 *
 * Kode keluar: 0 berhasil (termasuk sebagian NIP gagal di sisi SIASN — dicatat, AC-03.1), 1 konfigurasi/akun sistem belum
 * siap, driver SIASN bukan `bkn`, jenis yang sama sedang berjalan, atau galat DB/lainnya (`EXIT_ERROR`; galat DB hanya
 * dicetak kelas & kodenya), 7 masukan salah (`EXIT_USER_INPUT`). Keluaran hanya jumlah, tanpa NIP/nama.
 */
class SiasnTarik extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'siasn:tarik';
    protected $description = 'Tarik data SIASN BKN per jenis untuk satu batch NIP (ikut legacy SS/*).';
    protected $usage       = 'siasn:tarik --jenis=<data-utama|golongan|pendidikan|pmk|skp|ak> [--limit=N] [--nip=<nip>] [--dry-run]';
    protected $options     = [
        '--jenis'   => 'Jenis tarik (wajib).',
        '--limit'   => 'Jumlah NIP per batch (bawaan 20).',
        '--nip'     => 'Tarik satu NIP saja (tanpa syarat usang).',
        '--dry-run' => 'Hanya hitung rencana, tanpa menulis apa pun.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['jenis' => true, 'limit' => true, 'nip' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $jenis = $opsi['jenis'] ?? null;
        $limit = $opsi['limit'] ?? (string) TarikSiasn::LIMIT_BAWAAN;
        $nip   = $opsi['nip'] ?? null;

        if ($argumen !== [] || ! is_string($jenis) || ! isset(TarikSiasn::semuaJenis()[$jenis]) || ! is_string($limit) || ! ctype_digit($limit)
            || (int) $limit < 1 || (int) $limit > 500 || ($nip !== null && (! is_string($nip) || preg_match('/^[0-9A-Za-z.-]{1,30}$/', $nip) !== 1))) {
            CLI::error('Isi --jenis yang dikenal, --limit 1-500, dan --nip yang sah. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $h = TarikSiasn::dariLayanan()->jalankan($jenis, (int) $limit, is_string($nip) ? $nip : null, isset($opsi['dry-run']));
        } catch (KonfigurasiIntegrasiTidakLengkap|AkunSistemTidakSiap|SiasnTidakSiap|SiasnTarikSibuk $e) {
            // Pesan kelas-kelas ini disusun aplikasi tanpa nilai rahasia/NIP.
            CLI::error("Tarik SIASN {$jenis} GAGAL: " . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            // Review B6 M1 P-6: pesan galat DB/lainnya bisa memuat NIP (mis. "Duplicate entry") → hanya kelas & kode ke log cron.
            CLI::error("Tarik SIASN {$jenis} GAGAL: " . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }

        $j = $h['jumlah'];
        CLI::write(sprintf(
            '%sTarik SIASN %s: NIP %d (sukses %d, gagal %d); sisip %d, ubah %d, pasang %d, lepas %d, hapus %d, ganda %d, ditolak %d.',
            $h['dry_run'] ? '[dry-run] ' : '',
            $h['jenis'],
            $h['nip'],
            $h['sukses'],
            $h['gagal'],
            $j['sisip'],
            $j['ubah'],
            $j['pasang'],
            $j['lepas'],
            $j['hapus'],
            $j['ganda'],
            array_sum($h['ditolak']),
        ), 'green');

        foreach ($h['ditolak'] as $alasan => $n) {
            CLI::write("  ditolak {$alasan}: {$n}");
        }

        foreach (array_filter($h['info']) as $info => $n) {
            CLI::write("  info {$info}: {$n}");
        }

        return EXIT_SUCCESS;
    }
}
