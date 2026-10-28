<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Rekap;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * PRD 5B §2.2 — penjaga arsitektur folder baru `app/Libraries/Presensi/Rekap/` (aturan hari uang makan, klasifikasi
 * kehadiran, penyesuaian Tukin, penanda tangan): murni, yaitu tanpa DB, HTTP, session, jam sistem, angka acak, atau I/O
 * berkas. Pemindaian aditif dengan aturan yang sama dengan `SlipGaji`/`Tukin`/`Geo` (tanpa melonggarkan larangan).
 *
 * @internal
 */
final class RekapMurniTest extends CIUnitTestCase
{
    public function testFolderRekapMurni(): void
    {
        $hasil = (new PemindaiKemurnian())->pindaiFolder(APPPATH . 'Libraries/Presensi/Rekap');

        $this->assertGreaterThanOrEqual(1, $hasil['jumlahBerkas'], 'Kelas Rekap 5B tidak ditemukan');
        $this->assertSame([], $hasil['pelanggaran']);
    }
}
