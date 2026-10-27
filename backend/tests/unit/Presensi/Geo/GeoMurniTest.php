<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Geo;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * PRD 5A §2.8 — penjaga arsitektur folder baru `app/Libraries/Presensi/Geo/` (haversine & penilaian geofence): murni
 * seperti `Presensi/SlipGaji` dan `Presensi/Tukin` (tanpa DB, HTTP, jam, angka acak, I/O berkas, log). Memakai
 * konfigurasi bawaan `PemindaiKemurnian` tanpa pengecualian (pemindaian aditif, larangan tidak dilonggarkan).
 *
 * @internal
 */
final class GeoMurniTest extends CIUnitTestCase
{
    public function testSemuaKelasGeoMurni(): void
    {
        $hasil = (new PemindaiKemurnian())->pindaiFolder(APPPATH . 'Libraries/Presensi/Geo');

        $this->assertGreaterThanOrEqual(5, $hasil['jumlahBerkas'], 'Kelas Geo 5A tidak ditemukan');
        $this->assertSame([], $hasil['pelanggaran']);
    }
}
