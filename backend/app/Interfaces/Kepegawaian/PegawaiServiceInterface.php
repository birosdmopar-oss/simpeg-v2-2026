<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

/**
 * Daftar & detail pegawai (B-20 dasar, `GET api/v1/pegawai`, `GET api/v1/pegawai/{nip}`) — milik WS-2.
 *
 * Didaftarkan di Config\Services sejak S0-A (MAKE-002) dengan stub fail-closed. Method ditetapkan pemiliknya di
 * milestone implementasi (aditif); pemilik sekaligus memperbarui stub di `App\Libraries\Kepegawaian\Stub` atau
 * mengganti stub di Services.php dengan kelas nyata.
 */
interface PegawaiServiceInterface
{
}
