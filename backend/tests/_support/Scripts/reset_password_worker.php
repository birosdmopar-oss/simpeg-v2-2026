<?php

declare(strict_types=1);

/**
 * Worker CLI khusus test (bukan kode aplikasi). Menjalankan satu ResetPasswordService::reset() di proses PHP terpisah
 * (koneksi database sendiri, group `tests`), lalu mencetak hasilnya sebagai satu baris JSON di akhir output.
 * Dipakai Tests\Auth\ResetPasswordTransactionTest untuk menguji reset yang benar-benar paralel.
 *
 * Pemakaian: php tests/_support/Scripts/reset_password_worker.php <token> <password-baru>
 */

use App\Exceptions\ApiException;
use App\Libraries\ApiExceptionHandler;

chdir(dirname(__DIR__, 3));

// Bootstrap yang sama dengan PHPUnit: ENVIRONMENT = testing → database group `tests`.
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

$token    = (string) ($_SERVER['argv'][1] ?? '');
$password = (string) ($_SERVER['argv'][2] ?? '');

// Batas tunggu lock agar worker tidak menggantung bila test gagal melepas lock.
db_connect()->query('SET SESSION innodb_lock_wait_timeout = 20');

try {
    service('resetPasswordService', false)->reset($token, $password, $password);

    $result = ['status' => 200];
} catch (Throwable $e) {
    $result = [
        'status'  => ApiExceptionHandler::toEnvelope($e)[0],
        'class'   => $e::class,
        'message' => $e->getMessage(),
        'errors'  => $e instanceof ApiException ? $e->getErrors() : null,
    ];
}

echo PHP_EOL, json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
