<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan;

use App\Libraries\Layanan\Pmk\AturanPmk;
use App\Libraries\Layanan\Studi\AturanStudi;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Layanan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * MAKE-024 (bagian murni) — penjaga arsitektur: kelas TB/IB/PMK di `app/Libraries/Layanan/{Studi,Tb,Pmk}/` dan kontrak
 * `app/Interfaces/Layanan/` murni (tanpa DB, HTTP, session, jam sistem, angka acak, lingkungan, konfigurasi, atau I/O) —
 * PRD Fase 4 v1.1 §6.1.4 (pola Tahap 2b cuti). Pengecualian sama dengan Cuti: hanya `::createFromFormat` berformat literal
 * berawalan `!` (`RentangTanggal::tanggal()`). Juga menjaga `Config\Layanan` = nilai bawaan value object.
 *
 * @internal
 */
final class LayananStudiPmkMurniTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: int}> folder => jumlah berkas minimum
     */
    public static function folderMurni(): array
    {
        return [
            'Studi (TB & IB)' => ['Libraries/Layanan/Studi', 6],
            'Tb'              => ['Libraries/Layanan/Tb', 2],
            'Pmk'             => ['Libraries/Layanan/Pmk', 5],
            'Interfaces'      => ['Interfaces/Layanan', 1],
        ];
    }

    #[DataProvider('folderMurni')]
    public function testFolderMurni(string $folder, int $minimum): void
    {
        $hasil = (new PemindaiKemurnian(createFromFormatBerjangkar: true))->pindaiFolder(APPPATH . $folder);

        $this->assertGreaterThanOrEqual($minimum, $hasil['jumlahBerkas'], "Kelas murni {$folder} tidak ditemukan");
        $this->assertSame([], $hasil['pelanggaran']);
    }

    public function testConfigLayananSamaDenganAturanBawaan(): void
    {
        $config = new Layanan();

        $this->assertEquals(AturanStudi::bawaan(), $config->aturanStudi());
        $this->assertEquals(AturanPmk::bawaan(), $config->aturanPmk());
        $this->assertSame(255, AturanStudi::bawaan()->maksByteReason);
        $this->assertSame(['Swasta', 'Negeri'], AturanPmk::bawaan()->jenisPmk);
    }
}
