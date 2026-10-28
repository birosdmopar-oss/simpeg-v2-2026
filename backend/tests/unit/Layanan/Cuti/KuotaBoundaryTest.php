<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;
use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\EfekFinalCuti;
use App\Libraries\Layanan\Cuti\KuotaCuti;
use App\Libraries\Layanan\Cuti\PorsiTahunCuti;
use App\Libraries\Layanan\Cuti\SaldoCuti;
use App\Libraries\Layanan\Cuti\SnapshotSaldoCuti;
use App\Libraries\Layanan\Cuti\StatusCuti;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-022 Tahap 2b — tabel kasus uji wajib kuota PRD Fase 4 §6.3.8 (K1–K21, K25–K26 v1.3) + §6.3.10 (T11–T14) atas value
 * object `SaldoCuti`. Saldo ditulis `(N, N-1, N-2, utang, ditangguhkan)`; tahun acuan 2027.
 *
 * @internal
 */
final class KuotaBoundaryTest extends CIUnitTestCase
{
    private const TAHUN = 2027;

    private AturanCuti $aturan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aturan = AturanCuti::bawaan();
    }

    /**
     * K1–K11, K14–K15: validasi §6.3.4.
     *
     * @return iterable<string, array{array{int, int, int, int, int}, int, int, int, int, bool}>
     */
    public static function validasi(): iterable
    {
        // [saldo], menungguIni, lcIni, menungguDepan, lcDepan, lolos
        yield 'K1 tepat 24' => [[12, 6, 6, 0, 0], 0, 24, 0, 0, true];

        yield 'K2 25 > 24' => [[12, 6, 6, 0, 0], 0, 25, 0, 0, false];

        yield 'K3 5' => [[12, 6, 6, 0, 0], 0, 5, 0, 0, true];

        yield 'K6 N saja 12' => [[12, 0, 0, 0, 0], 0, 12, 0, 0, true];

        yield 'K7 N saja 13' => [[12, 0, 0, 0, 0], 0, 13, 0, 0, false];

        yield 'K8 menunggu 5 + 8 = 13' => [[10, 3, 0, 0, 0], 5, 8, 0, 0, true];

        yield 'K9 menunggu 5 + 9 > 13' => [[10, 3, 0, 0, 0], 5, 9, 0, 0, false];

        yield 'K10 menunggu 20 + 4 = 24' => [[12, 6, 6, 0, 0], 20, 4, 0, 0, true];

        yield 'K11 menunggu 20 + 5 > 24' => [[12, 6, 6, 0, 0], 20, 5, 0, 0, false];

        yield 'K14 utang 10 + 3 > 12' => [[12, 6, 6, 10, 0], 0, 0, 0, 3, false];

        yield 'K15 utang 10 + 2 = 12' => [[12, 6, 6, 10, 0], 0, 0, 0, 2, true];

        yield 'T9 menunggu tahun depan ikut dihitung' => [[12, 6, 6, 0, 0], 0, 0, 11, 2, false];
    }

    /**
     * @param array{int, int, int, int, int} $saldo
     */
    #[DataProvider('validasi')]
    public function testValidasiKuota(array $saldo, int $menungguIni, int $lcIni, int $menungguDepan, int $lcDepan, bool $lolos): void
    {
        $hasil = KuotaCuti::periksa(new SaldoCuti(...$saldo), $lcIni, $lcDepan, $menungguIni, $menungguDepan, self::TAHUN, $this->aturan);

        $this->assertSame($lolos, $hasil->valid);

        if (! $lolos) {
            $this->assertSame(KuotaCuti::KODE_KUOTA_TIDAK_CUKUP, $hasil->kode);
            $this->assertStringStartsWith('Sisa Cuti anda tidak mencukupi. Sisa cuti 2027: ', (string) $hasil->pesan);
            $this->assertStringContainsString('Sisa utang cuti 2028: ', (string) $hasil->pesan);
        }

        $this->assertSame($saldo[0] + $saldo[1] + $saldo[2], $hasil->detail['saldo_tahun_ini']);
        $this->assertSame(12 - $saldo[3], $hasil->detail['saldo_tahun_depan']);
    }

    /**
     * K1, K3–K6, K12–K13, K15, T11: potong N-2 → N-1 → N.
     *
     * @return iterable<string, array{array{int, int, int, int, int}, int, int, int, array{int, int, int, int, int}}>
     */
    public static function potong(): iterable
    {
        // [saldo], lcIni, lcDepan, status, [saldo akhir]
        yield 'K1 24 → kosong' => [[12, 6, 6, 0, 0], 24, 0, 1, [0, 0, 0, 0, 0]];

        yield 'K3 5 dari N-2' => [[12, 6, 6, 0, 0], 5, 0, 1, [12, 6, 1, 0, 0]];

        yield 'K4 8: N-2 habis, N-1 sisa 4' => [[12, 6, 6, 0, 0], 8, 0, 1, [12, 4, 0, 0, 0]];

        yield 'K5 15: sampai N' => [[12, 6, 6, 0, 0], 15, 0, 1, [9, 0, 0, 0, 0]];

        yield 'K6 N saja' => [[12, 0, 0, 0, 0], 12, 0, 1, [0, 0, 0, 0, 0]];

        yield 'K12 ditangguhkan' => [[12, 6, 6, 0, 0], 4, 0, 4, [12, 6, 2, 0, 4]];

        yield 'K13 lintas tahun: utang 3' => [[2, 0, 0, 0, 0], 2, 3, 1, [0, 0, 0, 3, 0]];

        yield 'K15 utang 10 + 2' => [[12, 6, 6, 10, 0], 0, 2, 1, [12, 6, 6, 12, 0]];

        yield 'T11 status 4 dengan porsi tahun depan: dibuang, utang tetap' => [[12, 6, 6, 0, 0], 4, 3, 4, [12, 6, 2, 0, 4]];

        yield 'T11b ditangguhkan bertambah dari nilai lama' => [[12, 6, 6, 5, 2], 3, 0, 4, [12, 6, 3, 5, 5]];
    }

    /**
     * @param array{int, int, int, int, int} $saldo
     * @param array{int, int, int, int, int} $akhir
     */
    #[DataProvider('potong')]
    public function testPotong(array $saldo, int $lcIni, int $lcDepan, int $status, array $akhir): void
    {
        $hasil = KuotaCuti::potong(new SaldoCuti(...$saldo), $lcIni, $lcDepan, $status, $this->aturan);

        $this->assertTrue($hasil->valid, (string) $hasil->pesan);
        $this->assertTrue((new SaldoCuti(...$akhir))->sama($hasil->detail['saldo']));
    }

    public function testK13LintasTahunDenganPorsiDariTanggal(): void
    {
        // 2027-12-30 Kamis .. 2028-01-05 Rabu: 2 hari kerja tahun ini + 3 tahun depan.
        $porsi = PorsiTahunCuti::pecah(1, '2027-12-30', '2028-01-05', self::TAHUN, [], $this->aturan);
        $this->assertSame(['tahun_ini' => 2, 'tahun_depan' => 3], $porsi);

        $saldo = new SaldoCuti(2, 0, 0, 0, 0);
        $this->assertTrue(KuotaCuti::periksa($saldo, 2, 3, 0, 0, self::TAHUN, $this->aturan)->valid);

        $potong = KuotaCuti::potong($saldo, 2, 3, StatusCuti::DISETUJUI, $this->aturan);
        $this->assertTrue((new SaldoCuti(0, 0, 0, 3, 0))->sama($potong->detail['saldo']));
    }

    /**
     * K16–K19, T14: refund kebalikan tepat, tanpa negatif, kelebihan di atas batas D8 dibuang (tanpa snapshot).
     *
     * @return iterable<string, array{array{int, int, int, int, int}, int, int, int, array{int, int, int, int, int}}>
     */
    public static function refund(): iterable
    {
        yield 'K16 refund K3 status 1' => [[12, 6, 1, 0, 0], 5, 0, 1, [12, 6, 6, 0, 0]];

        yield 'K17 refund K12 status 4' => [[12, 6, 2, 0, 4], 4, 0, 4, [12, 6, 6, 0, 0]];

        yield 'K18 kelebihan dibuang (total ≤ 24)' => [[12, 6, 5, 0, 0], 3, 0, 1, [12, 6, 6, 0, 0]];

        yield 'K19 utang 0 tidak negatif' => [[12, 6, 6, 0, 0], 0, 2, 1, [12, 6, 6, 0, 0]];

        yield 'T14 utang < lcTahunDepan → 0' => [[12, 6, 6, 1, 0], 0, 3, 1, [12, 6, 6, 0, 0]];

        yield 'refund K5 kembali ke N dulu' => [[9, 0, 0, 0, 0], 15, 0, 1, [12, 6, 6, 0, 0]];

        yield 'refund K13' => [[0, 0, 0, 3, 0], 2, 3, 1, [2, 0, 0, 0, 0]];

        yield 'ditangguhkan tidak negatif' => [[12, 6, 6, 0, 1], 4, 0, 4, [12, 6, 6, 0, 0]];
    }

    /**
     * @param array{int, int, int, int, int} $saldo
     * @param array{int, int, int, int, int} $akhir
     */
    #[DataProvider('refund')]
    public function testRefund(array $saldo, int $lcIni, int $lcDepan, int $status, array $akhir): void
    {
        $hasil = KuotaCuti::kembalikan(new SaldoCuti(...$saldo), $lcIni, $lcDepan, $status, $this->aturan);

        $this->assertTrue((new SaldoCuti(...$akhir))->sama($hasil), json_encode($hasil->keBaris()));
        $this->assertLessThanOrEqual(24, $hasil->total());
    }

    /**
     * K25–K26 (v1.3, Q-05): refund dengan batas per kolom dari snapshot `riwayat_cuti` — `max(batas D8, snapshot)`;
     * snapshot NULL (seluruhnya atau per kolom) → batas D8; snapshot di bawah D8 → batas D8.
     *
     * @return iterable<string, array{array{int, int, int, int, int}, array{?int, ?int, ?int}, int, int, int, array{int, int, int, int, int}}>
     */
    public static function refundSnapshot(): iterable
    {
        yield 'K25 snapshot impor (12,9,0): N-1 pulih sampai 9' => [[12, 4, 0, 0, 0], [12, 9, 0], 5, 0, 1, [12, 9, 0, 0, 0]];

        yield 'K26 snapshot NULL: batas D8, sisa ke N-2' => [[12, 5, 0, 0, 0], [null, null, null], 3, 0, 1, [12, 6, 2, 0, 0]];

        yield 'campuran: n1 NULL (batas 6), n2 terisi 9 (batas 9)' => [[12, 2, 3, 0, 0], [12, null, 9], 12, 0, 1, [12, 6, 9, 0, 0]];

        yield 'snapshot di bawah D8 → batas D8' => [[12, 4, 0, 0, 0], [10, 3, 2], 8, 0, 1, [12, 6, 6, 0, 0]];

        yield 'snapshot N-1 = 9 tetap membuang sisa di atas batas kolom' => [[12, 8, 6, 0, 0], [12, 9, 6], 5, 0, 1, [12, 9, 6, 0, 0]];

        yield 'status 4 + snapshot: ditangguhkan berkurang' => [[12, 4, 0, 0, 5], [12, 9, 0], 5, 0, 4, [12, 9, 0, 0, 0]];

        yield 'status 1 + snapshot: utang berkurang tanpa negatif' => [[0, 0, 0, 2, 0], [12, 9, 0], 2, 3, 1, [2, 0, 0, 0, 0]];
    }

    /**
     * @param array{int, int, int, int, int} $saldo
     * @param array{?int, ?int, ?int}        $snap
     * @param array{int, int, int, int, int} $akhir
     */
    #[DataProvider('refundSnapshot')]
    public function testRefundDenganSnapshot(array $saldo, array $snap, int $lcIni, int $lcDepan, int $status, array $akhir): void
    {
        $hasil = KuotaCuti::kembalikan(new SaldoCuti(...$saldo), $lcIni, $lcDepan, $status, $this->aturan, new SnapshotSaldoCuti(...$snap));

        $this->assertTrue((new SaldoCuti(...$akhir))->sama($hasil), json_encode($hasil->keBaris()));
    }

    public function testRefundTanpaSnapshotSamaDenganSnapshotKosong(): void
    {
        $saldo = new SaldoCuti(12, 5, 0, 1, 2);

        foreach ([[3, 0, 1], [4, 0, 4], [30, 2, 1]] as [$lcIni, $lcDepan, $status]) {
            $this->assertTrue(
                KuotaCuti::kembalikan($saldo, $lcIni, $lcDepan, $status, $this->aturan)
                    ->sama(KuotaCuti::kembalikan($saldo, $lcIni, $lcDepan, $status, $this->aturan, new SnapshotSaldoCuti())),
            );
        }
    }

    public function testSnapshotDariBarisMengonversiStringDanMenjagaNull(): void
    {
        $snap = SnapshotSaldoCuti::dariBaris(['sisa_cuti' => '12', 'sisa_cuti_n1' => '9', 'sisa_cuti_n2' => null]);

        $this->assertSame(['n' => 12, 'n1' => 9, 'n2' => 6], $snap->batas($this->aturan));
        $this->assertSame(['n' => 12, 'n1' => 6, 'n2' => 6], SnapshotSaldoCuti::dariBaris([])->batas($this->aturan));
        $this->assertSame(['n' => 12, 'n1' => 6, 'n2' => 6], SnapshotSaldoCuti::dariBaris(['sisa_cuti' => '', 'sisa_cuti_n1' => '0'])->batas($this->aturan));
    }

    public function testK16K17PotongLaluRefundKembaliKeAwal(): void
    {
        $awal = new SaldoCuti(12, 6, 6, 0, 0);

        foreach ([[5, 0, 1], [4, 0, 4], [24, 0, 1], [2, 3, 1], [15, 0, 1]] as [$lcIni, $lcDepan, $status]) {
            $potong = KuotaCuti::potong($awal, $lcIni, $lcDepan, $status, $this->aturan)->detail['saldo'];
            $this->assertTrue($awal->sama(KuotaCuti::kembalikan($potong, $lcIni, $lcDepan, $status, $this->aturan)));
        }
    }

    public function testK20SaldoTidakTerdaftar(): void
    {
        $hasil = KuotaCuti::periksa(null, 1, 0, 0, 0, self::TAHUN, $this->aturan);

        $this->assertFalse($hasil->valid);
        $this->assertSame(KuotaCuti::KODE_SALDO_TIDAK_TERDAFTAR, $hasil->kode);
        $this->assertStringStartsWith('Saldo cuti anda tidak terdaftar didalam sistem.', (string) $hasil->pesan);
    }

    public function testK21JenisSelainTahunanTidakMemotongKuota(): void
    {
        foreach ([2, 3, 4, 5, 6] as $jenis) {
            $this->assertFalse(EfekFinalCuti::perluPotongSaldo($jenis, StatusCuti::DISETUJUI), "jenis {$jenis}");
            $this->assertFalse(EfekFinalCuti::perluPotongSaldo($jenis, StatusCuti::DITANGGUHKAN), "jenis {$jenis}");
        }

        $this->assertTrue(EfekFinalCuti::perluPotongSaldo(1, StatusCuti::DISETUJUI));
        $this->assertTrue(EfekFinalCuti::perluPotongSaldo(1, StatusCuti::DITANGGUHKAN));
        $this->assertFalse(EfekFinalCuti::perluPotongSaldo(1, StatusCuti::DISETUJUI_AL));
        $this->assertFalse(EfekFinalCuti::perluPotongSaldo(1, StatusCuti::DITOLAK));
    }

    public function testT12PotongSaatSaldoKurangGagalTanpaMengubahSaldo(): void
    {
        $saldo = new SaldoCuti(1, 0, 0, 0, 0);
        $hasil = KuotaCuti::potong($saldo, 3, 0, StatusCuti::DISETUJUI, $this->aturan);

        $this->assertFalse($hasil->valid);
        $this->assertSame(KuotaCuti::KODE_KUOTA_TIDAK_CUKUP, $hasil->kode);
        $this->assertSame(2, $hasil->detail['kurang']);
        $this->assertArrayNotHasKey('saldo', $hasil->detail);
        $this->assertTrue($saldo->sama(new SaldoCuti(1, 0, 0, 0, 0)));

        $utang = KuotaCuti::potong(new SaldoCuti(12, 6, 6, 11, 0), 0, 2, StatusCuti::DISETUJUI, $this->aturan);
        $this->assertFalse($utang->valid);
        $this->assertSame(KuotaCuti::KODE_UTANG_MELEBIHI_BATAS, $utang->kode);
    }

    /**
     * T13 — jaminan data impor: untuk saldo berbentuk ternormalisasi legacy (N diisi dulu sampai 12, lalu N-1 sampai 6,
     * lalu N-2) dan `lc ≤ total`, potong N-2 → N-1 → N menghasilkan saldo yang sama dengan normalisasi e-sign legacy
     * `total − lc` → N 12, N-1 6, N-2 sisa (`L_cuti.php:3867-3886`). Untuk saldo yang TIDAK ternormalisasi (mis.
     * (5,6,6)) kedua rumus memang berbeda — bentuk itu tidak pernah dihasilkan legacy maupun v2.
     */
    public function testT13ParitasDenganNormalisasiLegacyEsign(): void
    {
        $kasus = 0;

        for ($n = 0; $n <= 12; $n++) {
            for ($n1 = 0; $n1 <= 6; $n1++) {
                for ($n2 = 0; $n2 <= 6; $n2++) {
                    $ternormalisasi = ($n === 12 || ($n1 === 0 && $n2 === 0)) && ($n1 === 6 || $n2 === 0);

                    if (! $ternormalisasi) {
                        continue;
                    }

                    $saldo = new SaldoCuti($n, $n1, $n2);

                    for ($lc = 0; $lc <= $saldo->total(); $lc++) {
                        $v2 = KuotaCuti::potong($saldo, $lc, 0, StatusCuti::DISETUJUI, $this->aturan)->detail['saldo'];
                        $this->assertTrue(
                            self::normalisasiLegacy($saldo, $lc)->sama($v2),
                            "({$n},{$n1},{$n2}) lc {$lc}: v2 " . json_encode($v2->keBaris()),
                        );
                        $kasus++;
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $kasus);
    }

    public function testStatusSelainFinalDitolakProgram(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KuotaCuti::potong(new SaldoCuti(12), 1, 0, StatusCuti::DISETUJUI_AL, $this->aturan);
    }

    public function testRefundStatusDitolakDitolakProgram(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KuotaCuti::kembalikan(new SaldoCuti(12), 1, 0, StatusCuti::DITOLAK, $this->aturan);
    }

    public function testSaldoDariBarisDanBatasD8(): void
    {
        $saldo = SaldoCuti::dariBaris(['sisa_cuti' => '12', 'sisa_cuti_n1' => null, 'sisa_cuti_n2' => 7, 'utang_cuti' => 13, 'sisa_cuti_ditangguhkan' => -1]);

        $this->assertSame(['sisa_cuti' => 12, 'sisa_cuti_n1' => 0, 'sisa_cuti_n2' => 7, 'utang_cuti' => 13, 'sisa_cuti_ditangguhkan' => -1], $saldo->keBaris());
        $this->assertSame(
            ['sisa_cuti_n2' => 'Nilai maksimal 6 hari.', 'utang_cuti' => 'Nilai maksimal 12 hari.', 'sisa_cuti_ditangguhkan' => 'Nilai tidak boleh negatif.'],
            $saldo->periksaBatas($this->aturan),
        );
        $this->assertSame([], (new SaldoCuti(12, 6, 6, 12, 30))->periksaBatas($this->aturan));
        $this->assertSame(0, (new SaldoCuti(0, 0, 0, 13))->saldoTahunDepan($this->aturan));
        $this->assertInstanceOf(HasilKalkulasi::class, KuotaCuti::periksa($saldo, 0, 0, 0, 0, self::TAHUN, $this->aturan));
    }

    /**
     * Normalisasi legacy `sp_process_esign` `L:3867-3886` (disalin persis untuk pembanding).
     */
    private static function normalisasiLegacy(SaldoCuti $psc, int $lcCurrYear): SaldoCuti
    {
        $newTotalSC = $psc->n + $psc->n1 + $psc->n2 - $lcCurrYear;

        if ($newTotalSC > 12) {
            $sisa = 12;
            $newTotalSC -= 12;

            if ($newTotalSC > 6) {
                $n1 = 6;
                $newTotalSC -= 6;
                $n2 = $newTotalSC;
            } else {
                $n1 = $newTotalSC;
                $n2 = 0;
            }
        } else {
            $sisa = $newTotalSC;
            $n1   = 0;
            $n2   = 0;
        }

        return new SaldoCuti($sisa, $n1, $n2, $psc->utang, $psc->ditangguhkan);
    }

    /**
     * [V2] `sisa_setelah_tahun_ini`/`sisa_setelah_tahun_depan` (PRD §6.11.3, review Paket 2): saldo − menunggu − diajukan,
     * dihitung di server supaya FE tidak menghitung. Negatif = kekurangan (cukup=false).
     */
    public function testPeriksaMemuatSisaSetelahPengajuanTahunIniDanDepan(): void
    {
        $saldo = new SaldoCuti(12, 6, 6, 0, 0);

        $cukup = KuotaCuti::periksa($saldo, 5, 0, 2, 0, self::TAHUN, $this->aturan);
        $this->assertTrue($cukup->valid);
        $this->assertSame(24, $cukup->detail['saldo_tahun_ini']);
        $this->assertSame(17, $cukup->detail['sisa_setelah_tahun_ini']);
        $this->assertSame($saldo->saldoTahunDepan($this->aturan), $cukup->detail['saldo_tahun_depan']);
        $this->assertSame($saldo->saldoTahunDepan($this->aturan), $cukup->detail['sisa_setelah_tahun_depan'], 'tanpa porsi tahun depan sisa = saldo');
    }

    public function testSisaSetelahPengajuanLintasTahunMemakaiPorsiTahunDepan(): void
    {
        $saldo = new SaldoCuti(12, 6, 6, 0, 0);

        $hasil = KuotaCuti::periksa($saldo, 3, 4, 1, 2, self::TAHUN, $this->aturan);

        $this->assertTrue($hasil->valid);
        $this->assertSame(24 - 1 - 3, $hasil->detail['sisa_setelah_tahun_ini']);
        $this->assertSame($saldo->saldoTahunDepan($this->aturan) - 2 - 4, $hasil->detail['sisa_setelah_tahun_depan']);
    }

    public function testSisaSetelahPengajuanNegatifBilaKuotaTidakCukup(): void
    {
        $hasil = KuotaCuti::periksa(new SaldoCuti(2, 0, 0, 0, 0), 5, 0, 0, 0, self::TAHUN, $this->aturan);

        $this->assertFalse($hasil->valid);
        $this->assertSame(-3, $hasil->detail['sisa_setelah_tahun_ini']);
    }
}
