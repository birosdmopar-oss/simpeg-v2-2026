<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\StatusHarian;

use App\Libraries\Layanan\StatusHarian\KeputusanStatusHarian as K;
use App\Libraries\Layanan\StatusHarian\PenentuStatusHarian;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-062 — aturan murni status harian TB/CLTN (PRD Fase 6 §7.9, F6-20, Q6-24..Q6-27; AC-11.1..11.5, 11.7..11.9).
 *
 * @internal
 */
final class PenentuStatusHarianTest extends CIUnitTestCase
{
    /**
     * @param list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}> $perpanjangan
     *
     * @return array{status: int, tgl_mulai: string, tgl_akhir: string, perpanjangan: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}
     */
    private static function tb(string $mulai, string $akhir, array $perpanjangan = [], int $status = 1): array
    {
        return ['status' => $status, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'perpanjangan' => $perpanjangan];
    }

    /**
     * @return array{status: int, tgl_mulai: string, tgl_akhir: string}
     */
    private static function cltn(string $mulai, string $akhir, int $status = 1): array
    {
        return ['status' => $status, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir];
    }

    /**
     * @return array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}
     */
    private static function perpan(string $mulai, string $akhir): array
    {
        return ['tgl_mulai_perpan' => $mulai, 'tgl_akhir_perpan' => $akhir];
    }

    public function testAc111TbDisetujuiSebelumMulaiAktifTepatPadaTanggalMulai(): void
    {
        $tb = [self::tb('2026-11-10', '2027-11-09')];

        $this->assertNull(PenentuStatusHarian::tentukan(1, $tb, [], '2026-11-09')->statusBaru, 'sehari sebelum mulai tidak berubah');
        $hari = PenentuStatusHarian::tentukan(1, $tb, [], '2026-11-10');
        $this->assertSame(2, $hari->statusBaru);
        $this->assertSame(K::ALASAN_TB_AKTIF, $hari->alasan);
    }

    public function testAc112SetelahTanggalAkhirKembaliKeAktif(): void
    {
        $tb = [self::tb('2025-11-10', '2026-11-09')];

        $this->assertNull(PenentuStatusHarian::tentukan(2, $tb, [], '2026-11-09')->statusBaru, 'hari terakhir masih TB (inklusif)');
        $hari = PenentuStatusHarian::tentukan(2, $tb, [], '2026-11-10');
        $this->assertSame(1, $hari->statusBaru);
        $this->assertSame(K::ALASAN_PULIH_TB, $hari->alasan);
    }

    public function testAc113PerpanjanganBersambungTidakBerkedip(): void
    {
        $tb = [self::tb('2025-11-10', '2026-11-09', [self::perpan('2026-11-10', '2027-11-09')])];

        foreach (['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11'] as $tanggal) {
            $k = PenentuStatusHarian::tentukan(2, $tb, [], $tanggal);
            $this->assertNull($k->statusBaru, $tanggal);
            $this->assertFalse($k->celahTb, $tanggal);
        }
    }

    public function testPerpanjanganDenganCelahKembaliKeAktifLaluTbLagi(): void
    {
        $tb = [self::tb('2025-11-10', '2026-11-09', [self::perpan('2026-11-20', '2027-11-19')])];

        $celah = PenentuStatusHarian::tentukan(2, $tb, [], '2026-11-10');
        $this->assertSame(1, $celah->statusBaru);
        $this->assertTrue($celah->celahTb);

        $lagi = PenentuStatusHarian::tentukan(1, $tb, [], '2026-11-20');
        $this->assertSame(2, $lagi->statusBaru);
    }

    public function testAc114StatusSelain123TidakDisentuh(): void
    {
        $tb   = [self::tb('2026-01-01', '2026-12-31')];
        $cltn = [self::cltn('2026-01-01', '2026-12-31')];

        foreach ([4, 5, 12] as $status) {
            $k = PenentuStatusHarian::tentukan($status, $tb, $cltn, '2026-06-01');
            $this->assertFalse($k->berubah(), (string) $status);
            $this->assertFalse($k->bentrok, (string) $status);
            $this->assertFalse($k->tanpaRiwayat, (string) $status);
        }
    }

    public function testAc115IdempotenSetelahDiterapkan(): void
    {
        $tb = [self::tb('2026-01-01', '2026-12-31')];

        $pertama = PenentuStatusHarian::tentukan(1, $tb, [], '2026-06-01');
        $this->assertSame(2, $pertama->statusBaru);
        $this->assertFalse(PenentuStatusHarian::tentukan(2, $tb, [], '2026-06-01')->berubah());

        $selesai = [self::tb('2025-01-01', '2025-12-31')];
        $this->assertSame(1, PenentuStatusHarian::tentukan(2, $selesai, [], '2026-06-01')->statusBaru);
        $this->assertFalse(PenentuStatusHarian::tentukan(1, $selesai, [], '2026-06-01')->berubah());
    }

    public function testCltnAktifMenjadi3DanPulihSetelahBerakhir(): void
    {
        $aktif = [self::cltn('2026-06-01', '2027-05-31')];
        $this->assertSame(3, PenentuStatusHarian::tentukan(1, [], $aktif, '2026-06-01')->statusBaru);
        $this->assertNull(PenentuStatusHarian::tentukan(1, [], $aktif, '2026-05-31')->statusBaru);
        $this->assertFalse(PenentuStatusHarian::tentukan(3, [], $aktif, '2027-05-31')->berubah());

        $pulih = PenentuStatusHarian::tentukan(3, [], $aktif, '2027-06-01');
        $this->assertSame(1, $pulih->statusBaru);
        $this->assertSame(K::ALASAN_PULIH_CLTN, $pulih->alasan);
    }

