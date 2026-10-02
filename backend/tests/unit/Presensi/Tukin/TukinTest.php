<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\JamKerja;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\Tukin\StatusHarian;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-037 — kalkulasi Tukin murni. Data sintetis. Tarif uji sengaja berbeda-beda (dalam basis poin:
 * TL1/PSW1 = 50, TL2/PSW2 = 100, TL3/PSW3 = 125, TA = 150, TK = 300, LKH = 75) agar setiap kombinasi
 * menghasilkan angka yang dapat dibedakan.
 *
 * Kalender 2026: 5 Jan = Senin, 9 Jan = Jumat.
 *
 * @internal
 */
final class TukinTest extends CIUnitTestCase
{
    private const SENIN = '2026-01-05';
    private const JUMAT = '2026-01-09';
    private const TL1   = 50;
    private const TL2   = 100;
    private const TL3   = 125;
    private const TA    = 150;
    private const TK    = 300;
    private const LKH   = 75;

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
            'LKH'      => '0.75',
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

        $hasil = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-01', '2026-01-11'), ['2026-01-01', '2026-01-03'], [], $this->config());
        $this->assertSame(6, $hasil->hariKerja);
        $this->assertSame(6, $hasil->jumlah['TK']);
        $this->assertSame(6 * self::TK, $hasil->totalPotongan);
    }

    public function testHariIniMemotongAkhirPeriode(): void
    {
        $periode = new PeriodeTukin('2026-01-05', '2026-01-09');

        $sebagian = KalkulasiTukin::hitung($periode, [], [], $this->config(), '2026-01-06');
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
        $config = new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5, 'LKH' => 6], jamMasukNormal: '07:45:00');
        $this->assertSame('07:45:00', JamKerja::untuk(self::SENIN, $config)['masuk']);

        $this->expectException(InvalidArgumentException::class);
        new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5, 'LKH' => 6], jamMasukNormal: '24:00');
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

    // ---------------------------------------------------------------- cuti

    /**
     * @dataProvider jenisCutiHarianProvider
     *
     * @param array<string, mixed> $cuti
     */
    public function testTiapJenisCutiTidakDipotongHarian(array $cuti): void
    {
        $hasil = StatusHarian::evaluasi(self::SENIN, ['cuti' => $cuti, 'wajibLkh' => true], $this->config());
        $this->assertSame('cuti', $hasil->status);
        $this->assertSame($cuti['jenis'], $hasil->jenisCuti);
        $this->assertSame(0, $hasil->potongan);
        $this->assertSame(0, $hasil->potonganLkh, 'LKH hanya pada baris presensi.');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function jenisCutiHarianProvider(): array
    {
        return [
            'tahunan'        => [['jenis' => 'tahunan']],
            'besar'          => [['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => '2026-03-31']],
            'sakit ke-14'    => [['jenis' => 'sakit']],
            'melahirkan'     => [['jenis' => 'melahirkan', 'mulai' => '2026-01-05', 'akhir' => '2026-04-05', 'lama' => 90, 'anak_sebelumnya' => 3]],
            'alasan penting' => [['jenis' => 'alasan_penting', 'mulai' => '2026-01-05', 'akhir' => '2026-01-30', 'lama' => 20]],
            'cltn'           => [['jenis' => 'cltn']],
        ];
    }

    public function testJenisCutiTidakDikenalDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StatusHarian::evaluasi(self::SENIN, ['cuti' => ['jenis' => 'liburan']], $this->config());
    }

    public function testCutiSakitHariKe14Vs15DanReset(): void
    {
        $config = $this->config();
        // 5–22 Jan = 14 hari kerja; 5–23 Jan = 15 hari kerja.
        $empatBelas = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-22'), [], self::isi('2026-01-05', '2026-01-22', ['cuti' => ['jenis' => 'sakit']]), $config);
        $this->assertSame(0, $empatBelas->totalPotongan);

        $harian    = self::isi('2026-01-05', '2026-01-23', ['cuti' => ['jenis' => 'sakit']]);
        $limaBelas = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-23'), [], $harian, $config);
        $this->assertSame(100, $limaBelas->totalPotongan, 'Hari ke-15 dipotong CUTI_SAKIT 1%.');
        $this->assertSame(1, $limaBelas->jumlah['CS']);
        $this->assertSame(100, $limaBelas->totalPresensi);

        // Libur di tengah tidak memutus urutan (hari kerja), tetapi konket (walau affect_tukin 2) memutusnya.
        $denganLibur = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-26'), ['2026-01-12'], self::isi('2026-01-05', '2026-01-26', ['cuti' => ['jenis' => 'sakit']], ['2026-01-12']), $config);
        $this->assertSame(100, $denganLibur->totalPotongan);

        $harian['2026-01-12'] = ['cuti' => ['jenis' => 'sakit'], 'konket' => ['affect_tukin' => 2]];
        $this->assertSame(100, KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-23'), [], $harian, $config)->totalPotongan, 'Cuti mendahului konket, urutan tetap.');
        $harian['2026-01-12'] = ['konket' => ['affect_tukin' => 2], 'presensi' => ['masuk' => '07:30', 'pulang' => '16:00']];
        $this->assertSame(0, KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-23'), [], $harian, $config)->totalPotongan);
        $harian['2026-01-12'] = ['cuti' => ['jenis' => 'tahunan']];
        $this->assertSame(0, KalkulasiTukin::hitung(new PeriodeTukin('2026-01-05', '2026-01-23'), [], $harian, $config)->totalPotongan);
    }

    public function testCutiSakitBerlanjutDariSebelumPeriode(): void
    {
        // 10 hari kerja sakit sebelum periode (2–15 Jan) + 5 hari di periode → hari ke-15 = 22 Jan.
        $harian = self::isi('2026-01-02', '2026-01-22', ['cuti' => ['jenis' => 'sakit']]);
        $hasil  = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-16', '2026-01-22'), [], $harian, $this->config());
        $this->assertSame(100, $hasil->totalPotongan);

        $harian['2026-01-07'] = ['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']];
        $this->assertSame(0, KalkulasiTukin::hitung(new PeriodeTukin('2026-01-16', '2026-01-22'), [], $harian, $this->config())->totalPotongan);
    }

    public function testCutiBesarBulanKe1Ke2Ke3DanSelesai(): void
    {
        $config = $this->config();
        // Mulai 5 Jan (≤ 15) → bulan ke-1 = Januari; 96 hari kalender (> 65) → tiga bulan: Jan, Feb, Mar.
        $harian = self::isi('2026-01-05', '2026-04-10', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => '2026-04-10']]);
        $hasil  = [];

        foreach ([1, 2, 3, 4] as $bulan) {
            $hasil[$bulan] = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, $bulan), [], $harian, $config)->potonganCuti;
        }
        $this->assertSame([1 => 5000, 2 => 7500, 3 => 9000, 4 => 0], $hasil);
    }

    public function testCutiBesarBatas35HariDanMulaiSetelahSeparuhBulan(): void
    {
        $config = $this->config();
        $cuti   = static fn (string $akhir): array => self::isi('2026-01-05', $akhir, ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => $akhir]]);
        // 5 Jan – 8 Feb = 35 hari → hanya bulan ke-1; 5 Jan – 9 Feb = 36 hari → bulan ke-2 juga.
        $this->assertSame(0, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $cuti('2026-02-08'), $config)->potonganCuti);
        $this->assertSame(7500, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $cuti('2026-02-09'), $config)->potonganCuti);

        // Mulai 16 Jan (> 15) → bulan ke-1 = Februari, satu kali saja walau banyak hari cuti.
        $harian = self::isi('2026-01-16', '2026-02-14', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-16', 'akhir' => '2026-02-14']]);
        $this->assertSame(0, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 1), [], $harian, $config)->potonganCuti);
        $this->assertSame(5000, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $harian, $config)->totalPotongan);
    }

    public function testCutiMelahirkanHanyaAnakKeempatDanBertahap(): void
    {
        $config   = $this->config();
        $harian   = static fn (int $anak, int $lama): array => self::isi('2026-01-05', '2026-04-05', ['cuti' => ['jenis' => 'melahirkan', 'mulai' => '2026-01-05', 'akhir' => '2026-04-05', 'lama' => $lama, 'anak_sebelumnya' => $anak]]);
        $perBulan = static function (array $harian) use ($config): array {
            $hasil = [];

            foreach ([1, 2, 3, 4] as $bulan) {
                $hasil[] = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, $bulan), [], $harian, $config)->potonganCuti;
            }

            return $hasil;
        };

        $this->assertSame([0, 0, 0, 0], $perBulan($harian(2, 90)), 'Anak ke-1 s.d. ke-3 tidak dipotong.');
        $this->assertSame([6000, 3000, 2000, 0], $perBulan($harian(3, 90)));
        $this->assertSame([6000, 0, 0, 0], $perBulan($harian(3, 31)));
        $this->assertSame([6000, 3000, 0, 0], $perBulan($harian(3, 32)));
        $this->assertSame([6000, 3000, 0, 0], $perBulan($harian(4, 61)));
        $this->assertSame([6000, 3000, 2000, 0], $perBulan($harian(4, 62)));
    }

    public function testCutiAlasanPentingSekaliPadaBulanTerbanyak(): void
    {
        $config = $this->config();
        // 26 Jan – 13 Feb: 5 hari kerja Januari, 10 hari kerja Februari → dipotong 2 Feb (hari kerja pertama Februari).
        $cuti  = static fn (int $lama): array => self::isi('2026-01-26', '2026-02-13', ['cuti' => ['jenis' => 'alasan_penting', 'mulai' => '2026-01-26', 'akhir' => '2026-02-13', 'lama' => $lama]]);
        $hasil = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $cuti(15), $config);
        $this->assertSame(5000, $hasil->potonganCuti);
        $this->assertSame('cuti', $hasil->harian['2026-02-02']->status);
        $this->assertSame(0, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $cuti(14), $config)->potonganCuti);
        $this->assertSame(0, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $cuti(15), $config, '2026-02-01')->potonganCuti, 'Belum sampai tanggal potong.');
    }

    public function testTarifCutiBulananDapatDitimpa(): void
    {
        $harian = self::isi('2026-01-05', '2026-04-10', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-01-05', 'akhir' => '2026-04-10']]);
        $config = $this->config(['CUTI_BESAR_1' => 25, 'CUTI_BESAR_2' => 25, 'CUTI_BESAR_3' => 25]);
        $this->assertSame(2500, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 3), [], $harian, $config)->potonganCuti);
    }

    public function testCutiBulananWajibDataLengkap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KalkulasiTukin::hitung(new PeriodeTukin(self::SENIN, self::SENIN), [], [self::SENIN => ['cuti' => ['jenis' => 'melahirkan', 'mulai' => self::SENIN, 'akhir' => self::JUMAT, 'lama' => 5]]], $this->config());
    }

    // ---------------------------------------------------------------- tugas belajar

    public function testTugasBelajarDipotongSekaliPerBulanKalender(): void
    {
        $config = $this->config();
        $tb     = self::isi('2026-01-16', '2026-02-13', ['tugas_belajar' => ['id' => 'tb1', 'mulai' => '2025-09-01']]);
        $hasil  = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $tb, $config);
        $this->assertSame(5000, $hasil->potonganTb, 'Periode 16–15 menyentuh dua bulan kalender: Januari dan Februari.');
        $this->assertSame(0, $hasil->potonganPresensi);

        // Bulan mulai TB dengan tanggal mulai > 1 bebas potongan.
        $baru = self::isi('2026-01-20', '2026-02-13', ['tugas_belajar' => ['mulai' => '2026-01-20']]);
        $this->assertSame(2500, KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $baru + self::isi('2026-01-16', '2026-01-19', ['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']]), $config)->potonganTb);
    }

    public function testSetelahPotonganTbHariBerikutnyaDikecualikan(): void
    {
        $config = $this->config();
        $harian = [
            '2026-01-26' => [],
            '2026-01-27' => ['tugas_belajar' => true],
            '2026-01-28' => [],
            '2026-02-02' => ['presensi' => ['masuk' => '09:00']],
            '2026-02-03' => ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-02-03', 'akhir' => '2026-02-05']],
        ];
        $hasil = KalkulasiTukin::hitung(new PeriodeTukin('2026-01-26', '2026-02-05'), [], $harian, $config);
        $this->assertSame(self::TK, $hasil->potonganPresensi, 'Hanya TK 26 Jan sebelum TB.');
        $this->assertSame(2500, $hasil->potonganTb);
        $this->assertSame(0, $hasil->potonganCuti, 'Potongan cuti 1 Feb tidak dikenakan setelah isPegTB.');
        $this->assertSame('dikecualikan_tb', $hasil->harian['2026-01-28']->status);
        $this->assertSame(1, $hasil->jumlah['TK']);
    }

    // ---------------------------------------------------------------- LKH

    public function testLkhPadaTkHadirTpmTpp(): void
    {
        $config = $this->config();
        $lkh    = ['wajibLkh' => true, 'lkhTerisi' => false];

        $tk = StatusHarian::evaluasi(self::SENIN, $lkh, $config);
        $this->assertSame([self::TK, self::LKH, ['TK', 'LKH']], [$tk->potongan, $tk->potonganLkh, $tk->kategori]);

        $hadir = StatusHarian::evaluasi(self::SENIN, $lkh + ['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']], $config);
        $this->assertSame([0, self::LKH], [$hadir->potongan, $hadir->potonganLkh]);

        $tpm = StatusHarian::evaluasi(self::SENIN, $lkh + ['presensi' => ['pulang' => '16:00']], $config);
        $this->assertSame([self::TA, self::LKH, ['TPM', 'LKH']], [$tpm->potongan, $tpm->potonganLkh, $tpm->kategori]);

        $tpp = StatusHarian::evaluasi(self::SENIN, $lkh + ['presensi' => ['masuk' => '07:30']], $config);
        $this->assertSame([self::TA, self::LKH], [$tpp->potongan, $tpp->potonganLkh]);

        $this->assertSame(0, StatusHarian::evaluasi(self::SENIN, ['wajibLkh' => true, 'lkhTerisi' => true], $config)->potonganLkh);
        $this->assertSame(0, StatusHarian::evaluasi(self::SENIN, ['wajibLkh' => false], $config)->potonganLkh);
        $this->assertSame(0, StatusHarian::evaluasi(self::SENIN, $lkh + ['konket' => ['affect_tukin' => 1]], $config)->potonganLkh);
    }

    public function testTotalPresensiDanTotalPresensiLkhTerpisah(): void
    {
        $periode = new PeriodeTukin(self::SENIN, '2026-01-07');
        $harian  = [
            self::SENIN  => ['wajibLkh' => true],
            '2026-01-06' => ['wajibLkh' => true, 'lkhTerisi' => true, 'presensi' => ['masuk' => '08:00', 'pulang' => '16:00']],
            '2026-01-07' => ['wajibLkh' => true, 'presensi' => ['pulang' => '16:00']],
        ];
        $hasil = KalkulasiTukin::hitung($periode, [], $harian, $this->config());
        $this->assertSame(self::TK + self::TL1 + self::TA, $hasil->totalPresensi);
        $this->assertSame(2 * self::LKH, $hasil->potonganLkh);
        $this->assertSame($hasil->totalPresensi + 2 * self::LKH, $hasil->totalPresensiLkh);
        $this->assertSame(2, $hasil->jumlah['LKH']);
        $this->assertSame($hasil->totalPresensiLkh, $hasil->totalPotongan);
    }

    // ---------------------------------------------------------------- kombinasi bertumpuk (level periode)

    public function testKombinasiBertumpukLevelPeriode(): void
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
            // 4) konket affect_tukin 2 + hanya presensi pulang lebih awal 45 menit → TPM + PSW2 (+ LKH).
            '2026-02-18' => ['konket' => ['affect_tukin' => 2, 'kategori' => 13], 'presensi' => ['pulang' => '15:15'], 'wajibLkh' => true],
            // 5) hari pertama puasa: datang 08:20 (TL1), pulang 15:40 (+40) → TL1 gugur.
            '2026-02-19' => ['presensi' => ['masuk' => '08:20', 'pulang' => '15:40']],
            // 6) Jumat puasa: datang 08:45 (TL2), pulang 15:00 (PSW1, standar Jumat 15:30).
            '2026-02-20' => ['presensi' => ['masuk' => '08:45:30', 'pulang' => '15:00']],
            // 7) konket affect_tukin 1 + presensi terlambat → konket, tanpa potongan.
            '2026-02-23' => ['konket' => ['affect_tukin' => 1, 'kategori' => 4], 'presensi' => ['masuk' => '11:00']],
            // 8) hanya presensi masuk terlambat 61 menit → TPP + TL3.
            '2026-02-24' => ['presensi' => ['masuk' => '09:01']],
            // 9) 25 Feb tanpa data → TK. 10) 26 Feb hadir tepat. 11) 27 Feb (Jumat puasa) pulang 15:30 tepat.
            '2026-02-26' => ['presensi' => ['masuk' => '08:00', 'pulang' => '15:00']],
            '2026-02-27' => ['presensi' => ['masuk' => '07:59', 'pulang' => '15:30']],
        ];
        $hasil = KalkulasiTukin::hitung(new PeriodeTukin('2026-02-16', '2026-02-27'), $libur, $harian, $config);

        $this->assertSame(9, $hasil->hariKerja);
        $this->assertSame([
            'TL1' => 0, 'TL2' => 1, 'TL3' => 1, 'PSW1' => 1, 'PSW2' => 1, 'PSW3' => 0,
            'TPM' => 1, 'TPP' => 1, 'TK' => 1, 'LKH' => 1, 'CS' => 0,
        ], $hasil->jumlah);
        $presensi = (self::TA + self::TL2) + (self::TL2 + self::TL1) + (self::TA + self::TL3) + self::TK;
        $this->assertSame($presensi, $hasil->potonganPresensi);
        $this->assertSame(self::LKH, $hasil->potonganLkh);
        $this->assertSame($presensi + self::LKH, $hasil->totalPotongan);
        $this->assertSame('cuti', $hasil->harian['2026-02-16']->status);
        $this->assertSame('konket', $hasil->harian['2026-02-23']->status);
        $this->assertArrayNotHasKey('2026-02-17', $hasil->harian);
    }

    public function testKombinasiBertumpukCutiBulananDanSakit(): void
    {
        $config = $this->config();
        // Cuti besar mulai 2 Feb (bulan ke-1 = Feb) + cuti sakit 15 hari kerja berurutan sebelumnya pada periode Feb.
        $harian = self::isi('2026-01-16', '2026-01-30', ['cuti' => ['jenis' => 'sakit']])
            + self::isi('2026-02-02', '2026-02-13', ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-02-02', 'akhir' => '2026-02-13']]);
        $harian['2026-01-30'] = ['cuti' => ['jenis' => 'sakit'], 'konket' => ['affect_tukin' => 1]];
        $hasil                = KalkulasiTukin::hitung(PeriodeTukin::dariBulan(2026, 2), [], $harian, $config);

        // 16–30 Jan = 11 hari kerja sakit (< 15) → 0; cuti besar 12 hari kalender → bulan ke-1 saja.
        $this->assertSame(0, $hasil->potonganPresensi);
        $this->assertSame(5000, $hasil->potonganCuti);
        $this->assertSame(5000, $hasil->totalPotongan);
        $this->assertSame(0, $hasil->jumlah['TK']);
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
            self::JUMAT  => ['wajibLkh' => true],                                            // TK + LKH
        ];
        $dasar = KalkulasiTukin::hitung($periode, [], $harian, $this->config());
        $this->assertSame(self::TL2 + self::TL2 + self::TA + self::TK + self::LKH, $dasar->totalPotongan);

        $tl2 = KalkulasiTukin::hitung($periode, [], $harian, $this->config(['TL2/PSW2' => '2.5']));
        $this->assertSame($dasar->totalPotongan + 2 * 150, $tl2->totalPotongan);

        $ta = KalkulasiTukin::hitung($periode, [], $harian, $this->config(['TA' => 2]));
        $this->assertSame($dasar->totalPotongan + 50, $ta->totalPotongan);

        $tk = KalkulasiTukin::hitung($periode, [], $harian, $this->config(['TK' => '3.01', 'LKH' => 0]));
        $this->assertSame($dasar->totalPotongan + 1 - self::LKH, $tk->totalPotongan);
    }

    public function testKonversiPersenKeBasisPoinTanpaFloat(): void
    {
        $this->assertSame(175, KonfigurasiTukin::persenKeBasisPoin('1.75'));
        $this->assertSame(175, KonfigurasiTukin::persenKeBasisPoin('1,75'));
        $this->assertSame(150, KonfigurasiTukin::persenKeBasisPoin('1.5'));
        $this->assertSame(500, KonfigurasiTukin::persenKeBasisPoin(5));
        $this->assertSame(1, KonfigurasiTukin::persenKeBasisPoin('0.01'));
        $this->assertSame(2500, $this->config()->tarif('TB'));
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

    public function testKunciTarifWajib(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5]);
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
