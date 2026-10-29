<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S01 — Hari libur + akhir pekan (QASMTASK-088 / QAFUNC-006, PRD 5C §7.6): hari libur aktif dan Sabtu/Minggu bukan
 * hari kerja, tanpa potongan; libur yang jatuh di akhir pekan tidak dihitung ganda; libur nonaktif tetap hari kerja.
 * Legacy [K] `L_presensi.php:12749-12757` (rekap `laporan_tukin_us_skp`).
 *
 * Test lama yang dicakup: `Presensi/TukinData/PeriodeDanLiburTukinTest::testHariLiburAktifDalamRentangDanAkhirPekan`,
 * `unit/Presensi/Tukin/TukinTest` (hari kerja), `PrecedenceKombinasiTest` (libur + cuti).
 *
 * @internal
 */
#[Group('d13')]
final class S01LiburAkhirPekanTest extends DatabaseTestCase
{
    use RegresiD13UjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanD13();
    }

    protected function tearDown(): void
    {
        $this->bersihkanD13();
        parent::tearDown();
    }

    public function testLiburAktifDanAkhirPekanTanpaPotongan(): void
    {
        $this->libur('2026-08-19');
        $this->libur('2026-08-22'); // Sabtu: libur di akhir pekan
        $nip = $this->pegawaiHadirD13(['2026-08-19']);

        $laporan = $this->laporanD13($nip);

        $this->assertSame(['0.00', '0.00'], [$laporan['rincian']['TK'] ?? '0.00', $laporan['total_presensi']], 'libur Rabu tanpa presensi tidak dipotong');
        $this->assertSame(0, $laporan['jumlah']['TK'] ?? 0);

        foreach (self::harianD13($laporan) as $tgl => $b) {
            $this->assertSame('0.00', $b['potongan'], "tanpa potongan {$tgl}");
        }

        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testLiburNonaktifTetapHariKerja(): void
    {
        $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-08-20', 'tgl_akhir' => '2026-08-20', 'nama_libur' => 'Libur dibatalkan', 'status' => 2]);
        $nip = $this->pegawaiHadirD13(['2026-08-20']);

        $laporan = $this->laporanD13($nip);

        $this->assertSame('TK', self::harianD13($laporan)['2026-08-20']['status']);
        $this->assertSame(['0.60', '0.60'], [$laporan['rincian']['TK'], $laporan['total_presensi']], '1 hari TK × 3% × 0,2');
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testAkhirPekanTanpaPresensiTidakDipotong(): void
    {
        $nip = $this->pegawaiHadirD13();
        $this->hadir($nip, '2026-08-23', '07:45:00', '15:00:00'); // Minggu: presensi terlambat tidak dinilai

        $laporan = $this->laporanD13($nip);

        $this->assertSame('0.00', $laporan['total_presensi'], '22 hari kerja hadir; Sabtu/Minggu tidak dinilai');
        $this->assertSame([], array_filter($laporan['jumlah']), 'tanpa kejadian TL/PSW/TK');
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }
}
