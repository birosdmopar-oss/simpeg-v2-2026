<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use App\Libraries\Siasn\Dorong\DorongSiasn;
use App\Libraries\Siasn\Dorong\SiasnTokenGagal;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\SiasnTidakSiap;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * `php spark siasn:dorong --jenis=<jenis> [--limit=N] [--nip=<nip>] [--dry-run]` (H-03, F6-14; PRD Fase 6 §7.3, MAKE-067
 * M2) — satu batch dorong riwayat ke SIASN, padanan `upload_*` legacy [K]. Jabatan didorong ulang bila diubah sesudah
 * terkirim; diklat, sertifikasi, kursus, SKP 2022 hanya sekali (CR-090). Jadwal di runbook (PN-5). Tanpa UI admin.
 *
 * Kode keluar: 0 berhasil (termasuk sebagian baris gagal di SIASN — tercatat di `siasn_error`), 1 konfigurasi/akun sistem
 * belum siap, driver bukan `bkn`, jenis yang sama sedang berjalan, atau galat DB/lainnya (dicetak kelas & kode saja), 7
 * masukan salah. Keluaran hanya jumlah, tanpa NIP/nama.
 */
class SiasnDorong extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'siasn:dorong';
    protected $description = 'Dorong riwayat ke SIASN BKN per jenis untuk satu batch (ikut legacy upload_*).';
    protected $usage       = 'siasn:dorong --jenis=<jabatan|diklat|sertifikasi|kursus|skp22> [--limit=N] [--nip=<nip>] [--dry-run]';
    protected $options     = [
        '--jenis'   => 'Jenis dorong (wajib).',
        '--limit'   => 'Jumlah baris per batch (bawaan 20).',
        '--nip'     => 'Batasi pada satu NIP.',
        '--dry-run' => 'Hanya hitung, tanpa mengirim dan tanpa menulis.',
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
        $limit = $opsi['limit'] ?? (string) DorongSiasn::LIMIT_BAWAAN;
        $nip   = $opsi['nip'] ?? null;

        if ($argumen !== [] || ! is_string($jenis) || ! in_array($jenis, ['jabatan', 'diklat', 'sertifikasi', 'kursus', 'skp22'], true) || ! is_string($limit)
            || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 500 || ($nip !== null && (! is_string($nip) || preg_match('/^[0-9A-Za-z.-]{1,30}$/', $nip) !== 1))) {
            CLI::error('Isi --jenis yang dikenal, --limit 1-500, dan --nip yang sah. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $h = DorongSiasn::dariLayanan()->jalankan($jenis, (int) $limit, is_string($nip) ? $nip : null, isset($opsi['dry-run']));
        } catch (KonfigurasiIntegrasiTidakLengkap|AkunSistemTidakSiap|SiasnTidakSiap|SiasnTarikSibuk|SiasnTokenGagal $e) {
            CLI::error("Dorong SIASN {$jenis} GAGAL: " . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error("Dorong SIASN {$jenis} GAGAL: " . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            '%sDorong SIASN %s: baris %d; %s %d, sukses %d, gagal %d, tidak sah %d, sukses tanpa id %d, status tidak diketahui %d, tidak tersimpan %d.',
            $h['dry_run'] ? '[dry-run] ' : '',
            $h['jenis'],
            $h['baris'],
            $h['dry_run'] ? 'akan dikirim' : 'terkirim',
            $h['terkirim'],
            $h['sukses'],
            $h['gagal'],
            $h['tidak_sah'],
            $h['sukses_tanpa_id'],
            $h['status_tidak_diketahui'],
            $h['tidak_tersimpan'],
        ), $h['tidak_tersimpan'] > 0 ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }
}
