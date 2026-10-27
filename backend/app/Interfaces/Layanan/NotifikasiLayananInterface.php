<?php

declare(strict_types=1);

namespace App\Interfaces\Layanan;

/**
 * Titik sambung notifikasi layanan TB/IB/PMK (PRD Fase 4 §6.6.1, MAKE-024 M5). Binding sementara
 * `App\Libraries\Layanan\NotifikasiNoOp`; binding diganti NotifikasiService di M4 (MAKE-023) tanpa mengubah pemanggil.
 *
 * Pola pemanggil (§7.8): kirim() di DALAM transaksi bisnis (menulis `inbox`/`inbox_msg` v2, tidak pernah
 * `disiplin_ticket*`), lalu enqueuePush() dengan id hasilnya SESUDAH commit. Bila transaksi rollback, enqueuePush() tidak
 * dipanggil.
 */
interface NotifikasiLayananInterface
{
    /**
     * Tulis notifikasi satu arah untuk `$penerima` (daftar NIP; duplikat digabung, NIP pelaku aksi tidak dikirimi).
     *
     * §6.6.1 menulis tipe kembalian `void` sekaligus "mengembalikan daftar id untuk di-enqueue pemanggil sesudah
     * commit"; kontrak ini memakai kalimat kedua (daftar id `inbox_msg` baru).
     *
     * @param string     $modul        `cuti` | `tb` | `ib` | `pmk` (domain CHECK `inbox.modul`)
     * @param int        $tipe         1 Diajukan, 2 Keputusan, 3 Perubahan, 4 Tembusan (`inbox_msg.tipe`)
     * @param list<string> $penerima   NIP penerima
     * @param int        $statusSumber status baris sumber sesudah kejadian
     *
     * @return list<int> id `inbox_msg` baru
     */
    public function kirim(string $modul, int|string $idRwy, int $tipe, array $penerima, string $judul, string $isi, ?string $reasonNote, int $statusSumber, string $url): array;

    /**
     * Antrekan push mock FCM untuk `inbox_msg` yang sudah ter-commit. Gagal enqueue tidak membatalkan transaksi bisnis.
     *
     * @param list<int> $ids id `inbox_msg`
     */
    public function enqueuePush(array $ids): void;
}
