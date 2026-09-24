<?php

declare(strict_types=1);

namespace Tests\Support\Database\CommitFailing;

use CodeIgniter\Database\MySQLi\Connection as MySQLiConnection;

/**
 * Driver MySQLi khusus test: COMMIT selalu "gagal" (_transCommit() = false, seperti mysqli::commit() yang mengembalikan
 * false) tanpa benar-benar meng-commit, sehingga transaksi masih terbuka dan bisa di-rollback. Builder/Result ada di
 * namespace yang sama karena CI4 menurunkan nama kelasnya dari nama kelas koneksi.
 */
class Connection extends MySQLiConnection
{
    protected function _transCommit(): bool
    {
        return false;
    }
}
