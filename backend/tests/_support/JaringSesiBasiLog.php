<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\TestLogger;

/**
 * CR-185 R1: jumlah entri log warning jaring 1452 sesi NIP basi (ApiController::_remap) yang sudah tercatat. Riwayat
 * TestLogger statis dan tidak dikosongkan antar test, jadi test membandingkan jumlah sebelum/sesudah request — bukti bahwa
 * 401 datang dari jaring, bukan dari JwtAuthFilter.
 */
final class JaringSesiBasiLog
{
    public const PESAN = 'FK 1452 pada sesi NIP basi diterjemahkan ke 401: [1452] ' . DatabaseException::class . '.';

    public static function jumlah(): int
    {
        /** @var list<array{level: string, message: string}> $log */
        $log = Closure::bind(static fn (): array => TestLogger::$op_logs, null, TestLogger::class)();

        return count(array_filter($log, static fn (array $r): bool => strtolower($r['level']) === 'warning' && $r['message'] === self::PESAN));
    }
}
