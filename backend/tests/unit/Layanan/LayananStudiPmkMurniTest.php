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
 * M5 (ber-DB): PRD §6.7/§6.9 menaruh service ber-DB di folder yang sama (`Tb/TbService.php`, `Tb/StatusTugasBelajar.php`,
 * `Pmk/PmkPengajuanService.php`, …). Berkas lapisan DB dikenali dari namanya ({@see self::BERKAS_DB}) dan dikecualikan;
 * berkas lain di folder itu tetap wajib murni.
 *
 * @internal
 */
final class LayananStudiPmkMurniTest extends CIUnitTestCase
{
    /** Nama berkas lapisan DB M5 di folder murni (file boundary PRD §6.7–§6.9). */
    private const BERKAS_DB = '/(Service|StatusTugasBelajar|Trait)\.php$/';

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
        $pemindai    = new PemindaiKemurnian(createFromFormatBerjangkar: true);
        $jumlah      = 0;
        $pelanggaran = [];

        foreach (glob(APPPATH . $folder . '/*.php') ?: [] as $path) {
            if (preg_match(self::BERKAS_DB, basename($path)) === 1) {
                continue;
            }

            $jumlah++;
            array_push($pelanggaran, ...$pemindai->pindai((string) file_get_contents($path), basename($path)));
        }

        $this->assertGreaterThanOrEqual($minimum, $jumlah, "Kelas murni {$folder} tidak ditemukan");
        $this->assertSame([], $pelanggaran);
    }

    public function testPengecualianHanyaBerkasLapisanDb(): void
    {
        foreach (['Libraries/Layanan/Studi', 'Interfaces/Layanan'] as $folder) {
            foreach (glob(APPPATH . $folder . '/*.php') ?: [] as $path) {
                $this->assertSame(0, preg_match(self::BERKAS_DB, basename($path)), "{$folder} tidak boleh berisi lapisan DB");
            }
        }

        $this->assertSame(1, preg_match(self::BERKAS_DB, 'TbService.php'));
        $this->assertSame(1, preg_match(self::BERKAS_DB, 'StatusTugasBelajar.php'));
        $this->assertSame(0, preg_match(self::BERKAS_DB, 'BebasPresensiTb.php'));
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
