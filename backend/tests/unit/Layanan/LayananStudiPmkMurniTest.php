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
 * M5 (ber-DB): PRD §6.7/§6.9 menaruh service ber-DB di folder `Tb/` dan `Pmk/`. Setiap berkas di folder itu WAJIB terdaftar
 * di {@see self::BERKAS_MURNI} (dipindai) atau {@see self::BERKAS_DB} (lapisan DB, dikecualikan) — daftar eksplisit,
 * disamakan dengan keputusan M3 reviewer CR 27-10-2026, sehingga berkas baru tidak lolos diam-diam. `Studi/` dan
 * `Interfaces/Layanan/` 100% murni (tanpa daftar DB).
 *
 * @internal
 */
final class LayananStudiPmkMurniTest extends CIUnitTestCase
{
    /**
     * Berkas murni per folder (dipindai PemindaiKemurnian).
     *
     * @var array<string, list<string>>
     */
    private const BERKAS_MURNI = [
        'Libraries/Layanan/Studi' => [
            'AturanStudi.php', 'BerkasStudi.php', 'LamaTahunStudi.php', 'RentangTanggal.php', 'StatusStudi.php',
            'TampilanStudi.php', 'TransisiStudi.php', 'ValidasiStudi.php',
        ],
        'Libraries/Layanan/Tb'  => ['BebasPresensiTb.php', 'PerpanjanganTb.php'],
        'Libraries/Layanan/Pmk' => ['AturanPmk.php', 'MasaKerjaPmk.php', 'StatusPmk.php', 'TampilanPmk.php', 'TransisiPmk.php', 'ValidasiPmk.php'],
        'Interfaces/Layanan'    => ['NotifikasiLayananInterface.php', 'StatusTugasBelajarInterface.php'],
    ];

    /**
     * Berkas lapisan DB M5 (file boundary PRD §6.7–§6.9) — dikecualikan dari pemindaian kemurnian.
     *
     * @var array<string, list<string>>
     */
    private const BERKAS_DB = [
        'Libraries/Layanan/Studi' => [],
        'Libraries/Layanan/Tb'    => [
            'EfekTbTrait.php', 'PerpanjanganTbService.php', 'StatusTugasBelajar.php', 'SuratTbService.php',
            'TbApprovalService.php', 'TbService.php',
        ],
        'Libraries/Layanan/Pmk' => ['DasarPmkService.php', 'PmkApprovalService.php', 'PmkPengajuanService.php', 'RiwayatPmkService.php'],
        'Interfaces/Layanan'    => [],
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function folder(): array
    {
        return array_combine(array_keys(self::BERKAS_MURNI), array_map(static fn (string $f): array => [$f], array_keys(self::BERKAS_MURNI)));
    }

    #[DataProvider('folder')]
    public function testSetiapBerkasTerdaftarMurniAtauLapisanDb(string $folder): void
    {
        $ada = array_map('basename', glob(APPPATH . $folder . '/*.php') ?: []);
        sort($ada);

        $terdaftar = [...self::BERKAS_MURNI[$folder], ...self::BERKAS_DB[$folder]];
        sort($terdaftar);

        $this->assertSame([], array_values(array_intersect(self::BERKAS_MURNI[$folder], self::BERKAS_DB[$folder])), "{$folder}: berkas terdaftar ganda");
        $this->assertSame($terdaftar, $ada, "{$folder}: setiap berkas wajib terdaftar di BERKAS_MURNI atau BERKAS_DB");
    }

    #[DataProvider('folder')]
    public function testBerkasMurniTanpaPelanggaran(string $folder): void
    {
        $pemindai    = new PemindaiKemurnian(createFromFormatBerjangkar: true);
        $pelanggaran = [];

        foreach (self::BERKAS_MURNI[$folder] as $berkas) {
            array_push($pelanggaran, ...$pemindai->pindai((string) file_get_contents(APPPATH . $folder . '/' . $berkas), $berkas));
        }

        $this->assertNotSame([], self::BERKAS_MURNI[$folder]);
        $this->assertSame([], $pelanggaran);
    }

    public function testStudiDanInterfacesTanpaLapisanDb(): void
    {
        $this->assertSame([], self::BERKAS_DB['Libraries/Layanan/Studi']);
        $this->assertSame([], self::BERKAS_DB['Interfaces/Layanan']);
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
