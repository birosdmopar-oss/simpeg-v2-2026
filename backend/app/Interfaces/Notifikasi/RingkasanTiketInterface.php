<?php

declare(strict_types=1);

namespace App\Interfaces\Notifikasi;

use App\Libraries\Auth\AuthContext;

/**
 * Titik sambung penghitung tiket Halo Simpeg ke lonceng F-08 (PRD Fase 8 F8-19, Q8-06 = B; PRD Fase 7 v2.0 §5.9).
 *
 * BP8-1: modul notifikasi tidak membaca tabel tiket; angka tiket hanya lewat antarmuka ini. Binding
 * `Config\Services::ringkasanTiket()` = NoOp (null) sampai Fase 8 menyediakan implementasinya.
 */
interface RingkasanTiketInterface
{
    /**
     * Jumlah tiket belum dibaca milik pemanggil (sisi pemilik + sisi petugas). null = sumber belum tersedia.
     */
    public function jumlahBelumDibaca(AuthContext $auth): ?int;
}
