<?php

declare(strict_types=1);

/**
 * Worker CLI khusus test (bukan kode aplikasi). Menjalankan `ImporQna::impor` untuk satu berkas di proses PHP terpisah,
 * supaya berkas yang membuat pemuat PhpSpreadsheet kehabisan memori atau berputar tanpa akhir (CR-182) gagal di proses
 * anak (bukan memutus PHPUnit), lalu mencetak hasilnya sebagai satu baris JSON di akhir output. Dipakai
 * Tests\Database\HaloSimpeg\Chatbot\ImporQnaPemindaiTest.
 *
 * Pemakaian: php -d memory_limit=… tests/_support/Scripts/impor_qna_worker.php <path-berkas>
 *
 * Hasil: {status, errors, puncak (memory_get_peak_usage), rss (puncak RSS proses dalam byte; null bila tidak bisa dibaca,
 * mis. Windows), detik}.
 */

use App\Exceptions\ApiException;
use App\Libraries\ApiExceptionHandler;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use CodeIgniter\HTTP\Files\UploadedFile;

chdir(dirname(__DIR__, 3));

// Bootstrap yang sama dengan PHPUnit: ENVIRONMENT = testing → database group `tests`.
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

$path  = (string) ($_SERVER['argv'][1] ?? '');
$mulai = hrtime(true);

try {
    (new ImporQna(db_connect()))->impor(new UploadedFile($path, 'qna.xlsx', 'application/octet-stream', (int) filesize($path), UPLOAD_ERR_OK));
    $result = ['status' => 200, 'errors' => null];
} catch (Throwable $e) {
    $result = ['status' => ApiExceptionHandler::toEnvelope($e)[0], 'errors' => $e instanceof ApiException ? $e->getErrors() : null];
}

$rss = null;

if (is_readable('/proc/self/status') && preg_match('/^VmHWM:\s+(\d+)\s+kB/m', (string) file_get_contents('/proc/self/status'), $m) === 1) {
    $rss = (int) $m[1] * 1024;
}

$result += ['puncak' => memory_get_peak_usage(), 'rss' => $rss, 'detik' => (hrtime(true) - $mulai) / 1e9];

echo PHP_EOL, json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
