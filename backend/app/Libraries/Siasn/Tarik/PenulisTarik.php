<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Terapkan {@see RencanaTarik} dalam SATU transaksi pola proyek (transBegin → tulis → cek transStatus → commit/rollback →
 * resetTransStatus), padanan `trans_begin`/`trans_commit` per batch legacy [K `SS/*`]. Urutan: sisip → ubah → hapus.
 * Galat DB → rollback lalu {@see DatabaseException} (pemanggil mengembalikan flag status ke 0, command keluar ≠ 0).
 */
final class PenulisTarik
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function terapkan(RencanaTarik $rencana): void
    {
        if ($rencana->kosong()) {
            return;
        }

        $this->db->transBegin();

        try {
            foreach ($rencana->sisip as $s) {
                $this->wajib($this->db->table($s['tabel'])->insert($s['data']), "sisip {$s['tabel']}");
            }

            foreach ($rencana->ubah as $u) {
                $this->wajib($this->db->table($u['tabel'])->where($u['kunci'])->update($u['data']), "ubah {$u['tabel']}");
            }

            foreach ($rencana->hapus as $h) {
                $this->wajib($this->db->table($h['tabel'])->where($h['kunci'])->delete(), "hapus {$h['tabel']}");
            }

            if (! $this->db->transStatus()) {
                throw new DatabaseException('Transaksi tarik SIASN gagal.');
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e instanceof DatabaseException ? $e : new DatabaseException('Transaksi tarik SIASN gagal: ' . $e::class, 0, $e);
        }
    }

    private function wajib(mixed $hasil, string $langkah): void
    {
        if ($hasil === false) {
            throw new DatabaseException("Tarik SIASN gagal pada {$langkah}.");
        }
    }
}
