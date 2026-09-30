<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Libraries\Kepegawaian\Kalkulasi\JenisKp;
use App\Libraries\Kepegawaian\Kalkulasi\KenaikanPangkat;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-025 (B-21 "kalkulasi periode KP (kelipatan April/Oktober)") — periode 1 April/1 Oktober (SRS-FR-025), validasi TMT
 * KP, proyeksi KP reguler setara legacy `next_kp` (`L_employee.php:1987-1998`) dan percepatan (:2005-2041).
 *
 * @internal
 */
final class KenaikanPangkatTest extends CIUnitTestCase
{
    public function testIsTanggalPeriode(): void
    {
        $this->assertTrue(KenaikanPangkat::isTanggalPeriode('2026-04-01'));
        $this->assertTrue(KenaikanPangkat::isTanggalPeriode('2026-10-01'));

        foreach (['2026-04-02', '2026-03-31', '2026-10-31', '2026-02-01', '2026-06-01', '2024-02-29', '2026-04-31', ''] as $t) {
            $this->assertFalse(KenaikanPangkat::isTanggalPeriode($t), var_export($t, true));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function periodeBerikutnyaProvider(): iterable
    {
        yield 'tepat periode April' => ['2026-04-01', '2026-04-01'];
        yield 'tepat periode Oktober' => ['2026-10-01', '2026-10-01'];
        yield 'sehari setelah April' => ['2026-04-02', '2026-10-01'];
        yield 'sehari setelah Oktober' => ['2026-10-02', '2027-04-01'];
        yield 'akhir tahun' => ['2026-12-31', '2027-04-01'];
        yield 'awal tahun' => ['2026-01-01', '2026-04-01'];
        yield '29 Februari' => ['2028-02-29', '2028-04-01'];
    }

    #[DataProvider('periodeBerikutnyaProvider')]
    public function testPeriodeBerikutnya(string $tanggal, string $harapan): void
    {
        $this->assertSame($harapan, KenaikanPangkat::periodeBerikutnya($tanggal));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function periodeSebelumnyaProvider(): iterable
    {
        yield 'pertengahan April' => ['2026-04-15', '2026-04-01'];
        yield 'tepat periode' => ['2026-10-01', '2026-10-01'];
        yield 'Desember' => ['2026-12-01', '2026-10-01'];
        yield 'awal tahun' => ['2026-01-01', '2025-10-01'];
        yield '29 Februari' => ['2028-02-29', '2027-10-01'];
        yield 'sehari sebelum April' => ['2026-03-31', '2025-10-01'];
    }

    #[DataProvider('periodeSebelumnyaProvider')]
    public function testPeriodeSebelumnya(string $tanggal, string $harapan): void
    {
        $this->assertSame($harapan, KenaikanPangkat::periodeSebelumnya($tanggal));
    }

    public function testValidasiTmtPeriodeValid(): void
    {
        foreach (['2026-04-01', '2026-10-01'] as $tmt) {
            $hasil = KenaikanPangkat::validasiTmt($tmt);

            $this->assertTrue($hasil->valid, $tmt);
            $this->assertSame($tmt, $hasil->tanggal);
            $this->assertNull($hasil->kode);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function tmtBukanPeriodeProvider(): iterable
    {
        yield 'pertengahan April' => [
            '2026-04-15', '2026-04-01', '2026-10-01',
            'TMT kenaikan pangkat harus tanggal 1 April atau 1 Oktober. Periode terdekat: 1 April 2026 atau 1 Oktober 2026.',
        ];
        yield 'Desember' => [
            '2026-12-01', '2026-10-01', '2027-04-01',
            'TMT kenaikan pangkat harus tanggal 1 April atau 1 Oktober. Periode terdekat: 1 Oktober 2026 atau 1 April 2027.',
        ];
        yield '29 Februari' => [
            '2028-02-29', '2027-10-01', '2028-04-01',
            'TMT kenaikan pangkat harus tanggal 1 April atau 1 Oktober. Periode terdekat: 1 Oktober 2027 atau 1 April 2028.',
        ];
    }

    #[DataProvider('tmtBukanPeriodeProvider')]
    public function testValidasiTmtBukanPeriodeDitolak(string $tmt, string $sebelumnya, string $berikutnya, string $pesan): void
    {
        $hasil = KenaikanPangkat::validasiTmt($tmt);

        $this->assertFalse($hasil->valid);
        $this->assertNull($hasil->tanggal);
        $this->assertSame('tmt_kp_bukan_periode', $hasil->kode);
        $this->assertSame($pesan, $hasil->pesan);
        $this->assertSame(['periode_sebelumnya' => $sebelumnya, 'periode_berikutnya' => $berikutnya], $hasil->detail);
    }

    public function testValidasiTmtFormat(): void
    {
        $this->assertSame('tanggal_tidak_valid', KenaikanPangkat::validasiTmt('2026-04-31')->kode);
        $this->assertSame('tanggal_tidak_valid', KenaikanPangkat::validasiTmt('01-04-2026')->kode);
        $this->assertSame('tanggal_wajib', KenaikanPangkat::validasiTmt('')->kode);
        $this->assertSame('TMT KP wajib diisi.', KenaikanPangkat::validasiTmt('')->pesan);
        $this->assertSame('tanggal_tidak_valid', KenaikanPangkat::validasiTmt('2026-04-31', false)->kode);
    }

    public function testValidasiTmtTanpaKewajibanPeriodeUntukPengangkatan(): void
    {
        // Bukti legacy TMT CPNS 1 Maret (controllers/Tester.php:4283).
        $hasil = KenaikanPangkat::validasiTmt('2022-03-01', JenisKp::wajibTmtPeriode(JenisKp::PENGANGKATAN_CPNS));

        $this->assertTrue($hasil->valid);
        $this->assertSame('2022-03-01', $hasil->tanggal);

        $this->assertFalse(KenaikanPangkat::validasiTmt('2022-03-01', JenisKp::wajibTmtPeriode(JenisKp::REGULER))->valid);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function proyeksiRegulerProvider(): iterable
    {
        yield 'April + 4' => ['2022-04-01', 4, '2026-04-01'];
        yield 'Oktober + 4' => ['2022-10-01', 4, '2026-10-01'];
        yield 'pertengahan April (aturan bulan legacy)' => ['2022-04-15', 4, '2026-04-01'];
        yield 'Mei → Oktober' => ['2022-05-01', 4, '2026-10-01'];
        yield 'November → April tahun berikutnya' => ['2022-11-01', 4, '2027-04-01'];
        yield 'akhir Desember' => ['2022-12-31', 4, '2027-04-01'];
        yield 'setelah Pengangkatan PNS (3 tahun)' => ['2023-03-01', 3, '2026-04-01'];
        yield '29 Februari' => ['2024-02-29', 4, '2028-04-01'];
    }

    #[DataProvider('proyeksiRegulerProvider')]
    public function testProyeksiRegulerSetaraLegacy(string $tmt, int $masa, string $harapan): void
    {
        $hasil = KenaikanPangkat::proyeksiReguler($tmt, $masa);

        $this->assertTrue($hasil->valid);
        $this->assertSame($harapan, $hasil->tanggal);
        $this->assertSame(['masa_tahun' => $masa], $hasil->detail);
        $this->assertSame($harapan, self::legacyNextKp($tmt, $masa), 'pembanding legacy L_employee.php:1987-1998');
    }

    public function testProyeksiRegulerRantaiKelipatan(): void
    {
        $pertama = KenaikanPangkat::proyeksiReguler('2018-10-01')->tanggal;
        $this->assertSame('2022-10-01', $pertama);
        $this->assertSame('2026-10-01', KenaikanPangkat::proyeksiReguler((string) $pertama)->tanggal);
    }

    public function testProyeksiRegulerMasaDefaultEmpatTahun(): void
    {
        $this->assertSame('2026-04-01', KenaikanPangkat::proyeksiReguler('2022-04-01')->tanggal);
    }

    public function testProyeksiRegulerTmtTidakValidGagal(): void
    {
        $hasil = KenaikanPangkat::proyeksiReguler('2022-02-30');

        $this->assertFalse($hasil->valid);
        $this->assertSame('tanggal_tidak_valid', $hasil->kode);
        $this->assertStringStartsWith('TMT KP terakhir tidak valid.', (string) $hasil->pesan);
    }

    public function testProyeksiRegulerMasaNolDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KenaikanPangkat::proyeksiReguler('2022-04-01', 0);
    }

    public function testProyeksiRegulerMasaNegatifDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KenaikanPangkat::proyeksiReguler('2022-04-01', -4);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function proyeksiPercepatanProvider(): iterable
    {
        yield 'sebelum April' => ['2025-03-15', '2026-04-01'];
        yield 'tepat 1 April (≤ legacy)' => ['2025-04-01', '2026-04-01'];
        yield 'sehari setelah April' => ['2025-04-02', '2026-10-01'];
        yield 'setelah Oktober' => ['2025-10-02', '2027-04-01'];
        yield '29 Februari' => ['2024-02-29', '2025-04-01'];
    }

    #[DataProvider('proyeksiPercepatanProvider')]
    public function testProyeksiPercepatan(string $tmtJabatan, string $harapan): void
    {
        $hasil = KenaikanPangkat::proyeksiPercepatan($tmtJabatan);

        $this->assertTrue($hasil->valid);
        $this->assertSame($harapan, $hasil->tanggal);
        $this->assertSame(['masa_tahun' => KenaikanPangkat::MASA_PERCEPATAN_TAHUN], $hasil->detail);
    }

    public function testProyeksiPercepatanTmtTidakValidGagal(): void
    {
        $this->assertSame('tanggal_wajib', KenaikanPangkat::proyeksiPercepatan('')->kode);
    }

    public function testJenisKpWajibTmtPeriode(): void
    {
        $this->assertFalse(JenisKp::wajibTmtPeriode(JenisKp::PENGANGKATAN_CPNS));
        $this->assertFalse(JenisKp::wajibTmtPeriode(JenisKp::PENGANGKATAN_PNS));
        $this->assertTrue(JenisKp::wajibTmtPeriode(JenisKp::REGULER));
        $this->assertTrue(JenisKp::wajibTmtPeriode(JenisKp::PILIHAN));
        $this->assertTrue(JenisKp::wajibTmtPeriode(JenisKp::PENYESUAIAN_IJAZAH));
        $this->assertTrue(JenisKp::wajibTmtPeriode(6));
    }

    public function testJenisKpIdLegacy(): void
    {
        $this->assertSame(
            [1, 2, 3, 4, 5],
            [JenisKp::PENGANGKATAN_CPNS, JenisKp::PENGANGKATAN_PNS, JenisKp::REGULER, JenisKp::PILIHAN, JenisKp::PENYESUAIAN_IJAZAH],
        );
    }

    public function testJenisKpMasaRegulerBerikutnya(): void
    {
        $this->assertSame(3, JenisKp::masaRegulerBerikutnya(JenisKp::PENGANGKATAN_PNS));

        foreach ([JenisKp::PENGANGKATAN_CPNS, JenisKp::REGULER, JenisKp::PILIHAN, JenisKp::PENYESUAIAN_IJAZAH, null] as $id) {
            $this->assertSame(4, JenisKp::masaRegulerBerikutnya($id), var_export($id, true));
        }
    }

    /**
     * Pembanding: aturan bulan legacy `next_kp` (`L_employee.php:1988-1998`) ditulis ulang tanpa `strtotime`.
     */
    private static function legacyNextKp(string $tmt, int $masa): string
    {
        $tahun = (int) substr($tmt, 0, 4);
        $bulan = (int) substr($tmt, 5, 2);

        if ($bulan <= 4) {
            return ($tahun + $masa) . '-04-01';
        }

        if ($bulan <= 10) {
            return ($tahun + $masa) . '-10-01';
        }

        return ($tahun + $masa + 1) . '-04-01';
    }
}
