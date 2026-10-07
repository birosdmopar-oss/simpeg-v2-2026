<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;

/**
 * Sinkron snapshot `pegawai_*` dari tabel riwayat (WS-1 M1 MAKE-004; kontrak beku S0-A MAKE-002).
 *
 * Hitung ulang semua AturanSnapshot milik $definisi untuk satu NIP: pilih baris Disetujui sesuai aturan, tulis/ganti
 * snapshot, atau hapus snapshot bila tidak ada baris yang memenuhi. Dipanggil HANYA di approval final dan setiap
 * transisi masuk/keluar status Disetujui (ADR-006), di dalam transaksi pemanggil, dengan audit.
 */
interface SnapshotSyncInterface
{
    public function sinkronkan(RiwayatDefinisi $definisi, string $nip): void;
}