    public function testAc117BentrokStatusSaatIni2Atau3TidakBerkedip(): void
    {
        $tb   = [self::tb('2026-01-01', '2026-12-31')];
        $cltn = [self::cltn('2026-06-01', '2027-05-31')];

        foreach ([2, 3] as $status) {
            $k = PenentuStatusHarian::tentukan($status, $tb, $cltn, '2026-07-01');
            $this->assertFalse($k->berubah(), (string) $status);
            $this->assertTrue($k->bentrok, (string) $status);
        }
    }

    public function testAc117BentrokDariStatus1MemilihMulaiPalingAkhirSeriTb(): void
    {
        $tb = [self::tb('2026-01-01', '2026-12-31')];

        $cltnLebihBaru = PenentuStatusHarian::tentukan(1, $tb, [self::cltn('2026-06-01', '2027-05-31')], '2026-07-01');
        $this->assertSame(3, $cltnLebihBaru->statusBaru);
        $this->assertTrue($cltnLebihBaru->bentrok);

        $tbLebihBaru = PenentuStatusHarian::tentukan(1, [self::tb('2026-06-01', '2026-12-31')], [self::cltn('2026-01-01', '2027-05-31')], '2026-07-01');
        $this->assertSame(2, $tbLebihBaru->statusBaru);

        $seri = PenentuStatusHarian::tentukan(1, $tb, [self::cltn('2026-01-01', '2026-12-31')], '2026-07-01');
        $this->assertSame(2, $seri->statusBaru);
        $this->assertTrue($seri->bentrok);
    }

    public function testBentrokMemakaiMulaiSegmenAktifBukanInduk(): void
    {
        $tb   = [self::tb('2025-01-01', '2025-12-31', [self::perpan('2026-03-01', '2026-12-31')])];
        $cltn = [self::cltn('2026-02-01', '2026-12-31')];

        $this->assertSame(2, PenentuStatusHarian::tentukan(1, $tb, $cltn, '2026-07-01')->statusBaru, 'segmen perpanjangan (03-01) lebih baru dari CLTN (02-01)');
    }

    public function testBentrokSalahSatuBerakhirPindahKeJenisYangMasihAktif(): void
    {
        $tb   = [self::tb('2026-01-01', '2026-06-30')];
        $cltn = [self::cltn('2026-03-01', '2027-02-28')];

        $this->assertFalse(PenentuStatusHarian::tentukan(2, $tb, $cltn, '2026-06-30')->berubah());
        $pindah = PenentuStatusHarian::tentukan(2, $tb, $cltn, '2026-07-01');
        $this->assertSame(3, $pindah->statusBaru, 'TB berakhir, CLTN masih aktif');
        $this->assertFalse($pindah->bentrok);
    }

    public function testAc118CltnStatus4TidakMengubahStatus(): void
    {
        $this->assertFalse(PenentuStatusHarian::tentukan(1, [], [self::cltn('2026-01-01', '2026-12-31', 4)], '2026-06-01')->berubah());

        $tanpaPendukung = PenentuStatusHarian::tentukan(3, [], [self::cltn('2025-01-01', '2025-12-31', 4)], '2026-06-01');
        $this->assertFalse($tanpaPendukung->berubah());
        $this->assertTrue($tanpaPendukung->tanpaRiwayat, 'status 4 bukan riwayat pendukung');
    }

    public function testAc119StatusTanpaRiwayatPendukungTidakDisentuh(): void
    {
        foreach ([2, 3] as $status) {
            $k = PenentuStatusHarian::tentukan($status, [], [], '2026-06-01');
            $this->assertFalse($k->berubah(), (string) $status);
            $this->assertTrue($k->tanpaRiwayat, (string) $status);
        }

        $this->assertFalse(PenentuStatusHarian::tentukan(1, [], [], '2026-06-01')->tanpaRiwayat);
    }

    public function testStatus2DenganRiwayatBelumMulaiSajaTidakDianggapPendukung(): void
    {
        $k = PenentuStatusHarian::tentukan(2, [self::tb('2026-09-01', '2027-08-31')], [], '2026-06-01');

        $this->assertFalse($k->berubah());
        $this->assertTrue($k->tanpaRiwayat);
    }

    public function testStatus2PendukungHarusTbBukanCltn(): void
    {
        $k = PenentuStatusHarian::tentukan(2, [], [self::cltn('2025-01-01', '2025-12-31')], '2026-06-01');

        $this->assertFalse($k->berubah());
        $this->assertTrue($k->tanpaRiwayat);
    }

    public function testTbBukanStatus1TidakDihitung(): void
    {
        foreach ([0, 2, 3] as $status) {
            $k = PenentuStatusHarian::tentukan(1, [self::tb('2026-01-01', '2026-12-31', [], $status)], [], '2026-06-01');
            $this->assertFalse($k->berubah(), (string) $status);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tanggalTidakSah(): array
    {
        return ['bulan 13' => ['2026-13-01'], 'format lain' => ['01-11-2026'], 'kosong' => ['']];
    }

    #[DataProvider('tanggalTidakSah')]
    public function testTanggalTidakSahDitolak(string $tanggal): void
    {
        $this->expectException(InvalidArgumentException::class);
        PenentuStatusHarian::tentukan(1, [], [], $tanggal);
    }
}
