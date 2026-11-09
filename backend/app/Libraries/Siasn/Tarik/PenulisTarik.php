<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Libraries\Integrasi\Simapi\AturanTrigger;
use App\Libraries\Integrasi\Simapi\PencatatPerubahan;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Terapkan {@see RencanaTarik} dalam SATU transaksi pola proyek (transBegin → tulis → cek transStatus → commit/rollback →
 * resetTransStatus), padanan `trans_begin`/`trans_commit` per batch legacy [K `SS/*`]. Urutan: sisip → ubah → hapus.
 * Galat DB → rollback lalu {@see DatabaseException} (pemanggil mengembalikan flag status ke 0, command keluar ≠ 0).
 *
 * MAKE-060 A5b: tabel riwayat ber-trigger legacy (`AturanTrigger::RIWAYAT`) ikut memancar log SIMAPI `simapi_ch` di transaksi
 * yang sama (baris lama dibaca sebelum tulis; legacy trigger menyala untuk tulisan tarik SIASN juga).
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
                $this->catatSisip($s['tabel']);
            }

            foreach ($rencana->ubah as $u) {
                $lama = $this->barisSimapi($u['tabel'], $u['kunci']);
                $this->wajib($this->db->table($u['tabel'])->where($u['kunci'])->update($u['data']), "ubah {$u['tabel']}");
                $this->catatUbah($u['tabel'], $lama);
            }

            foreach ($rencana->hapus as $h) {
                $lama = $this->barisSimapi($h['tabel'], $h['kunci']);
                $this->wajib($this->db->table($h['tabel'])->where($h['kunci'])->delete(), "hapus {$h['tabel']}");

                foreach ($lama as $baris) {
                    PencatatPerubahan::catat($this->db, $h['tabel'], AturanTrigger::DELETE, $baris, null);
                }
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

    /**
     * @param array<string, mixed> $kunci
     *
     * @return list<array<string, mixed>>
     */
    private function barisSimapi(string $tabel, array $kunci): array
    {
        return isset(AturanTrigger::RIWAYAT[$tabel]) ? $this->db->table($tabel)->where($kunci)->get()->getResultArray() : [];
    }

    private function catatSisip(string $tabel): void
    {
        if (! isset(AturanTrigger::RIWAYAT[$tabel])) {
            return;
        }

        PencatatPerubahan::catat($this->db, $tabel, AturanTrigger::INSERT, null, PencatatPerubahan::baca($this->db, $tabel, AturanTrigger::RIWAYAT[$tabel][1], $this->db->insertID()));
    }

    /**
     * @param list<array<string, mixed>> $lama
     */
    private function catatUbah(string $tabel, array $lama): void
    {
        $pk = AturanTrigger::RIWAYAT[$tabel][1] ?? null;

        foreach ($lama as $baris) {
            PencatatPerubahan::sesudahUbah($this->db, $tabel, (string) $pk, $baris[$pk] ?? null, $baris);
        }
    }
}
