<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * CR-027 (K-CR027-1) — penjaga arsitektur: kelas di `app/Libraries/Presensi/SlipGaji/` murni, yaitu tanpa DB, HTTP,
 * session, jam, angka acak, atau I/O berkas. **Semua** `Time::` dilarang tanpa pengecualian, `hariIni()` wajib diberi
 * argumen, dan `::createFromFormat`/`date_create*` dilarang karena mengisi bagian yang tidak ada dari jam sekarang.
 *
 * CR-040 (U-8): pemindai dipindah ke `Tests\Support\Libraries\PemindaiKemurnian` (uji dirinya di
 * `Tests\Unit\Libraries\PemindaiKemurnianTest`) dengan daftar larangan gabungan ketiga modul; modul ini memakai
 * konfigurasi bawaan (tanpa pengecualian).
 *
 * @internal
 */
final class SlipGajiMurniTest extends CIUnitTestCase
{
    public function testSemuaKelasSlipGajiMurni(): void
    {
        $hasil = (new PemindaiKemurnian())->pindaiFolder(APPPATH . 'Libraries/Presensi/SlipGaji');

        $this->assertGreaterThanOrEqual(14, $hasil['jumlahBerkas'], 'Kelas Slip Gaji CR-027 tidak ditemukan');
        $this->assertSame([], $hasil['pelanggaran']);
    }
}
