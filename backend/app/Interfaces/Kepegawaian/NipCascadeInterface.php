<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;

/**
 * Koreksi NIP dengan cascade ke seluruh tabel anak (B-06, role 1) — milik WS-2.
 *
 * Didaftarkan di Config\Services sejak S0-A (MAKE-002) dengan stub fail-closed; implementasi nyata
 * `App\Libraries\Kepegawaian\NipCascadeService` (MAKE-010). Method aditif oleh pemiliknya.
 */
interface NipCascadeInterface
{
    /**
     * Ganti NIP pegawai $nipLama menjadi $nipBaru dalam SATU transaksi (ikut `update_nip` legacy, keputusan #10): salin
     * baris `pegawai` ke NIP baru → arahkan ulang setiap kolom `TabelAnakNip::sampaiFase(3)` → hapus baris lama.
     * Satu langkah gagal → rollback penuh (tidak ada tabel yang setengah berpindah).
     *
     * Urutan pemeriksaan: role 1 (403) → format (422 `errors.nip_lama` / `errors.nip_baru`) → pegawai lama ada (404) →
     * NIP baru belum dipakai pegawai lain atau sebagai username/NIP akun lain (422 `errors.nip_baru`).
     *
     * @param mixed $nipBaru input klien apa adanya (bukan string → 422, bukan 500)
     *
     * @return array{nip_lama: string, nip_baru: string, tabel: array<string, int>, dilewati: list<string>, sesi_dicabut: int}
     *               `tabel` = `tabel.kolom` => jumlah baris yang diarahkan ulang; `dilewati` = `tabel.kolom` registry
     *               yang tabelnya belum ada di skema
     */
    public function koreksi(AuthContext $auth, string $nipLama, mixed $nipBaru): array;
}
