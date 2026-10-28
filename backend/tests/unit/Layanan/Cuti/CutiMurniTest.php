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
    /**
     * Berkas murni (daftar eksplisit: tahap 2b PRD §6.1.4 + `SnapshotSaldoCuti` + `SaldoTahunanCuti`). Kelas ber-DB
     * (PRD §6.2–§6.4 file boundary) hidup di folder yang sama dan tidak dipindai sebagai murni.
     */
    private const MURNI = [
        'AturanCuti', 'AturanJenisCuti', 'CutiPenyaji', 'EfekFinalCuti', 'HitungLamaCuti', 'KuotaCuti', 'PengingatCuti', 'PorsiTahunCuti', 'SaldoCuti',
        'SaldoTahunanCuti', 'SnapshotSaldoCuti', 'StatusCuti', 'TransisiCuti', 'ValidasiTanggalCuti',
    ];

    /** Kelas ber-DB yang path-nya ditetapkan PRD tanpa sufiks `Service`. */
    private const BER_DB = ['ResetTundaCuti', 'ApproverTerjangkau', 'AtasanLangsungCuti', 'PejabatBerwenangCuti', 'OpsiCuti', 'PenerbitSuratCutiNoOp'];

    public function testSemuaKelasCutiMurni(): void
    {
        $pemindai    = self::pemindai();
        $pelanggaran = [];

        foreach (self::MURNI as $kelas) {
            $berkas = APPPATH . "Libraries/Layanan/Cuti/{$kelas}.php";
            $this->assertFileExists($berkas, "Kelas murni {$kelas} tidak ditemukan");
            $pelanggaran = array_merge($pelanggaran, $pemindai->pindai((string) file_get_contents($berkas), "{$kelas}.php"));
        }

        $this->assertSame([], $pelanggaran);
    }

    public function testSetiapBerkasBaruTerdaftarSebagaiMurniAtauBerDb(): void
    {
        $tidakTerdaftar = [];

        foreach (glob(APPPATH . 'Libraries/Layanan/Cuti/*.php') ?: [] as $berkas) {
            $kelas = basename($berkas, '.php');

            if (! in_array($kelas, self::MURNI, true) && ! in_array($kelas, self::BER_DB, true) && ! str_ends_with($kelas, 'Service')) {
                $tidakTerdaftar[] = $kelas;
            }
        }

        $this->assertSame([], $tidakTerdaftar, 'Berkas baru harus didaftarkan sebagai murni (MURNI) atau ber-DB (BER_DB / sufiks Service)');
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
