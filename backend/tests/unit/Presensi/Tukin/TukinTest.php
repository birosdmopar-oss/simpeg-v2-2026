<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\JamKerja;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\Tukin\PotonganCutiPeriode;
use App\Libraries\Presensi\Tukin\StatusHarian;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-037/CR-039 — kalkulasi Tukin murni mengikuti rekap legacy `laporan_tukin_us_skp` (`L_presensi.php:11752`).
 * Data sintetis. Tarif `web_config` uji sengaja berbeda-beda (persen: TL1/PSW1 = 0,5, TL2/PSW2 = 1, TL3/PSW3 = 1,25,
 * TA = 1,5, TK = 3) sehingga setelah faktor presensi 0,2 (:12854 dst.) potongan harian dalam basis poin adalah
 * TL1 = 10, TL2 = 20, TL3 = 25, TA = 30, TK = 60, dan cuti sakit = 20 (1% × 0,2, :12799).
 *
 * Kalender: 16 Des 2025 = Selasa; 5 Jan 2026 = Senin; 16 Jan 2026 = Jumat; 16 Feb 2026 = Senin; 13 Mar 2026 = Jumat.
 * Periode Februari 2026 (16 Jan – 15 Feb) punya 21 hari kerja; periode Maret (16 Feb – 15 Mar) 20 hari kerja.
 *
 * @internal
 */
final class TukinTest extends CIUnitTestCase
{
    private const SENIN = '2026-01-05';
    private const JUMAT = '2026-01-09';
    private const TL1   = 10;
    private const TL2   = 20;
    private const TL3   = 25;
    private const TA    = 30;
    private const TK    = 60;
    private const CS    = 20;

    /**
     * @param array<string, int|string> $tambahan
     */
    private function config(array $tambahan = [], ?string $puasaMulai = null, ?string $puasaSelesai = null): KonfigurasiTukin
    {
        return new KonfigurasiTukin(array_merge([
            'TL1/PSW1' => '0.5',
            'TL2/PSW2' => 1,
            'TL3/PSW3' => '1.25',
            'TA'       => '1,5',
            'TK'       => 3,
        ], $tambahan), puasaMulai: $puasaMulai, puasaSelesai: $puasaSelesai);
    }

    /**
     * Input harian yang sama untuk setiap hari kerja dalam rentang.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $libur
     *
     * @return array<string, array<string, mixed>>
     */
    private static function isi(string $awal, string $akhir, array $input, array $libur = []): array
    {
        return array_fill_keys(HariKerja::daftar(new PeriodeTukin($awal, $akhir), $libur), $input);
    }

    /** Pegawai dengan predikat SKP "baik" (potongan SKP 0) agar total sama dengan total presensi. */
    private static function baik(?string $tmtMasuk = null, ?string $tmtKeluar = null): DataPegawaiTukin
    {
        return new DataPegawaiTukin('baik', tmtMasuk: $tmtMasuk, tmtKeluar: $tmtKeluar);
    }

    private static function jam(int $menitDariTengahMalam): string
    {
        return sprintf('%02d:%02d', intdiv($menitDariTengahMalam, 60), $menitDariTengahMalam % 60);
    }

    // ---------------------------------------------------------------- periode & hari kerja

    public function testPeriodeDefaultLintasTahun(): void
    {
        $januari = PeriodeTukin::dariBulan(2026, 1);
        $this->assertSame(['2025-12-16', '2026-01-15'], [$januari->awal, $januari->akhir]);

        $maret = PeriodeTukin::dariBulan(2026, 3);
        $this->assertSame(['2026-02-16', '2026-03-15'], [$maret->awal, $maret->akhir]);
    }

    public function testPeriodeBknDiinjeksikanDanDicocokkanDenganBulan(): void
    {
        $bkn = PeriodeTukin::dariBulan(2026, 2, ['awal' => '2026-01-19', 'akhir' => '2026-02-13']);
        $this->assertSame(['2026-01-19', '2026-02-13'], [$bkn->awal, $bkn->akhir]);

        // Januari: awal selalu 16 Desember tahun lalu walau ada baris BKN (legacy :11859-11862).
        $januari = PeriodeTukin::dariBulan(2026, 1, ['awal' => '2026-01-02', 'akhir' => '2026-01-14']);
        $this->assertSame(['2025-12-16', '2026-01-14'], [$januari->awal, $januari->akhir]);

        $this->expectException(InvalidArgumentException::class);
        PeriodeTukin::dariBulan(2026, 3, ['awal' => '2026-01-19', 'akhir' => '2026-02-13']);
    }

