<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\AturanCuti;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Cuti;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * MAKE-022 Tahap 2b — penjaga arsitektur: kelas di `app/Libraries/Layanan/Cuti/` murni (tanpa DB, HTTP, session, jam
 * sistem, angka acak, lingkungan, konfigurasi, atau I/O) — PRD Fase 4 v1.1 §6.1.4. Pengecualian modul ini sama dengan
 * Tukin: `::createFromFormat` hanya dengan format literal berawalan `!` (`HitungLamaCuti::tanggal()`); `Time::*` tidak
 * boleh sama sekali karena "hari ini"/"sekarang" diinjeksikan pemanggil.
 *
 * Juga menjaga `Config\Cuti` (sumber nilai runtime) = `AturanCuti::bawaan()` (nilai PRD §6.3.1).
 *
 * @internal
 */
final class CutiMurniTest extends CIUnitTestCase
{
    public function testSemuaKelasCutiMurni(): void
    {
        $hasil = self::pemindai()->pindaiFolder(APPPATH . 'Libraries/Layanan/Cuti');

        $this->assertGreaterThanOrEqual(10, $hasil['jumlahBerkas'], 'Kelas murni cuti MAKE-022 tidak ditemukan');
        $this->assertSame([], $hasil['pelanggaran']);
    }

    public function testPengecualianHanyaCreateFromFormatBerjangkar(): void
    {
        $pemindai = self::pemindai();

        $this->assertSame([], $pemindai->pindai("<?php\n\$d = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$t);", 'HitungLamaCuti.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\n\$d = \\DateTimeImmutable::createFromFormat('Y-m-d', \$t);", 'HitungLamaCuti.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\n\$d = new \\DateTimeImmutable();", 'PengingatCuti.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\n\$c = config('Cuti');", 'KuotaCuti.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\nfunction hariIni(\$s = null) { return Time::now(); }", 'TanggalBisnis.php'));
    }

    public function testConfigCutiSamaDenganAturanBawaan(): void
    {
        $this->assertEquals(AturanCuti::bawaan(), (new Cuti())->aturan());
        $this->assertSame(12, AturanCuti::bawaan()->kuotaN);
        $this->assertSame(24, AturanCuti::bawaan()->maksTotal);
        $this->assertSame('12:00', AturanCuti::bawaan()->jamBatasTunda);
    }

    private static function pemindai(): PemindaiKemurnian
    {
        return new PemindaiKemurnian(createFromFormatBerjangkar: true);
    }
}
