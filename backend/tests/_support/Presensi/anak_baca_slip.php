<?php

declare(strict_types=1);

/**
 * Proses anak untuk `PembacaExcelSlipBomArsipTest` (CR-188): membaca satu berkas dengan `PembacaExcelSlip` di proses
 * PHP tersendiri, supaya `memory_limit` bisa diatur dari baris perintah dan galat fatal (memori habis) tidak mematikan
 * PHPUnit. Tanpa framework dan tanpa database; keluaran = satu baris JSON `{status, pesan, puncak}`.
 *
 * Pemakaian: php -d memory_limit=256M anak_baca_slip.php <berkas>
 */

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;

defined('WRITEPATH') || define('WRITEPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR);

require __DIR__ . '/../../../vendor/autoload.php';

if (! function_exists('log_message')) {
    /**
     * @param array<string, mixed> $konteks
     */
    function log_message(string $tingkat, string $pesan, array $konteks = []): void
    {
    }
}

$path = (string) ($argv[1] ?? '');

try {
    $hasil  = (new PembacaExcelSlip(10000))->baca($path);
    $keluar = ['status' => $hasil->valid ? 'DITERIMA' : 'TIDAK_VALID', 'pesan' => ''];
} catch (BerkasImporDitolak $e) {
    $keluar = ['status' => 'DITOLAK', 'pesan' => $e->getMessage()];
}

echo json_encode($keluar + ['puncak' => memory_get_peak_usage()], JSON_UNESCAPED_UNICODE), "\n";
