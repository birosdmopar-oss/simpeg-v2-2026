<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Libraries\Kepegawaian\Kalkulasi\JenisReferensiTmt;
use App\Libraries\Kepegawaian\Kalkulasi\KenaikanGajiBerkala;
use App\Libraries\Kepegawaian\Kalkulasi\ReferensiTmt;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-025 (B-21 "kalkulasi jarak KGB 2 tahun") — jarak minimal 2 tahun dari TMT KGB/KP terakhir (SRS-FR-026) dan proyeksi
 * KGB berikutnya setara legacy `next_kgb` (`L_employee.php:4023-4045`) dengan deviasi K-CR025-8.
 *
 * @internal
 */
final class KenaikanGajiBerkalaTest extends CIUnitTestCase
{
    private const HARI_INI = '2026-09-30';

    private static function kgb(string $tmt): ReferensiTmt
    {
        return new ReferensiTmt(JenisReferensiTmt::Kgb, $tmt);
    }

    private static function kp(string $tmt): ReferensiTmt
    {
        return new ReferensiTmt(JenisReferensiTmt::Kp, $tmt);
    }

    public function testTepatDuaTahunDariKgbValid(): void
    {
        $hasil = KenaikanGajiBerkala::validasiJarak('2026-04-01', [self::kgb('2024-04-01')]);

        $this->assertTrue($hasil->valid);
        $this->assertSame('2026-04-01', $hasil->tanggal);
        $this->assertSame(
            ['referensi_jenis' => 'kgb', 'referensi_tmt' => '2024-04-01', 'batas_minimal' => '2026-04-01'],
            $hasil->detail,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kurangDariDuaTahunProvider(): iterable
    {
        yield 'kurang sehari' => ['2026-03-31'];
        yield 'kurang sebulan' => ['2026-03-01'];
        yield 'TMT sama dengan acuan' => ['2024-04-01'];
    }

    #[DataProvider('kurangDariDuaTahunProvider')]
    public function testKurangDariDuaTahunDitolak(string $tmtBaru): void
    {
        $hasil = KenaikanGajiBerkala::validasiJarak($tmtBaru, [self::kgb('2024-04-01')]);

        $this->assertFalse($hasil->valid);
        $this->assertSame('jarak_kgb_kurang_dari_2_tahun', $hasil->kode);
        $this->assertSame(
            'TMT KGB minimal 2 tahun dari TMT KGB terakhir (1 April 2024). TMT KGB paling cepat 1 April 2026.',
            $hasil->pesan,
        );
        $this->assertSame('2026-04-01', $hasil->detail['batas_minimal']);
    }

    public function testKpLebihBaruMenjadiAcuan(): void
    {
        $hasil = KenaikanGajiBerkala::validasiJarak('2026-04-01', [self::kgb('2024-04-01'), self::kp('2025-04-01')]);

        $this->assertFalse($hasil->valid);
        $this->assertSame(
            'TMT KGB minimal 2 tahun dari TMT KP terakhir (1 April 2025). TMT KGB paling cepat 1 April 2027.',
            $hasil->pesan,
        );
        $this->assertSame(
            ['referensi_jenis' => 'kp', 'referensi_tmt' => '2025-04-01', 'batas_minimal' => '2027-04-01'],
            $hasil->detail,
        );
    }

    public function testTmtSamaAntarAcuanMemilihKgb(): void
    {
        foreach ([[self::kp('2024-04-01'), self::kgb('2024-04-01')], [self::kgb('2024-04-01'), self::kp('2024-04-01')]] as $refs) {
            $terpilih = KenaikanGajiBerkala::referensiTerakhir('2026-04-01', $refs);

            $this->assertNotNull($terpilih);
            $this->assertSame(JenisReferensiTmt::Kgb, $terpilih->jenis);
            $this->assertTrue(KenaikanGajiBerkala::validasiJarak('2026-04-01', $refs)->valid);
        }

        $cpnsDanKp = [new ReferensiTmt(JenisReferensiTmt::Cpns, '2020-03-01'), self::kp('2020-03-01')];
        $this->assertSame(JenisReferensiTmt::Kp, KenaikanGajiBerkala::referensiTerakhir('2022-03-01', $cpnsDanKp)?->jenis);

        $cpnsDanPppk = [new ReferensiTmt(JenisReferensiTmt::AwalPppk, '2020-03-01'), new ReferensiTmt(JenisReferensiTmt::Cpns, '2020-03-01')];
        $this->assertSame(JenisReferensiTmt::Cpns, KenaikanGajiBerkala::referensiTerakhir('2022-03-01', $cpnsDanPppk)?->jenis);
    }

    public function testBatasDari29FebruariDijepit(): void
    {
        $refs = [self::kgb('2024-02-29')];

        $this->assertTrue(KenaikanGajiBerkala::validasiJarak('2026-02-28', $refs)->valid);

        $hasil = KenaikanGajiBerkala::validasiJarak('2026-02-27', $refs);
        $this->assertFalse($hasil->valid);
        $this->assertSame('2026-02-28', $hasil->detail['batas_minimal']);
    }

    public function testBatasDariAkhirBulan(): void
    {
        $refs = [self::kgb('2024-08-31')];

        $this->assertFalse(KenaikanGajiBerkala::validasiJarak('2026-08-30', $refs)->valid);
        $this->assertTrue(KenaikanGajiBerkala::validasiJarak('2026-08-31', $refs)->valid);
    }

    public function testRiwayatLamaMemakaiPendahulu(): void
    {
        $refs = [self::kgb('2020-04-01'), self::kgb('2024-04-01')];

        $sela = KenaikanGajiBerkala::validasiJarak('2022-04-01', $refs);
        $this->assertTrue($sela->valid);
        $this->assertSame('2020-04-01', $sela->detail['referensi_tmt']);

        $terlaluDekat = KenaikanGajiBerkala::validasiJarak('2021-04-01', $refs);
        $this->assertFalse($terlaluDekat->valid);
        $this->assertSame('2020-04-01', $terlaluDekat->detail['referensi_tmt']);
        $this->assertSame('2022-04-01', $terlaluDekat->detail['batas_minimal']);
    }

    public function testTanpaPendahuluValid(): void
    {
        $kosong = ['referensi_jenis' => null, 'referensi_tmt' => null, 'batas_minimal' => null];

        $tanpaRef = KenaikanGajiBerkala::validasiJarak('2026-04-01', []);
        $this->assertTrue($tanpaRef->valid);
        $this->assertSame($kosong, $tanpaRef->detail);

        $semuaSetelahnya = KenaikanGajiBerkala::validasiJarak('2019-04-01', [self::kgb('2020-04-01'), self::kp('2021-04-01')]);
        $this->assertTrue($semuaSetelahnya->valid);
        $this->assertSame($kosong, $semuaSetelahnya->detail);
        $this->assertNull(KenaikanGajiBerkala::referensiTerakhir('2019-04-01', [self::kgb('2020-04-01')]));
    }

    public function testAcuanCpns(): void
    {
        $refs = [new ReferensiTmt(JenisReferensiTmt::Cpns, '2020-03-01')];

        $this->assertTrue(KenaikanGajiBerkala::validasiJarak('2022-03-01', $refs)->valid);

        $hasil = KenaikanGajiBerkala::validasiJarak('2021-03-01', $refs);
        $this->assertFalse($hasil->valid);
        $this->assertSame(
            'TMT KGB minimal 2 tahun dari TMT CPNS (1 Maret 2020). TMT KGB paling cepat 1 Maret 2022.',
            $hasil->pesan,
        );
    }

    public function testAcuanAwalPppk(): void
    {
        $hasil = KenaikanGajiBerkala::validasiJarak('2025-01-01', [new ReferensiTmt(JenisReferensiTmt::AwalPppk, '2024-01-01')]);

        $this->assertSame(
            'TMT KGB minimal 2 tahun dari TMT awal PPPK (1 Januari 2024). TMT KGB paling cepat 1 Januari 2026.',
            $hasil->pesan,
        );
        $this->assertSame('awal_pppk', $hasil->detail['referensi_jenis']);
    }

    public function testTmtBaruTidakValidGagal(): void
    {
        $refs = [self::kgb('2024-04-01')];

        $this->assertSame('tanggal_tidak_valid', KenaikanGajiBerkala::validasiJarak('2026-02-30', $refs)->kode);
        $this->assertSame('tanggal_tidak_valid', KenaikanGajiBerkala::validasiJarak('01-04-2026', $refs)->kode);

        $kosong = KenaikanGajiBerkala::validasiJarak('', $refs);
        $this->assertSame('tanggal_wajib', $kosong->kode);
        $this->assertSame('TMT KGB wajib diisi.', $kosong->pesan);
    }

    public function testAcuanTidakValidFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::kgb('0000-00-00');
    }

    public function testJatuhTempo(): void
    {
        $this->assertSame('2026-04-01', KenaikanGajiBerkala::jatuhTempo('2024-04-01'));
        $this->assertSame('2026-02-28', KenaikanGajiBerkala::jatuhTempo('2024-02-29'));

        $this->expectException(InvalidArgumentException::class);
        KenaikanGajiBerkala::jatuhTempo('2024-02-30');
    }

    /**
     * Hari ini 30 September 2026. Kolom legacy = hasil `next_kgb` (dibandingkan pada jam kerja) bila berbeda.
     *
     * @return iterable<string, array{string, string, string, int, int}>
     */
    public static function proyeksiProvider(): iterable
    {
        yield '17 bulan' => ['2025-04-01', '2027-04-01', 'belum_jatuh_tempo', 1, 5];
        yield '23 bulan' => ['2024-10-01', '2026-10-01', 'belum_jatuh_tempo', 1, 11];
        yield 'tepat 24 bulan' => ['2024-09-30', '2026-09-30', 'jatuh_tempo', 2, 0];
        yield '2 th 5 bl' => ['2024-04-01', '2026-09-30', 'jatuh_tempo', 2, 5];
        yield 'tepat 36 bulan' => ['2023-09-30', '2026-09-30', 'jatuh_tempo', 3, 0];
        yield '37 bulan' => ['2023-08-30', '2027-08-30', 'siklus_terlewat', 3, 1];
        yield '4 th 5 bl' => ['2022-04-01', '2028-04-01', 'siklus_terlewat', 4, 5];
        yield '8 th 5 bl' => ['2018-04-01', '2028-04-01', 'siklus_terlewat', 8, 5];
        // Deviasi K-CR025-8(i): acuan + 4 tahun = hari ini → hari ini (legacy meloncat ke 2028-09-30 karena jam).
        yield 'acuan + 4 th = hari ini' => ['2022-09-30', '2026-09-30', 'siklus_terlewat', 4, 0];
        // Deviasi K-CR025-8(ii): acuan di masa depan → acuan + 2 tahun, masa 0/0.
        yield 'acuan di masa depan' => ['2026-10-01', '2028-10-01', 'belum_jatuh_tempo', 0, 0];
    }

    #[DataProvider('proyeksiProvider')]
    public function testProyeksiBerikutnya(string $acuan, string $harapan, string $status, int $masaTahun, int $masaBulan): void
    {
        $hasil = KenaikanGajiBerkala::proyeksiBerikutnya($acuan, self::HARI_INI);

        $this->assertTrue($hasil->valid);
        $this->assertSame($harapan, $hasil->tanggal);
        $this->assertSame([
            'status'           => $status,
            'jatuh_tempo_awal' => KenaikanGajiBerkala::jatuhTempo($acuan),
            'masa_tahun'       => $masaTahun,
            'masa_bulan'       => $masaBulan,
        ], $hasil->detail);
    }

    public function testProyeksiSiklusDariAcuanAsli29Februari(): void
    {
        // Siklus dihitung dari acuan asli: 2024-02-29 + 6 tahun = 2030-02-28, + 8 tahun = 2032-02-29 (bukan 02-28).
        $this->assertSame('2030-02-28', KenaikanGajiBerkala::proyeksiBerikutnya('2024-02-29', '2030-01-15')->tanggal);
        $this->assertSame('2032-02-29', KenaikanGajiBerkala::proyeksiBerikutnya('2024-02-29', '2030-03-01')->tanggal);
    }

    public function testProyeksiHariIniTidakValidFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KenaikanGajiBerkala::proyeksiBerikutnya('2024-04-01', '2026-09-31');
    }

    public function testProyeksiAcuanTidakValidFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KenaikanGajiBerkala::proyeksiBerikutnya('', self::HARI_INI);
    }
}
