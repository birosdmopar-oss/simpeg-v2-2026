<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use CodeIgniter\Cache\CacheInterface;

/**
 * MAKE-058 — pembatas kegagalan auth per kunci (IP) dan penahan log throttle (review MAKE-058 B-1, P-2). Jendela tetap:
 *  - {@see self::terbuka()} / {@see self::catatGagal()}: hanya KEGAGALAN yang dihitung, sehingga klien sah di satu IP tidak
 *    menghabiskan bucket IP; bucket yang penuh ditolak sebelum `password_verify` (melindungi CPU dari banjir percobaan).
 *  - {@see self::sekali()}: penanda "sudah dicatat pada jendela ini" supaya penolakan throttle ditulis ke `integrasi_log`
 *    paling banyak SEKALI per kunci per jendela (bukan satu baris per request yang ditolak).
 * Kunci selalu di-hash (md5) oleh pemanggil; nilai IP tidak disimpan di DB maupun log.
 */
final class PembatasKegagalan
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Apakah kunci masih di bawah kapasitas kegagalan pada jendela berjalan.
     */
    public function terbuka(string $kunci, int $kapasitas): bool
    {
        return (int) ($this->cache->get('klgg|' . $kunci) ?? 0) < $kapasitas;
    }

    /**
     * Tambah satu kegagalan; jendela dimulai pada kegagalan pertama dan berakhir `$detik` kemudian.
     */
    public function catatGagal(string $kunci, int $detik): void
    {
        $nama  = 'klgg|' . $kunci;
        $nilai = (int) ($this->cache->get($nama) ?? 0);

        // Menyimpan ulang memperpanjang TTL (jendela menggeser); cukup untuk membatasi banjir dan tetap murah.
        $this->cache->save($nama, $nilai + 1, $detik);
    }

    /**
     * True hanya untuk panggilan PERTAMA pada jendela `$detik` untuk kunci ini; berikutnya false sampai penanda kedaluwarsa.
     */
    public function sekali(string $kunci, int $detik): bool
    {
        $nama = 'klog|' . $kunci;

        if ($this->cache->get($nama) !== null) {
            return false;
        }

        $this->cache->save($nama, 1, $detik);

        return true;
    }
}
