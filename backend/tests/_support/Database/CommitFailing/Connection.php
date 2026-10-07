<?php

declare(strict_types=1);

namespace Tests\Support\Database\CommitFailing;

use Tests\Support\Database\Isolasi\Connection as IsolasiConnection;

/**
 * Driver MySQLi khusus test: COMMIT selalu "gagal" (_transCommit() = false, seperti mysqli::commit() yang mengembalikan
 * false) tanpa benar-benar meng-commit, sehingga transaksi masih terbuka dan bisa di-rollback. Builder/Result ada di
 * namespace yang sama karena CI4 menurunkan nama kelasnya dari nama kelas koneksi. Turunan driver Isolasi (MAKE-001)
 * hanya agar DBDriver kembali 'MySQLi' saat dibuat dari config grup `tests`; bingkai uji tidak dipakai di koneksi ini.
 */
class Connection extends IsolasiConnection
{
    protected function _transCommit(): bool
    {
        return false;
    }
}
