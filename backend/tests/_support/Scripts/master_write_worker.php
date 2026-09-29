<?php

declare(strict_types=1);

/**
 * Worker CLI khusus test (bukan kode aplikasi). Menjalankan satu tulis MasterService (create, update, setStatus,
 * reorder, delete) di proses PHP terpisah (koneksi database sendiri, group `tests`), lalu mencetak hasilnya sebagai satu
 * baris JSON di akhir output. Dipakai Tests\MasterData\MasterWriteLockTest untuk menguji tulis master yang benar-benar
 * paralel di lingkup urutan yang sama (CR-020, ISSUE-020).
 *
 * Pemakaian: php tests/_support/Scripts/master_write_worker.php <job> <batas-tunggu-lock-detik>
 *   <job> = base64 dari JSON {op, entity, id?, data?, status?, order?} (base64 supaya tanda kutip JSON tidak diolah
 *   ulang oleh baris perintah Windows).
 */

use App\Exceptions\ApiException;
use App\Libraries\ApiExceptionHandler;
use App\Libraries\MasterData\MasterService;

chdir(dirname(__DIR__, 3));

// Bootstrap yang sama dengan PHPUnit: ENVIRONMENT = testing → database group `tests`.
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

/** @var array{op: string, entity: string, id?: string, data?: array<string, mixed>, status?: string, order?: int} $job */
$job     = json_decode((string) base64_decode((string) ($_SERVER['argv'][1] ?? ''), true), true, 512, JSON_THROW_ON_ERROR);
$timeout = (int) ($_SERVER['argv'][2] ?? MasterService::LOCK_TIMEOUT);

try {
    // Batas tunggu lock diatur test: worker harus tetap menunggu selama worker lain masih start-up.
    $service = new MasterService(service('masterRegistry'), service('cacheService'), null, $timeout);
    $def     = service('masterRegistry')->get($job['entity']);
    $id      = (string) ($job['id'] ?? '');

    $row = match ($job['op']) {
        'create'    => $service->create($def, $job['data'] ?? []),
        'update'    => $service->update($def, $id, $job['data'] ?? []),
        'setStatus' => $service->setStatus($def, $id, (string) ($job['status'] ?? '')),
        'reorder'   => $service->reorder($def, $id, (int) ($job['order'] ?? 0)),
        'delete'    => $service->delete($def, $id),
    };

    $result = ['status' => 200, 'id' => (string) $row[$def->primaryKey]];
} catch (Throwable $e) {
    $result = [
        'status'  => ApiExceptionHandler::toEnvelope($e)[0],
        'class'   => $e::class,
        'message' => $e->getMessage(),
        'errors'  => $e instanceof ApiException ? $e->getErrors() : null,
    ];
}

echo PHP_EOL, json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
