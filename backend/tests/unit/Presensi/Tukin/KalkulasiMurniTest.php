<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * CR-037 — penjaga arsitektur: kelas di `app/Libraries/Presensi/Tukin/` murni (tanpa DB, HTTP, session, atau jam
 * sistem). `Time::*` sama sekali tidak boleh dipakai karena "hari ini" diinjeksikan lewat parameter `hariIni`.
 *
 * CR-040 (U-8): pemindai dipindah ke `Tests\Support\Libraries\PemindaiKemurnian` (uji dirinya di
 * `Tests\Unit\Libraries\PemindaiKemurnianTest`) dengan daftar larangan gabungan ketiga modul. Pengecualian modul ini:
 * `::createFromFormat` boleh hanya dengan format literal berawalan `!` atau memuat `|` (`HariKerja` mem-parse tanggal
 * dengan `'!Y-m-d'` sehingga jam diisi nol, bukan jam sekarang). Sebelum CR-040 modul ini tidak membatasi
 * `createFromFormat` sama sekali.
 *
 * @internal
 */
final class KalkulasiMurniTest extends CIUnitTestCase
{
    public function testSemuaKelasTukinMurni(): void
    {
        $hasil = self::pemindai()->pindaiFolder(APPPATH . 'Libraries/Presensi/Tukin');

        $this->assertGreaterThanOrEqual(9, $hasil['jumlahBerkas'], 'Kelas kalkulasi Tukin CR-037 tidak ditemukan');
        $this->assertSame([], $hasil['pelanggaran']);
    }

    public function testPengecualianHanyaCreateFromFormatBerjangkar(): void
    {
        $pemindai = self::pemindai();

        $this->assertSame([], $pemindai->pindai("<?php\n\$d = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$t);", 'HariKerja.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\n\$d = \\DateTimeImmutable::createFromFormat('Y-m-d', \$t);", 'HariKerja.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\nfunction hariIni(\$s = null) { return Time::now(); }", 'TanggalBisnis.php'));
    }

    private static function pemindai(): PemindaiKemurnian
    {
        return new PemindaiKemurnian(createFromFormatBerjangkar: true);
    }
}
