<?php

declare(strict_types=1);

namespace Tests\Support\Database\Isolasi;

use CodeIgniter\Database\MySQLi\Connection as MySQLiConnection;
use Throwable;

/**
 * Driver koneksi grup `tests` (MAKE-001): MySQLi biasa, ditambah "bingkai uji" — transaksi luar yang dibuka
 * Tests\Support\DatabaseTestCase sebelum seed dan di-ROLLBACK sesudah test, sehingga data test tidak perlu
 * dibersihkan dengan migrate:refresh.
 *
 * Bingkai tidak terlihat oleh kode aplikasi: `transDepth` tetap 0 selama bingkai terbuka, jadi transBegin()/
 * transStart() aplikasi berjalan persis seperti tanpa bingkai (depth 0 → 1, error DB dilempar/ditangani sama), hanya
 * BEGIN/COMMIT/ROLLBACK fisiknya diganti SAVEPOINT/RELEASE/ROLLBACK TO SAVEPOINT. Commit aplikasi tetap terlihat di
 * koneksi ini; rollback aplikasi tetap membuang tulisannya sendiri.
 *
 * Batas (test seperti itu wajib #[Group('db-isolasi-penuh')]): DDL (CREATE/ALTER/DROP/TRUNCATE/RENAME, trigger,
 * down()/up() migration) melakukan implicit commit di MySQL; koneksi/proses lain tidak melihat data yang belum di-commit;
 * pencarian FULLTEXT InnoDB tidak melihat baris yang belum di-commit; counter AUTO_INCREMENT tidak ikut di-rollback
 * (dipulihkan DatabaseTestCase). Bingkai yang putus karena implicit commit terdeteksi di akhirBingkaiUji().
 *
 * DBDriver dikembalikan ke 'MySQLi' setelah konstruksi agar Forge/Utils MySQLi dipakai dan pemeriksaan platform
 * (Model::getPlatform() === 'MySQLi', queue) tetap sama dengan aplikasi.
 */
class Connection extends MySQLiConnection
{
    private const SAVEPOINT_BINGKAI = 'make001_bingkai_uji';
    private const SAVEPOINT_APP     = 'make001_transaksi_app';

    private bool $bingkaiUji = false;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->DBDriver = 'MySQLi';
    }

    /**
     * Buka bingkai uji: START TRANSACTION + savepoint penjaga. Ditolak bila ada transaksi aplikasi yang masih terbuka.
     */
    public function mulaiBingkaiUji(): void
    {
        if ($this->bingkaiUji || $this->transDepth !== 0) {
            throw new \LogicException('Bingkai uji tidak bisa dibuka: transaksi sebelumnya masih terbuka.');
        }

        if (empty($this->connID)) {
            $this->initialize();
        }

        $this->wajib('START TRANSACTION');
        $this->wajib('SAVEPOINT ' . self::SAVEPOINT_BINGKAI);
        $this->bingkaiUji   = true;
        $this->transStatus  = true;
        $this->transFailure = false;
    }

    /**
     * Tutup bingkai uji: ROLLBACK seluruh tulisan test (termasuk transaksi aplikasi yang tertinggal terbuka karena test
     * gagal di tengah). Mengembalikan false bila bingkai sudah putus sebelum ditutup — savepoint penjaga hilang karena
     * implicit commit (DDL) atau koneksi tersambung ulang — artinya ada tulisan yang sudah ter-commit.
     */
    public function akhiriBingkaiUji(): bool
    {
        if (! $this->bingkaiUji) {
            return true;
        }

        $this->bingkaiUji   = false;
        $this->transDepth   = 0;
        $this->transStatus  = true;
        $this->transFailure = false;

        try {
            $utuh = $this->connID->query('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT_BINGKAI) !== false;
        } catch (Throwable) {
            $utuh = false;
        }

        $this->connID->rollback();

        return $utuh;
    }

    public function dalamBingkaiUji(): bool
    {
        return $this->bingkaiUji;
    }

    protected function _transBegin(): bool
    {
        return $this->bingkaiUji ? $this->savepoint('SAVEPOINT ') : parent::_transBegin();
    }

    protected function _transCommit(): bool
    {
        return $this->bingkaiUji ? $this->savepoint('RELEASE SAVEPOINT ') : parent::_transCommit();
    }

    protected function _transRollback(): bool
    {
        return $this->bingkaiUji ? $this->savepoint('ROLLBACK TO SAVEPOINT ') : parent::_transRollback();
    }

    /**
     * Setara begin_transaction()/commit()/rollback() mysqli: mengembalikan false (tidak melempar) bila gagal.
     */
    private function savepoint(string $perintah): bool
    {
        try {
            return $this->connID->query($perintah . self::SAVEPOINT_APP) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function wajib(string $sql): void
    {
        if ($this->connID->query($sql) === false) {
            throw new \RuntimeException("Bingkai uji: '{$sql}' gagal: " . $this->connID->error);
        }
    }
}
