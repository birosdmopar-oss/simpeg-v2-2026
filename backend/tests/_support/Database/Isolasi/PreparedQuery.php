<?php

declare(strict_types=1);

namespace Tests\Support\Database\Isolasi;

use CodeIgniter\Database\MySQLi\PreparedQuery as MySQLiPreparedQuery;

/**
 * Pasangan kelas driver test Isolasi (MAKE-001): CI4 memuat Builder/Result/PreparedQuery dari namespace kelas koneksi.
 */
class PreparedQuery extends MySQLiPreparedQuery
{
}