    public function testPeriodeBulanTidakValid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodeTukin::dariBulan(2026, 13);
    }

    public function testLiburDanAkhirPekanTidakDihitungGanda(): void
    {
        // 1 Jan (Kamis) libur, 3 Jan (Sabtu) libur di akhir pekan.
        $hari = HariKerja::daftar(new PeriodeTukin('2026-01-01', '2026-01-11'), ['2026-01-01', '2026-01-03']);
        $this->assertSame(['2026-01-02', '2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09'], $hari);

        $hasil = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-01', '2026-01-11'), ['2026-01-01', '2026-01-03'], [], $this->config(), pegawai: self::baik());
        $this->assertSame(6, $hasil->hariKerja);
        $this->assertSame(6, $hasil->jumlah['TK']);
        $this->assertSame(6 * self::TK, $hasil->rincian['TK']);
        $this->assertSame(6 * self::TK, $hasil->totalPotongan);
    }

    public function testHariIniMemotongAkhirPeriode(): void
    {
        $periode = new PeriodeTukin('2026-01-05', '2026-01-09');

        $sebagian = KalkulasiTukin::hitung($periode, [], [], $this->config(), '2026-01-06', self::baik());
        $this->assertSame('2026-01-06', $sebagian->akhirDihitung);
        $this->assertSame(2, $sebagian->hariKerja);
        $this->assertSame(2 * self::TK, $sebagian->totalPotongan);

        $this->assertSame(5, KalkulasiTukin::hitung($periode, [], [], $this->config(), '2026-02-01')->hariKerja);
        $this->assertSame(0, KalkulasiTukin::hitung($periode, [], [], $this->config(), '2026-01-04')->hariKerja);
    }

    // ---------------------------------------------------------------- jam kerja

    public function testEmpatKombinasiJamKerjaDanBatasRentangPuasa(): void
    {
        $config = $this->config([], '2026-02-19', '2026-03-20');

        // Normal biasa / normal Jumat.
        $this->assertSame(['masuk' => '07:30:00', 'pulang' => '16:00:00'], JamKerja::untuk('2026-02-18', $config));
        $this->assertSame(['masuk' => '07:30:00', 'pulang' => '16:30:00'], JamKerja::untuk('2026-02-13', $config));
        // Hari pertama puasa (Kamis) dan Jumat puasa.
        $this->assertSame(['masuk' => '08:00:00', 'pulang' => '15:00:00'], JamKerja::untuk('2026-02-19', $config));
        $this->assertSame(['masuk' => '08:00:00', 'pulang' => '15:30:00'], JamKerja::untuk('2026-02-20', $config));
        // Hari terakhir puasa (Jumat) inklusif, lalu normal kembali.
        $this->assertSame(['masuk' => '08:00:00', 'pulang' => '15:30:00'], JamKerja::untuk('2026-03-20', $config));
        $this->assertSame(['masuk' => '07:30:00', 'pulang' => '16:00:00'], JamKerja::untuk('2026-03-23', $config));
        // Tanpa rentang puasa = jam normal.
        $this->assertSame(['masuk' => '07:30:00', 'pulang' => '16:00:00'], JamKerja::untuk('2026-02-19', $this->config()));

        // Dampak ke potongan: datang 08:00 pada hari pertama puasa tepat waktu, sehari sebelumnya TL1.
        $hadir = ['presensi' => ['masuk' => '08:00', 'pulang' => '16:00']];
        $this->assertSame(0, StatusHarian::evaluasi('2026-02-19', $hadir, $config)->potongan);
        $this->assertSame(self::TL1, StatusHarian::evaluasi('2026-02-18', $hadir, $config)->potongan);
    }

    public function testJamKonfigurasiDenganDetik(): void
    {
        $config = new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5], jamMasukNormal: '07:45:00');
        $this->assertSame('07:45:00', JamKerja::untuk(self::SENIN, $config)['masuk']);

        $this->expectException(InvalidArgumentException::class);
        new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5], jamMasukNormal: '24:00');
    }

    // ---------------------------------------------------------------- TL / PSW

    /**
     * @dataProvider batasTlProvider
     *
     * @param list<string> $kategori
     */
    public function testBatasTl(int $menit, int $potongan, array $kategori): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => self::jam(450 + $menit), 'pulang' => '16:00']], $this->config());
        $this->assertSame($menit, $hasil->tlMenit);
        $this->assertSame($potongan, $hasil->potongan);
        $this->assertSame($kategori, $hasil->kategori);
    }

    /**
     * @return array<string, array{int, int, list<string>}>
     */
    public static function batasTlProvider(): array
    {
        return [
            'tepat waktu' => [0, 0, []],
            '1 menit'     => [1, self::TL1, ['TL1']],
            '30 menit'    => [30, self::TL1, ['TL1']],
            '31 menit'    => [31, self::TL2, ['TL2']],
            '60 menit'    => [60, self::TL2, ['TL2']],
            '61 menit'    => [61, self::TL3, ['TL3']],
        ];
    }

    /**
     * @dataProvider batasPswProvider
     *
     * @param list<string> $kategori
     */
    public function testBatasPswTermasukJumat(string $tanggal, int $menit, int $potongan, array $kategori): void
    {
        $standar = $tanggal === self::JUMAT ? 16 * 60 + 30 : 16 * 60;
        $hasil   = StatusHarian::evaluasi($tanggal, ['presensi' => ['masuk' => '07:30', 'pulang' => self::jam($standar - $menit)]], $this->config());
        $this->assertSame($menit, $hasil->pswMenit);
        $this->assertSame($potongan, $hasil->potongan);
        $this->assertSame($kategori, $hasil->kategori);
    }

    /**
     * @return array<string, array{string, int, int, list<string>}>
     */
    public static function batasPswProvider(): array
    {
        $hasil = [];

        foreach (['senin' => self::SENIN, 'jumat' => self::JUMAT] as $nama => $tanggal) {
            $hasil["{$nama} 0"]  = [$tanggal, 0, 0, []];
            $hasil["{$nama} 30"] = [$tanggal, 30, self::TL1, ['PSW1']];
            $hasil["{$nama} 31"] = [$tanggal, 31, self::TL2, ['PSW2']];
            $hasil["{$nama} 60"] = [$tanggal, 60, self::TL2, ['PSW2']];
            $hasil["{$nama} 61"] = [$tanggal, 61, self::TL3, ['PSW3']];
        }

        return $hasil;
    }

    public function testPulangJumatJam1600AdalahPsw30(): void
    {
        $hasil = StatusHarian::evaluasi(self::JUMAT, ['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']], $this->config());
        $this->assertSame(['PSW1'], $hasil->kategori);
    }

    public function testDetikPresensiDibuangSepertiDateIntervalLegacy(): void
    {
        $config = $this->config();
        $this->assertSame(0, StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => '07:30:59', 'pulang' => '16:00:00']], $config)->potongan);
        $this->assertSame(30, StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => '08:00:59', 'pulang' => '16:00']], $config)->tlMenit);
        $this->assertSame(31, StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => '08:01:00', 'pulang' => '16:00']], $config)->tlMenit);
        // Pulang 15:29:30 = 30 menit 30 detik lebih awal → 30 menit (PSW1), bukan 31.
        $psw = StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => '07:30', 'pulang' => '15:29:30']], $config);
        $this->assertSame(30, $psw->pswMenit);
        $this->assertSame(['PSW1'], $psw->kategori);
    }

    public function testDendaSatuArah(): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => '07:00', 'pulang' => '17:00']], $this->config());
        $this->assertSame(-30, $hasil->tlMenit);
        $this->assertSame(-60, $hasil->pswMenit);
        $this->assertSame(0, $hasil->potongan);
        $this->assertSame([], $hasil->kategori);
    }

    /**
     * @dataProvider kompensasiProvider
     *
     * @param list<string> $kategori
     */
    public function testKompensasiTlOlehJamPulang(string $masuk, string $pulang, int $potongan, array $kategori): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, ['presensi' => ['masuk' => $masuk, 'pulang' => $pulang]], $this->config());
        $this->assertSame($potongan, $hasil->potongan);
        $this->assertSame($kategori, $hasil->kategori);
    }

    /**
     * @return array<string, array{string, string, int, list<string>}>
     */
    public static function kompensasiProvider(): array
    {
        return [
            'TL1 gugur, pulang +30'       => ['08:00', '16:30', 0, []],
            'TL1 tetap, pulang +29'       => ['08:00', '16:29', self::TL1, ['TL1']],
            'TL2 gugur, pulang +60'       => ['08:30', '17:00', 0, []],
            'TL2 tetap, pulang +59'       => ['08:30', '16:59', self::TL2, ['TL2']],
            'TL2 tidak gugur oleh +30'    => ['08:01', '16:30', self::TL2, ['TL2']],
            'TL3 tidak pernah gugur'      => ['08:31', '18:00', self::TL3, ['TL3']],
            'TL1 + PSW1 tanpa kompensasi' => ['07:31', '15:59', self::TL1 + self::TL1, ['TL1', 'PSW1']],
        ];
    }

    // ---------------------------------------------------------------- TPM / TPP / TK

    /**
     * @dataProvider tpmTppProvider
     *
     * @param array<string, string|null> $presensi
     * @param list<string>               $kategori
     */
    public function testNominalTpmTpp(array $presensi, string $status, int $potongan, array $kategori): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, ['presensi' => $presensi], $this->config());
        $this->assertSame($status, $hasil->status);
        $this->assertSame($potongan, $hasil->potongan);
        $this->assertSame($kategori, $hasil->kategori);
    }

    /**
     * @return array<string, array{array<string, string|null>, string, int, list<string>}>
     */
    public static function tpmTppProvider(): array
    {
        return [
            'TPM pulang tepat'  => [['pulang' => '16:00'], 'TPM', self::TA, ['TPM']],
            'TPM pulang lambat' => [['masuk' => null, 'pulang' => '17:30'], 'TPM', self::TA, ['TPM']],
            'TPM pulang -30'    => [['pulang' => '15:30'], 'TPM', self::TA + self::TL1, ['TPM', 'PSW1']],
            'TPM pulang -60'    => [['pulang' => '15:00'], 'TPM', self::TA + self::TL2, ['TPM', 'PSW2']],
            'TPM pulang -61'    => [['masuk' => '', 'pulang' => '14:59'], 'TPM', self::TA + self::TL3, ['TPM', 'PSW3']],
            'TPP datang awal'   => [['masuk' => '07:00'], 'TPP', self::TA, ['TPP']],
            'TPP datang +30'    => [['masuk' => '08:00'], 'TPP', self::TA + self::TL1, ['TPP', 'TL1']],
            'TPP datang +31'    => [['masuk' => '08:01', 'pulang' => null], 'TPP', self::TA + self::TL2, ['TPP', 'TL2']],
            'TPP datang +61'    => [['masuk' => '08:31'], 'TPP', self::TA + self::TL3, ['TPP', 'TL3']],
        ];
    }

    public function testTanpaPresensiAdalahTk(): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, [], $this->config());
        $this->assertSame('TK', $hasil->status);
        $this->assertSame(self::TK, $hasil->potongan);
        $this->assertSame(['TK'], $hasil->kategori);
        $this->assertSame(['TK' => self::TK], $hasil->rincian);
    }

    // ---------------------------------------------------------------- precedence & konket

    public function testKonketAffectTukinSajaYangMembebaskan(): void
    {
        $config = $this->config();
        $this->assertSame('konket', StatusHarian::evaluasi(self::SENIN, ['konket' => ['affect_tukin' => 1, 'kategori' => 13]], $config)->status);
        $this->assertSame('konket', StatusHarian::evaluasi(self::SENIN, ['konket' => ['affect_tukin' => '1', 'kategori' => 7]], $config)->status);
        $this->assertSame('TK', StatusHarian::evaluasi(self::SENIN, ['konket' => ['affect_tukin' => 2, 'kategori' => 13]], $config)->status);
        $this->assertSame('TK', StatusHarian::evaluasi(self::SENIN, ['konket' => ['kategori' => 13]], $config)->status, 'affect_tukin wajib bernilai 1.');
        $this->assertSame('TK', StatusHarian::evaluasi(self::SENIN, ['konket' => ['affect_tukin' => 0]], $config)->status);

        $tidakBebas = StatusHarian::evaluasi(self::SENIN, ['konket' => ['affect_tukin' => 2], 'presensi' => ['masuk' => '08:00', 'pulang' => '16:00']], $config);
        $this->assertSame('hadir', $tidakBebas->status);
        $this->assertSame(self::TL1, $tidakBebas->potongan);
    }

    public function testPrecedenceAkhirPekanTbCutiKonketPresensi(): void
    {
        $config = $this->config();
        $semua  = [
            'tugas_belajar' => true,
            'cuti'          => ['jenis' => 'tahunan'],
            'konket'        => ['affect_tukin' => 1],
            'presensi'      => ['masuk' => '09:00', 'pulang' => '12:00'],
        ];
        $this->assertSame('libur', StatusHarian::evaluasi('2026-01-10', $semua, $config)->status);
        $this->assertSame('tugas_belajar', StatusHarian::evaluasi(self::SENIN, $semua, $config)->status);
        unset($semua['tugas_belajar']);
        $this->assertSame('cuti', StatusHarian::evaluasi(self::SENIN, $semua, $config)->status);
        unset($semua['cuti']);
        $this->assertSame('konket', StatusHarian::evaluasi(self::SENIN, $semua, $config)->status);
        unset($semua['konket']);
        $this->assertSame(self::TL3 + self::TL3, StatusHarian::evaluasi(self::SENIN, $semua, $config)->potongan);
    }

    // ---------------------------------------------------------------- cuti harian

    /**
     * @dataProvider jenisCutiHarianProvider
     *
     * @param array<string, mixed> $cuti
     */
    public function testTiapJenisCutiTidakDipotongHarian(array $cuti): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, ['cuti' => $cuti], $this->config());
        $this->assertSame('cuti', $hasil->status);
        $this->assertSame($cuti['jenis'], $hasil->jenisCuti);
        $this->assertSame(0, $hasil->potongan);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function jenisCutiHarianProvider(): array
    {
        return [
            'tahunan'        => [['jenis' => 'tahunan']],
            'besar'          => [['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => '2026-03-31', 'lama' => 86]],
            'sakit ke-14'    => [['jenis' => 'sakit']],
            'melahirkan'     => [['jenis' => 'melahirkan', 'mulai' => '2026-01-05', 'akhir' => '2026-04-05', 'lama' => 90]],
            'alasan penting' => [['jenis' => 'alasan_penting', 'mulai' => '2026-01-05', 'akhir' => '2026-01-30', 'lama' => 20]],
            'cltn'           => [['jenis' => 'cltn']],
        ];
    }

    public function testJenisCutiTidakDikenalDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StatusHarian::evaluasi(self::SENIN, ['cuti' => ['jenis' => 'liburan']], $this->config());
    }

    // ---------------------------------------------------------------- cuti sakit

    public function testCutiSakitHariKe14Vs15(): void
    {
        $config = $this->config();
        // 5–22 Jan = 14 hari kerja; 5–23 Jan = 15 hari kerja. Hari ke-15 = 1% × 0,2 = 20 bp (legacy :12799).
        $empatBelas = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-22'), [], self::isi('2026-01-05', '2026-01-22', ['cuti' => ['jenis' => 'sakit']]), $config, pegawai: self::baik());
        $this->assertSame(0, $empatBelas->potonganPresensi);

        $limaBelas = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-23'), [], self::isi('2026-01-05', '2026-01-23', ['cuti' => ['jenis' => 'sakit']]), $config, pegawai: self::baik());
        $this->assertSame(self::CS, $limaBelas->potonganPresensi);
        $this->assertSame(1, $limaBelas->jumlah['CS']);
        $this->assertSame(['TL' => 0, 'PSW' => 0, 'TK' => 0, 'TA' => 0, 'CUTI' => self::CS], $limaBelas->rincian);

        // Libur di tengah tidak memutus urutan (hari kerja).
        $denganLibur = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-26'), ['2026-01-12'], self::isi('2026-01-05', '2026-01-26', ['cuti' => ['jenis' => 'sakit']], ['2026-01-12']), $config, pegawai: self::baik());
        $this->assertSame(self::CS, $denganLibur->potonganPresensi);
    }

    /**
     * Rekap (:12796-12849): urutan naik hanya pada cuti sakit, direset hanya oleh baris presensi. Cuti jenis lain,
     * konket `affect_tukin = 1`, dan TB tidak memutus (dan tidak menambah) urutan. 5–26 Jan = 16 hari kerja; bila
     * 12 Jan diganti, sisa 15 hari sakit → 26 Jan = hari ke-15.
     *
     * @dataProvider selaKonketCutiProvider
     *
     * @param array<string, mixed> $sela
     */
    public function testUrutanCutiSakitHanyaDiresetPresensi(array $sela, int $harapan): void
    {
        $harian               = self::isi('2026-01-05', '2026-01-26', ['cuti' => ['jenis' => 'sakit']]);
        $harian['2026-01-12'] = $sela;
        $hasil                = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-26'), [], $harian, $this->config(), pegawai: self::baik());
        $this->assertSame($harapan, $hasil->potonganPresensi);
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function selaKonketCutiProvider(): array
    {
        return [
            'cuti tahunan'                  => [['cuti' => ['jenis' => 'tahunan']], self::CS],
            'cuti alasan penting'           => [['cuti' => ['jenis' => 'alasan_penting', 'mulai' => '2026-01-12', 'akhir' => '2026-01-12', 'lama' => 1]], self::CS],
            'konket affect_tukin 1'         => [['konket' => ['affect_tukin' => 1, 'kategori' => 4]], self::CS],
            'tugas belajar'                 => [['tugas_belajar' => true], self::CS],
            'cuti sakit + konket'           => [['cuti' => ['jenis' => 'sakit'], 'konket' => ['affect_tukin' => 2]], 2 * self::CS],
            'hadir tepat waktu'             => [['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']], 0],
            'konket affect_tukin 2 + hadir' => [['konket' => ['affect_tukin' => 2], 'presensi' => ['masuk' => '07:30', 'pulang' => '16:00']], 0],
            'tanpa data (TK)'               => [[], self::TK],
        ];
    }

    /**
     * Lookback 1 periode (:12686-12697): periode Februari 2026, periode sebelumnya 16 Des 2025 – 15 Jan 2026. Urutan
     * dibawa hanya bila hari kerja terakhir periode lalu (15 Jan) dan hari kerja pertama periode ini (16 Jan) sakit.
     */
    public function testCutiSakitBerlanjutDariPeriodeSebelumnya(): void
    {
        $config  = $this->config();
        $periode = PeriodeTukin::dariBulan(2026, 2);
        // 2–15 Jan = 10 hari kerja sakit (1 Jan tanpa data memutus) + 16, 19, 20, 21, 22 Jan → 22 Jan = hari ke-15.
        $harian = self::isi('2026-01-02', '2026-01-22', ['cuti' => ['jenis' => 'sakit']]);
        $this->assertSame(self::CS, KalkulasiTukin::hitung($periode, [], $harian, $config, '2026-01-22', self::baik())->totalPotongan);

        // Hari kerja terakhir periode lalu bukan sakit → urutan tidak dibawa.
        $tanpaUjung               = $harian;
        $tanpaUjung['2026-01-15'] = ['cuti' => ['jenis' => 'tahunan']];
        $this->assertSame(0, KalkulasiTukin::hitung($periode, [], $tanpaUjung, $config, '2026-01-22', self::baik())->totalPotongan);

        // Di periode lalu cuti jenis lain memutus urutan (hanya cuti sakit yang dihitung): 9–15 Jan = 5 hari.
        $putus               = $harian;
        $putus['2026-01-08'] = ['cuti' => ['jenis' => 'tahunan']];
        $this->assertSame(0, KalkulasiTukin::hitung($periode, [], $putus, $config, '2026-01-22', self::baik())->totalPotongan);

        // Data sebelum periode lalu diabaikan: sakit sejak 1 Des → periode lalu 23 hari kerja → 16–22 Jan semua dipotong.
        $panjang = self::isi('2025-12-01', '2026-01-22', ['cuti' => ['jenis' => 'sakit']]);
        $this->assertSame(5 * self::CS, KalkulasiTukin::hitung($periode, [], $panjang, $config, '2026-01-22', self::baik())->totalPotongan);

        // Hanya 1 periode ke belakang: libur 16 Des – 9 Jan menyisakan 12–15 Jan (4 hari) di periode lalu, sehingga
        // 16–22 Jan = hari ke-5..9 walau sakit sejak 1 Des (1–15 Des di luar periode lalu).
        $libur = HariKerja::kalender('2025-12-16', '2026-01-09');
        $this->assertSame(0, KalkulasiTukin::hitung($periode, $libur, $panjang, $config, '2026-01-22', self::baik())->totalPotongan);

        // Hari kerja pertama periode ini (16 Jan) cuti tahunan → urutan periode lalu tidak dibawa, walau cuti tahunan
        // di dalam periode tidak memutus urutan: 19–23 Jan = hari ke-1..5.
        $awalTahunan               = self::isi('2026-01-02', '2026-01-23', ['cuti' => ['jenis' => 'sakit']]);
        $awalTahunan['2026-01-16'] = ['cuti' => ['jenis' => 'tahunan']];
        $this->assertSame(0, KalkulasiTukin::hitung($periode, [], $awalTahunan, $config, '2026-01-23', self::baik())->totalPotongan);
    }

    // ---------------------------------------------------------------- cuti besar & melahirkan (pengganti total)

    public function testTanggalPotongCutiPeriode(): void
    {
        // Hari mulai ≥ 16 → tanggal 16 bulan itu; < 16 → tanggal 16 bulan sebelumnya (Januari → Desember) (:12481-12493).
        $this->assertSame(['2025-12-16'], PotonganCutiPeriode::tanggalPotong('2026-01-15', 35));
        $this->assertSame(['2026-01-16', '2026-02-16'], PotonganCutiPeriode::tanggalPotong('2026-01-16', 36));
        $this->assertSame(['2026-01-16', '2026-02-16'], PotonganCutiPeriode::tanggalPotong('2026-02-02', 65));
        $this->assertSame(['2026-11-16', '2026-12-16', '2027-01-16'], PotonganCutiPeriode::tanggalPotong('2026-11-30', 66));
    }

    public function testCutiBesarMenggantikanTotalPresensiTigaPeriode(): void
    {
        $config = $this->config();
        // Mulai 5 Jan → tanggal potong 16 Des, 16 Jan, 16 Feb (lama 96 > 65) → periode Januari, Februari, Maret.
        $harian = self::isi('2026-01-05', '2026-04-10', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => '2026-04-10', 'lama' => 96]]);
        $hasil  = [];

        foreach ([1, 2, 3, 4] as $bulan) {
            $h             = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, $bulan), [], $harian, $config, pegawai: self::baik());
            $hasil[$bulan] = [$h->totalPresensi, $h->totalSkp, $h->totalPotongan, $h->alasan];
        }

        // Januari: 16 Des – 2 Jan tanpa data (13 hari kerja TK) tetap diganti 5% (:13033-13038), bukan dijumlahkan.
        // April: 16 Mar – 10 Apr cuti, 13–15 Apr TK = 3 × 60.
        $this->assertSame([
            1 => [500, 0, 500, 'cuti_besar'],
            2 => [500, 0, 500, 'cuti_besar'],
            3 => [500, 0, 500, 'cuti_besar'],
            4 => [3 * self::TK, 0, 3 * self::TK, null],
        ], $hasil);
    }

    public function testCutiBesarBatasLamaDanIrisanPeriode(): void
    {
        $config = $this->config();
        $cuti   = static fn (string $mulai, string $akhir, int $lama): array => self::isi($mulai, $akhir, ['cuti' => ['jenis' => 'besar', 'mulai' => $mulai, 'akhir' => $akhir, 'lama' => $lama]]);
        $maret  = PeriodeTukin::dariBulan(2026, 3);

        // Mulai 20 Jan → tanggal potong ke-1 = 16 Jan (periode Februari); ke-2 = 16 Feb (Maret) hanya bila lama > 35.
        $this->assertSame(['besar' => 500, 'melahirkan' => null], PotonganCutiPeriode::untuk(PeriodeTukin::dariBulan(2026, 2), $cuti('2026-01-20', '2026-02-23', 35), 0, $config));
        $this->assertSame(['besar' => null, 'melahirkan' => null], PotonganCutiPeriode::untuk($maret, $cuti('2026-01-20', '2026-02-23', 35), 0, $config));
        $this->assertSame(['besar' => 500, 'melahirkan' => null], PotonganCutiPeriode::untuk($maret, $cuti('2026-01-20', '2026-02-24', 36), 0, $config));

        // Cuti wajib beririsan dengan periode (:12479): lama 70 tetapi selesai 10 Jan → periode Februari tidak dipotong.
        $pendek = $cuti('2025-12-20', '2026-01-10', 70);
        $this->assertSame(500, PotonganCutiPeriode::untuk(PeriodeTukin::dariBulan(2026, 1), $pendek, 0, $config)['besar']);
        $this->assertNull(PotonganCutiPeriode::untuk(PeriodeTukin::dariBulan(2026, 2), $pendek, 0, $config)['besar']);
    }

    public function testCutiMelahirkanAnakLebihDariTigaDanMenggantikanTotal(): void
    {
        $config   = $this->config();
        $harian   = static fn (int $lama): array => self::isi('2026-01-05', '2026-04-05', ['cuti' => ['jenis' => 'melahirkan', 'mulai' => '2026-01-05', 'akhir' => '2026-04-05', 'lama' => $lama]]);
        $perBulan = static function (array $harian, int $anak) use ($config): array {
            $hasil = [];

            foreach ([1, 2, 3, 4] as $bulan) {
                $hasil[] = PotonganCutiPeriode::untuk(PeriodeTukin::dariBulan(2026, $bulan), $harian, $anak, $config)['melahirkan'];
            }

            return $hasil;
        };

        // Syarat jumlah anak > 3 (:12527): anak ke-1 s.d. ke-3 tidak dipotong.
        $this->assertSame([null, null, null, null], $perBulan($harian(90), 3));
        $this->assertSame([4000, 7000, 8000, null], $perBulan($harian(90), 4));
        $this->assertSame([4000, 7000, 8000, null], $perBulan($harian(66), 4));
        $this->assertSame([4000, 7000, null, null], $perBulan($harian(65), 4));
        $this->assertSame([4000, 7000, null, null], $perBulan($harian(36), 4));
        $this->assertSame([4000, null, null, null], $perBulan($harian(35), 4));

        // Total = 70% saja; presensi (TK 16 Des – 2 Jan) dan SKP diabaikan (:13041-13045).
        $hasil = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $harian(90), $config, pegawai: new DataPegawaiTukin('kurang', 4));
        $this->assertSame([0, 0, 7000, 'cuti_melahirkan'], [$hasil->totalPresensi, $hasil->totalSkp, $hasil->totalPotongan, $hasil->alasan]);
    }

    public function testCutiBesarMendahuluiMelahirkanDanAlasanPentingTidakDipotong(): void
    {
        $config = $this->config();
        $harian = self::isi('2026-01-16', '2026-01-23', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-16', 'akhir' => '2026-01-23', 'lama' => 8]])
            + self::isi('2026-01-26', '2026-02-13', ['cuti' => ['jenis' => 'melahirkan', 'mulai' => '2026-01-26', 'akhir' => '2026-02-13', 'lama' => 19]]);
        $hasil = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $harian, $config, pegawai: new DataPegawaiTukin(jumlahAnak: 5));
        $this->assertSame([500, 'cuti_besar'], [$hasil->totalPotongan, $hasil->alasan]);

        // Alasan penting > 14 hari tidak dipotong (rekap :12415-12466 tidak dipakai); seluruh periode cuti → SKP 0.
        $ap      = self::isi('2026-01-16', '2026-02-13', ['cuti' => ['jenis' => 'alasan_penting', 'mulai' => '2026-01-16', 'akhir' => '2026-02-13', 'lama' => 29]]);
        $hasilAp = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $ap, $config);
        $this->assertSame([0, 'cuti_sepanjang_periode'], [$hasilAp->totalPotongan, $hasilAp->alasan]);
    }

    public function testTarifCutiPeriodeDapatDitimpa(): void
    {
        $harian = self::isi('2026-01-05', '2026-04-10', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => '2026-04-10', 'lama' => 96]]);
        $config = $this->config(['CUTI_BESAR_3' => 25]);
        $this->assertSame(2500, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 3), [], $harian, $config)->totalPotongan);
    }

    public function testCutiPeriodeWajibDataLengkap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KalkulasiTukin::hitung(new PeriodeTukin(self::SENIN, self::SENIN), [], [self::SENIN => ['cuti' => ['jenis' => 'besar', 'mulai' => self::SENIN, 'akhir' => self::JUMAT]]], $this->config());
    }

    // ---------------------------------------------------------------- SKP & cuti sepanjang periode

    /**
     * @dataProvider predikatSkpProvider
     */
    public function testPotonganSkpPeriodik(?string $predikat, int $harapan): void
    {
        // Hadir 07:30–16:30 setiap hari kerja (Jumat 16:30 tepat) → potongan presensi 0.
        $harian = self::isi('2026-01-16', '2026-02-13', ['presensi' => ['masuk' => '07:30', 'pulang' => '16:30']]);
        $hasil  = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $harian, $this->config(), pegawai: new DataPegawaiTukin($predikat));
        $this->assertSame([0, $harapan, $harapan], [$hasil->totalPresensi, $hasil->totalSkp, $hasil->totalPotongan]);
    }

    /**
     * @return array<string, array{string|null, int}>
     */
    public static function predikatSkpProvider(): array
    {
        return [
            'sangat baik'                        => ['Sangat Baik', 0],
            'baik'                               => ['BAIK', 0],
            'butuh perbaikan'                    => ['butuh perbaikan', 0],
            'kurang'                             => ['Kurang', 1600],
            'sangat kurang'                      => ['sangat kurang', 3200],
            'tanpa data'                         => [null, 3200],
            'spasi tidak di-trim seperti legacy' => [' baik', 3200],
        ];
    }

    public function testCutiSepanjangPeriodeMembebaskanSkpSaja(): void
    {
        $config  = $this->config();
        $periode = PeriodeTukin::dariBulan(2026, 2);
        // 21 hari kerja cuti sakit → hari ke-15..21 = 7 × 20; seluruh periode cuti → SKP 0 (:13049-13051).
        $sakit = self::isi('2026-01-16', '2026-02-13', ['cuti' => ['jenis' => 'sakit']]);
        $hasil = KalkulasiTukin::hitung($periode, [], $sakit, $config);
        $this->assertSame([7 * self::CS, 0, 7 * self::CS, 'cuti_sepanjang_periode'], [$hasil->totalPresensi, $hasil->totalSkp, $hasil->totalPotongan, $hasil->alasan]);

        // Dihitung atas periode penuh walau loop dipotong hariIni.
        $this->assertSame('cuti_sepanjang_periode', KalkulasiTukin::hitung($periode, [], $sakit, $config, '2026-01-30')->alasan);

        // Satu hari kerja tanpa cuti → SKP tetap dipotong.
        $sakit['2026-02-13'] = ['presensi' => ['masuk' => '07:30', 'pulang' => '16:30']];
        $this->assertSame(6 * self::CS + 3200, KalkulasiTukin::hitung($periode, [], $sakit, $config)->totalPotongan);
    }

    // ---------------------------------------------------------------- tugas belajar

    public function testHariTugasBelajarBebasTanpaPotongan25Persen(): void
    {
        // TB 2–6 Feb di tengah periode Februari: hari TB bebas, 16 hari kerja lain TK, tanpa potongan TB bulanan.
        $harian = self::isi('2026-02-02', '2026-02-06', ['tugas_belajar' => ['id' => 'tb1', 'mulai' => '2026-02-02']]);
        $hasil  = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $harian, $this->config(), pegawai: self::baik());
        $this->assertSame([16 * self::TK, 16 * self::TK, null], [$hasil->potonganPresensi, $hasil->totalPotongan, $hasil->alasan]);
        $this->assertSame('tugas_belajar', $hasil->harian['2026-02-02']->status);
        $this->assertSame('TK', $hasil->harian['2026-02-09']->status, 'Tidak ada isPegTB: hari setelah TB dihitung normal.');
    }

    /**
     * Pemutihan rekap (:12726-12735, :13026-13030): bila hari kerja terakhir yang diproses masih TB, total = 0.
     */
    public function testPemutihanTbBilaHariKerjaTerakhirMasihTb(): void
    {
        $config  = $this->config();
        $periode = PeriodeTukin::dariBulan(2026, 2);
        $tb      = self::isi('2026-02-09', '2026-02-13', ['tugas_belajar' => true]);
        $hasil   = KalkulasiTukin::hitung($periode, [], $tb, $config);
        $this->assertSame([16 * self::TK, 0, 0, 0, 'pemutihan_tb'], [$hasil->potonganPresensi, $hasil->totalPresensi, $hasil->totalSkp, $hasil->totalPotongan, $hasil->alasan]);

        // TB yang dimulai Sabtu setelah hari kerja terakhir juga memutihkan (penanda di-set pada tanggal apa pun).
        $this->assertSame('pemutihan_tb', KalkulasiTukin::hitung($periode, [], ['2026-02-14' => ['tugas_belajar' => true]], $config)->alasan);

        // Hari kerja terakhir bukan TB → tidak ada pemutihan.
        $tb['2026-02-13'] = [];
        $this->assertSame(17 * self::TK + 3200, KalkulasiTukin::hitung($periode, [], $tb, $config)->totalPotongan);

        // Dengan hariIni, yang dinilai hari kerja terakhir yang diproses.
        $this->assertSame('pemutihan_tb', KalkulasiTukin::hitung($periode, [], $tb, $config, '2026-02-10')->alasan);
    }

    // ---------------------------------------------------------------- masa kerja

    public function testHariDiLuarMasaKerjaDikecualikan(): void
    {
        $config  = $this->config();
        $periode = PeriodeTukin::dariBulan(2026, 2);

        // [K] pegawai baru TMT 2 Feb: 16–30 Jan (11 hari kerja) tanpa potongan; 2–13 Feb (10 hari) TK.
        $masuk = KalkulasiTukin::hitung($periode, [], [], $config, pegawai: self::baik('2026-02-02'));
        $this->assertSame(10 * self::TK, $masuk->totalPotongan);
        $this->assertSame('di_luar_masa_kerja', $masuk->harian['2026-01-30']->status);
        $this->assertSame('TK', $masuk->harian['2026-02-02']->status);

        // [V2] TMT keluar 2 Feb: hari ≥ 2 Feb dikecualikan, 16–30 Jan TK.
        $keluar = KalkulasiTukin::hitung($periode, [], [], $config, pegawai: self::baik(tmtKeluar: '2026-02-02'));
        $this->assertSame(11 * self::TK, $keluar->totalPotongan);
        $this->assertSame('di_luar_masa_kerja', $keluar->harian['2026-02-02']->status);

        // Cuti sepanjang periode hanya menghitung hari dalam masa kerja.
        $cuti = KalkulasiTukin::hitung($periode, [], self::isi('2026-02-02', '2026-02-13', ['cuti' => ['jenis' => 'tahunan']]), $config, pegawai: new DataPegawaiTukin(tmtMasuk: '2026-02-02'));
        $this->assertSame([0, 'cuti_sepanjang_periode'], [$cuti->totalPotongan, $cuti->alasan]);

        $this->expectException(InvalidArgumentException::class);
        new DataPegawaiTukin(tmtMasuk: '2026-02-02', tmtKeluar: '2026-02-02');
    }

    // ---------------------------------------------------------------- kombinasi bertumpuk (level periode)

    public function testKombinasiBertumpukHarian(): void
    {
        $config = $this->config([], '2026-02-19', '2026-03-20');
        // 16 Feb – 27 Feb 2026; 17 Feb libur.
        $libur  = ['2026-02-17'];
        $harian = [
            // 1) libur + cuti + konket + presensi pada tanggal yang sama → libur, tidak dihitung.
            '2026-02-17' => ['cuti' => ['jenis' => 'tahunan'], 'konket' => ['affect_tukin' => 1], 'presensi' => ['masuk' => '10:00']],
            // 2) akhir pekan + presensi terlambat → diabaikan.
            '2026-02-21' => ['presensi' => ['masuk' => '10:00', 'pulang' => '11:00']],
            // 3) cuti + konket + presensi terlambat → cuti, tanpa potongan.
            '2026-02-16' => ['cuti' => ['jenis' => 'tahunan'], 'konket' => ['affect_tukin' => 2], 'presensi' => ['masuk' => '09:00']],
            // 4) konket affect_tukin 2 + hanya presensi pulang lebih awal 45 menit → TPM (TA) + PSW2.
            '2026-02-18' => ['konket' => ['affect_tukin' => 2, 'kategori' => 13], 'presensi' => ['pulang' => '15:15']],
            // 5) hari pertama puasa: datang 08:20 (TL1), pulang 15:40 (+40) → TL1 gugur.
            '2026-02-19' => ['presensi' => ['masuk' => '08:20', 'pulang' => '15:40']],
            // 6) Jumat puasa: datang 08:45 (TL2), pulang 15:00 (PSW1, standar Jumat 15:30).
            '2026-02-20' => ['presensi' => ['masuk' => '08:45:30', 'pulang' => '15:00']],
            // 7) konket affect_tukin 1 + presensi terlambat → konket, tanpa potongan.
            '2026-02-23' => ['konket' => ['affect_tukin' => 1, 'kategori' => 4], 'presensi' => ['masuk' => '11:00']],
            // 8) hanya presensi masuk terlambat 61 menit → TPP (TA) + TL3.
            '2026-02-24' => ['presensi' => ['masuk' => '09:01']],
            // 9) 25 Feb tanpa data → TK. 10) 26 Feb hadir tepat. 11) 27 Feb (Jumat puasa) pulang 15:30 tepat.
            '2026-02-26' => ['presensi' => ['masuk' => '08:00', 'pulang' => '15:00']],
            '2026-02-27' => ['presensi' => ['masuk' => '07:59', 'pulang' => '15:30']],
        ];
        $hasil = KalkulasiTukin::hitung(new PeriodeTukin('2026-02-16', '2026-02-27'), $libur, $harian, $config, pegawai: self::baik());

        $this->assertSame(9, $hasil->hariKerja);
        $this->assertSame([
            'TL1' => 0, 'TL2' => 1, 'TL3' => 1, 'PSW1' => 1, 'PSW2' => 1, 'PSW3' => 0,
            'TPM' => 1, 'TPP' => 1, 'TK' => 1, 'CS' => 0,
        ], $hasil->jumlah);
        // (TA + PSW2) + (TL2 + PSW1) + (TA + TL3) + TK = 50 + 30 + 55 + 60 = 195.
        $this->assertSame(['TL' => self::TL2 + self::TL3, 'PSW' => self::TL2 + self::TL1, 'TK' => self::TK, 'TA' => 2 * self::TA, 'CUTI' => 0], $hasil->rincian);
        $this->assertSame(195, $hasil->potonganPresensi);
        $this->assertSame(195, $hasil->totalPotongan);
        $this->assertSame('cuti', $hasil->harian['2026-02-16']->status);
        $this->assertSame('konket', $hasil->harian['2026-02-23']->status);
        $this->assertArrayNotHasKey('2026-02-17', $hasil->harian);
    }

    /**
     * Periode Februari 2026: sakit 16 Jan – 6 Feb (16 hari kerja; 30 Jan juga konket) lalu 9–13 Feb berganti-ganti.
     * Hari ke-15/16 (5–6 Feb) = 2 × 20 bp.
     */
    public function testKombinasiBertumpukSakitCutiBesarSkp(): void
    {
        $config  = $this->config();
        $periode = PeriodeTukin::dariBulan(2026, 2);
        $sakit   = self::isi('2026-01-16', '2026-02-06', ['cuti' => ['jenis' => 'sakit']]);
        $sakit['2026-01-30'] += ['konket' => ['affect_tukin' => 1]];

        // a) 9–13 Feb hadir, predikat kurang → 40 + 1600.
        $hadir = KalkulasiTukin::hitung($periode, [], $sakit + self::isi('2026-02-09', '2026-02-13', ['presensi' => ['masuk' => '07:30', 'pulang' => '16:30']]), $config, pegawai: new DataPegawaiTukin('kurang'));
        $this->assertSame([2 * self::CS, 1600, 2 * self::CS + 1600, null], [$hadir->totalPresensi, $hadir->totalSkp, $hadir->totalPotongan, $hadir->alasan]);

        // b) 9–13 Feb cuti tahunan → seluruh periode cuti → SKP 0, total 40.
        $tahunan = KalkulasiTukin::hitung($periode, [], $sakit + self::isi('2026-02-09', '2026-02-13', ['cuti' => ['jenis' => 'tahunan']]), $config, pegawai: new DataPegawaiTukin('kurang'));
        $this->assertSame([2 * self::CS, 0, 2 * self::CS, 'cuti_sepanjang_periode'], [$tahunan->totalPresensi, $tahunan->totalSkp, $tahunan->totalPotongan, $tahunan->alasan]);

        // c) 9–13 Feb cuti besar (mulai 9 Feb → tanggal potong 16 Jan = awal periode) → total diganti 5%.
        $besar = KalkulasiTukin::hitung($periode, [], $sakit + self::isi('2026-02-09', '2026-02-13', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-02-09', 'akhir' => '2026-02-13', 'lama' => 5]]), $config, pegawai: new DataPegawaiTukin('kurang'));
        $this->assertSame([2 * self::CS, 500, 0, 500, 'cuti_besar'], [$besar->potonganPresensi, $besar->totalPresensi, $besar->totalSkp, $besar->totalPotongan, $besar->alasan]);
    }

    /**
     * Periode Maret 2026 (16 Feb – 15 Mar, 20 hari kerja): pegawai baru TMT 18 Feb, 18–19 Feb tanpa data (TK),
     * cuti melahirkan 20 Feb – 20 Mei (lama 90, tanggal potong 16 Feb = awal periode).
     */
    public function testKombinasiBertumpukPegawaiBaruMelahirkanTb(): void
    {
        $config  = $this->config();
        $periode = PeriodeTukin::dariBulan(2026, 3);
        $harian  = self::isi('2026-02-20', '2026-03-13', ['cuti' => ['jenis' => 'melahirkan', 'mulai' => '2026-02-20', 'akhir' => '2026-05-20', 'lama' => 90]]);

        // a) Anak ke-3: tanpa potongan melahirkan; TK 18–19 Feb + SKP kurang = 120 + 1600.
        $anak3 = KalkulasiTukin::hitung($periode, [], $harian, $config, pegawai: new DataPegawaiTukin('kurang', 3, '2026-02-18'));
        $this->assertSame([2 * self::TK, 1600, 2 * self::TK + 1600, null], [$anak3->totalPresensi, $anak3->totalSkp, $anak3->totalPotongan, $anak3->alasan]);
        $this->assertSame('di_luar_masa_kerja', $anak3->harian['2026-02-17']->status);

        // b) Anak ke-4: total diganti 40%.
        $anak4 = KalkulasiTukin::hitung($periode, [], $harian, $config, pegawai: new DataPegawaiTukin('kurang', 4, '2026-02-18'));
        $this->assertSame([2 * self::TK, 0, 0, 4000, 'cuti_melahirkan'], [$anak4->potonganPresensi, $anak4->totalPresensi, $anak4->totalSkp, $anak4->totalPotongan, $anak4->alasan]);

        // c) Hari kerja terakhir (13 Mar) TB → pemutihan TB mendahului cuti melahirkan.
        $harian['2026-03-13']['tugas_belajar'] = true;
        $tb                                    = KalkulasiTukin::hitung($periode, [], $harian, $config, pegawai: new DataPegawaiTukin('kurang', 4, '2026-02-18'));
        $this->assertSame([0, 'pemutihan_tb'], [$tb->totalPotongan, $tb->alasan]);
    }

    // ---------------------------------------------------------------- tarif & konfigurasi

    public function testPerubahanTarifInputSamaMengubahHasil(): void
    {
        $periode = new PeriodeTukin(self::SENIN, self::JUMAT);
        $harian  = [
            self::SENIN  => ['presensi' => ['masuk' => '08:01', 'pulang' => '16:00']],       // TL2
            '2026-01-06' => ['presensi' => ['masuk' => '07:30', 'pulang' => '15:15']],       // PSW2
            '2026-01-07' => ['presensi' => ['pulang' => '16:00']],                           // TPM
            '2026-01-08' => ['cuti' => ['jenis' => 'tahunan']],
            self::JUMAT  => [],                                                              // TK
        ];
        $dasar = KalkulasiTukin::hitung($periode, [], $harian, $this->config(), pegawai: self::baik());
        $this->assertSame(self::TL2 + self::TL2 + self::TA + self::TK, $dasar->totalPotongan);

        // 2,5% × 0,2 = 50 bp per kejadian TL2/PSW2 (sebelumnya 20).
        $tl2 = KalkulasiTukin::hitung($periode, [], $harian, $this->config(['TL2/PSW2' => '2.5']), pegawai: self::baik());
        $this->assertSame($dasar->totalPotongan + 2 * 30, $tl2->totalPotongan);

        $ta = KalkulasiTukin::hitung($periode, [], $harian, $this->config(['TA' => 2]), pegawai: self::baik());
        $this->assertSame($dasar->totalPotongan + 10, $ta->totalPotongan);

        $tk = KalkulasiTukin::hitung($periode, [], $harian, $this->config(['TK' => '3.05']), pegawai: self::baik());
        $this->assertSame($dasar->totalPotongan + 1, $tk->totalPotongan);
    }

    public function testFaktorPresensiWajibBulatDalamBasisPoin(): void
    {
        $config = $this->config(['CUTI_SAKIT' => '0.5']);
        $this->assertSame(10, $config->potonganHarian('CUTI_SAKIT'));
        $this->assertSame(self::TK, $config->potonganHarian('TK'));
        $this->assertSame(300, $config->tarif('TK'), 'Tarif mentah tetap persen web_config.');

        try {
            $this->config(['TK' => '0.01']);
            $this->fail('Tarif 0,01% × 0,2 tidak bulat dan harus ditolak.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $config->potonganHarian('SKP_KURANG');
    }

    public function testKonversiPersenKeBasisPoinTanpaFloat(): void
    {
        $this->assertSame(175, KonfigurasiTukin::persenKeBasisPoin('1.75'));
        $this->assertSame(175, KonfigurasiTukin::persenKeBasisPoin('1,75'));
        $this->assertSame(150, KonfigurasiTukin::persenKeBasisPoin('1.5'));
        $this->assertSame(500, KonfigurasiTukin::persenKeBasisPoin(5));
        $this->assertSame(1, KonfigurasiTukin::persenKeBasisPoin('0.01'));
        $this->assertSame(500, $this->config()->tarif('CUTI_BESAR_1'));
        $this->assertSame(8000, $this->config()->tarif('CUTI_MELAHIRKAN_3'));
        $this->assertSame(100, $this->config()->tarif('CUTI_SAKIT'));
    }

    /**
     * @dataProvider tarifTidakValidProvider
     */
    public function testTarifTidakValidDitolak(mixed $nilai): void
    {
        $this->expectException(InvalidArgumentException::class);
        KonfigurasiTukin::persenKeBasisPoin($nilai);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function tarifTidakValidProvider(): array
    {
        return [
            'float'        => [1.75],
            'tiga desimal' => ['1.755'],
            'negatif'      => [-1],
            'string minus' => ['-1'],
            'bukan angka'  => ['satu'],
            'kosong'       => [''],
        ];
    }

    public function testKunciTarifWajibTanpaLkh(): void
    {
        $tanpaLkh = new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5]);
        $this->assertSame(500, $tanpaLkh->tarif('TK'));

        $this->expectException(InvalidArgumentException::class);
        new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4]);
    }

    /**
     * @dataProvider puasaTidakValidProvider
     */
    public function testRentangPuasaDivalidasi(?string $mulai, ?string $selesai): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->config([], $mulai, $selesai);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function puasaTidakValidProvider(): array
    {
        return [
            'hanya mulai'     => ['2026-02-19', null],
            'hanya selesai'   => [null, '2026-03-20'],
            'mulai > selesai' => ['2026-03-21', '2026-03-20'],
            'tanggal tak ada' => ['2026-02-30', '2026-03-20'],
            'format salah'    => ['19-02-2026', '2026-03-20'],
        ];
    }

    public function testInputHarianTanggalTidakValidDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KalkulasiTukin::hitung(new PeriodeTukin(self::SENIN, self::JUMAT), [], ['2026-13-01' => []], $this->config());
    }
}
