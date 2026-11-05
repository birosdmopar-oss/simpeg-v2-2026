<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\StatusHarian;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * MAKE-062 — penjaga arsitektur: aturan di `app/Libraries/Layanan/StatusHarian/` murni (tanpa DB, HTTP, session, jam sistem,
 * angka acak, lingkungan, konfigurasi, atau I/O); pengecualian sama dengan TB/IB/PMK (hanya `::createFromFormat` berformat
 * literal berawalan `!`, lewat `RentangTanggal`). Setiap berkas wajib terdaftar murni atau lapisan DB (daftar eksplisit).
 *
 * @internal
 */
final class StatusHarianMurniTest extends CIUnitTestCase
{
    private const FOLDER = 'Libraries/Layanan/StatusHarian';

    private const BERKAS_MURNI = ['KeputusanStatusHarian.php', 'PenentuStatusHarian.php'];

    private const BERKAS_DB = ['StatusHarianService.php', 'StatusHarianSibuk.php'];

    public function testSetiapBerkasTerdaftarMurniAtauLapisanDb(): void
    {
        $ada = array_map('basename', glob(APPPATH . self::FOLDER . '/*.php') ?: []);
        sort($ada);

        $terdaftar = [...self::BERKAS_MURNI, ...self::BERKAS_DB];
        sort($terdaftar);

        $this->assertSame($terdaftar, $ada, 'setiap berkas wajib terdaftar di BERKAS_MURNI atau BERKAS_DB');
    }

    public function testBerkasMurniTanpaPelanggaran(): void
    {
        $pemindai    = new PemindaiKemurnian(createFromFormatBerjangkar: true);
        $pelanggaran = [];

        foreach (self::BERKAS_MURNI as $berkas) {
            array_push($pelanggaran, ...$pemindai->pindai((string) file_get_contents(APPPATH . self::FOLDER . '/' . $berkas), $berkas));
        }

        $this->assertSame([], $pelanggaran);
    }
}
