<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * CR-025 (K-CR025-1) — penjaga arsitektur: kelas di `app/Libraries/Kepegawaian/Kalkulasi/` dan (CR-036)
 * `app/Libraries/Kepegawaian/Nip/` murni (tanpa DB, HTTP, session, jam, angka acak, lingkungan, atau I/O).
 *
 * CR-040 (U-8): pemindai dipindah ke `Tests\Support\Libraries\PemindaiKemurnian` (uji dirinya di
 * `Tests\Unit\Libraries\PemindaiKemurnianTest`) dengan daftar larangan gabungan ketiga modul. Pengecualian modul ini:
 * jam hanya boleh dibaca `TanggalBisnis::hariIni()` lewat `Time::now()` (impor `CodeIgniter\I18n\Time` hanya di
 * `TanggalBisnis.php`); `::createFromFormat` tetap dilarang seluruhnya.
 *
 * @internal
 */
final class KalkulasiMurniTest extends CIUnitTestCase
{
    /**
     * Folder kelas murni → jumlah berkas minimum (penjaga tidak boleh lolos karena folder kosong/salah path).
     */
    private const FOLDER_MURNI = [
        'Kalkulasi' => 10, // CR-025 (9) + CR-036 Pensiun
        'Nip'       => 5,  // CR-036
    ];

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function folderMurni(): iterable
    {
        foreach (self::FOLDER_MURNI as $folder => $minimum) {
            yield $folder => [$folder, $minimum];
        }
    }

    #[DataProvider('folderMurni')]
    public function testSemuaKelasKalkulasiMurni(string $folder, int $minimum): void
    {
        $hasil = self::pemindai()->pindaiFolder(APPPATH . "Libraries/Kepegawaian/{$folder}");

        $this->assertGreaterThanOrEqual($minimum, $hasil['jumlahBerkas'], "Kelas murni di {$folder}/ tidak ditemukan");
        $this->assertSame([], $hasil['pelanggaran']);
    }

    public function testPengecualianHanyaTimeNowDiHariIniTanggalBisnis(): void
    {
        $pemindai = self::pemindai();

        $this->assertSame([], $pemindai->pindai("<?php\nuse CodeIgniter\\I18n\\Time;\nfunction hariIni(\$s = null) { return Time::now(); }", 'TanggalBisnis.php'));
        $this->assertSame([], $pemindai->pindai("<?php\nfunction hariIni(\$s = null) { return \\CodeIgniter\\I18n\\Time::now(); }", 'TanggalBisnis.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\nfunction lain() { return Time::now(); }", 'TanggalBisnis.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\nfunction hariIni(\$s = null) { return Time::now(); }", 'Pensiun.php'));
        $this->assertNotSame([], $pemindai->pindai("<?php\n\$d = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$t);", 'Pensiun.php'));
    }

    private static function pemindai(): PemindaiKemurnian
    {
        return new PemindaiKemurnian(izinTime: [['berkas' => 'TanggalBisnis.php', 'fungsi' => 'hariIni', 'method' => 'now']]);
    }
}
