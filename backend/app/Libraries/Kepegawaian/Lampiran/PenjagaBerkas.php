<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use Throwable;

/**
 * Kompensasi berkas untuk tulis/hapus lampiran yang berjalan DI DALAM transaksi pemanggil (B-18, MAKE-009).
 *
 * Disk tidak ikut transaksi DB, dan pemanggil (RiwayatEngine WS-1, endpoint lampiran) tidak wajib memberi tahu hasil
 * transaksinya. Karena itu berkas tidak dihapus/dipertahankan berdasarkan "commit atau rollback", melainkan
 * direkonsiliasi dengan DB SETELAH transaksi selesai: berkas tercatat tetap ada hanya bila masih dirujuk baris DB
 * (`tabel.kolom = path`).
 *
 * - Tulis: berkas ditulis lebih dulu lalu dicatat. Rollback → barisnya hilang → berkas dihapus.
 * - Hapus: berkas TIDAK langsung dihapus, hanya dicatat. Commit → baris hilang → berkas dihapus; rollback → baris
 *   kembali → berkas dipertahankan.
 *
 * selesaikan() hanya bekerja saat tidak ada transaksi terbuka (`transDepth` 0); dipanggil pemanggil sesudah
 * commit/rollback, secara oportunistik oleh service pada operasi berikutnya, dan sebagai cadangan saat proses PHP
 * berakhir (register_shutdown_function, kecuali di lingkungan testing).
 */
final class PenjagaBerkas
{
    /**
     * @var array<string, array{0: string, 1: string}> path => [tabel, kolom] perujuk
     */
    private array $tercatat = [];

    private bool $shutdownTerdaftar = false;

    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly ?ConnectionInterface $db = null,
    ) {
    }

    /**
     * Catat berkas $path yang sah selama dirujuk `$tabel.$kolom`.
     */
    public function catat(string $path, string $tabel, string $kolom): void
    {
        $this->tercatat[$path] = [$tabel, $kolom];

        if (! $this->shutdownTerdaftar && ENVIRONMENT !== 'testing') {
            $this->shutdownTerdaftar = true;
            register_shutdown_function(function (): void {
                $this->selesaikan(true);
            });
        }
    }

    /**
     * Ada transaksi DB yang masih terbuka di koneksi ini.
     */
    public function dalamTransaksi(): bool
    {
        $db = $this->koneksi();

        return $db instanceof BaseConnection && (int) $db->transDepth > 0;
    }

    /**
     * Rekonsiliasi berkas tercatat dengan DB. Mengembalikan false (tanpa perubahan) bila transaksi masih terbuka.
     *
     * @param bool $akhirProses true = dipanggil saat proses berakhir: transaksi yang tertinggal terbuka akan dibatalkan
     *                          server saat koneksi ditutup, jadi dibatalkan dulu di sini agar rekonsiliasi melihat
     *                          keadaan akhir yang sebenarnya
     */
    public function selesaikan(bool $akhirProses = false): bool
    {
        if ($this->tercatat === []) {
            return true;
        }

        $db = $this->koneksi();

        if ($akhirProses && $db instanceof BaseConnection) {
            try {
                while ((int) $db->transDepth > 0 && $db->transRollback()) {
                    // transRollback() menurunkan transDepth satu tingkat.
                }
            } catch (Throwable) {
                return false;
            }
        }

        if ($this->dalamTransaksi()) {
            return false;
        }

        $tercatat       = $this->tercatat;
        $this->tercatat = [];

        foreach ($tercatat as $path => [$tabel, $kolom]) {
            try {
                if ($db->table($tabel)->where($kolom, $path)->countAllResults() === 0) {
                    $this->storage->hapus($path);
                }
            } catch (Throwable $e) {
                log_message('error', 'Kompensasi berkas lampiran gagal untuk {path}: {pesan}', ['path' => $path, 'pesan' => $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * @return list<string> path yang masih menunggu rekonsiliasi (untuk test/diagnostik)
     */
    public function menunggu(): array
    {
        return array_keys($this->tercatat);
    }

    private function koneksi(): ConnectionInterface
    {
        return $this->db ?? db_connect();
    }
}
