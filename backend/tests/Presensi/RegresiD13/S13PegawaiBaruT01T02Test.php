<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S13 — pegawai baru, CR-083 (keputusan Horii 29-10: T-01 dan T-02 ikut legacy [K]; riset statis UP1-11 B-29/B-31):
 *
 * - T-01: populasi rekap `DATE(created_at) <= akhir` (`L_employee.php:6414`), tetapi cuti/TB/konket/presensi hanya dimuat
 *   bila `peg.created_at < '<akhir periode BKN>'` (`L_presensi.php:12209`, `:12283`, `:12346`, `:12383`). Pegawai yang
 *   dibuat pada hari terakhir periode, pukul berapa pun, masuk rekap dengan semua hari kerja TK; sehari sebelumnya → data
 *   dimuat seperti biasa.
 * - Jalur pegawai baru yang tidak terdampak T-02 (CPNS ber-TMT, PPPK dengan TMT setelah hari kerja pertama, PNS) tetap.
 *   Cabang T-02 sendiri (CPNS tanpa TMT) tidak dapat dibentuk lewat basis data (`riwayat_kp.tmtsk` NOT NULL), diuji di
 *   `unit/Presensi/Tukin/LewatiLoopHarianTest`.
 *
 * @internal
 */
#[Group('d13')]
final class S13PegawaiBaruT01T02Test extends DatabaseTestCase
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

    public function testT01DibuatPadaHariTerakhirPeriodeSemuaHariKerjaTk(): void
    {
        foreach (['2026-09-15 00:00:00', '2026-09-15 16:30:00', '2026-09-15 23:59:59'] as $dibuat) {
            $nip = $this->pegawaiDibuat($dibuat);

            $laporan = $this->laporanD13($nip);
            $harian  = self::harianD13($laporan);

            $this->assertSame(22, $laporan['jumlah']['TK'], "{$dibuat}: semua hari kerja TK");
            $this->assertSame(['13.20', '13.20'], [$laporan['rincian']['TK'], $laporan['total_presensi']], $dibuat);
            $this->assertSame('TK', $harian['2026-08-18']['status'], "{$dibuat}: cuti tidak dimuat");
            $this->assertSame('TK', $harian['2026-08-19']['status'], "{$dibuat}: konket tidak dimuat");
            $this->assertSame('TK', $harian['2026-08-20']['status'], "{$dibuat}: TB tidak dimuat");
            $this->assertSame('TK', $harian['2026-08-17']['status'], "{$dibuat}: presensi tidak dimuat");
            $this->assertRekapSamaLaporanD13($nip, $laporan);
        }
    }

    public function testT01DibuatSehariSebelumAkhirPeriodeDataDimuat(): void
    {
        $nip = $this->pegawaiDibuat('2026-09-14 23:59:59');

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        $this->assertSame([], array_filter($laporan['jumlah']), 'tanpa TK');
        $this->assertSame('0.00', $laporan['total_presensi']);
        $this->assertSame('cuti', $harian['2026-08-18']['status']);
        $this->assertSame('konket', $harian['2026-08-19']['status']);
        $this->assertSame('tugas_belajar', $harian['2026-08-20']['status']);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testJalurPegawaiBaruLainTidakBerubah(): void
    {
        // CPNS ber-TMT (≤ awal periode): semua hari dihitung; satu hari tanpa presensi → TK.
        $cpns = $this->pegawaiHadirD13(['2026-08-24']);
        $this->db->table('riwayat_kp')->insert(['nip' => $cpns, 'tmtsk' => '2026-08-01', 'gol_ruang' => 'CPNS III/a', 'status' => 1]);
        $lap = $this->laporanD13($cpns);
        $this->assertSame(['TK' => 1], array_filter($lap['jumlah']), 'CPNS ber-TMT');
        $this->assertSame('0.60', $lap['total_presensi']);
        $this->assertRekapSamaLaporanD13($cpns, $lap);

        // PNS biasa: sama.
        $pns = $this->pegawaiHadirD13(['2026-08-24']);
        $this->db->table('riwayat_kp')->insert(['nip' => $pns, 'tmtsk' => '2020-04-01', 'gol_ruang' => 'III/a', 'status' => 1]);
        $this->assertSame('0.60', $this->laporanD13($pns)['total_presensi'], 'PNS');

        // PPPK TMT 25 Agu (setelah hari kerja pertama 17 Agu): hari sebelum TMT dilewati; 26 Agu tanpa presensi → TK.
        $pppk = $this->pegawaiRekap($this->satkerD13, ['id_jenis_pegawai' => 6], ['tmtsk' => '2026-08-25', 'gol_pppk' => 'V']);
        $this->hadirSepanjangPeriode($pppk);
        $this->db->table('dh_online')->where('nip', $pppk)->whereIn('tgl', ['2026-08-17', '2026-08-18', '2026-08-24', '2026-08-26'])->delete();
        $lap   = $this->laporanD13($pppk);
        $hrPpk = self::harianD13($lap);
        $this->assertSame(['TK' => 1], array_filter($lap['jumlah']), 'PPPK: hanya 26 Agu');
        $this->assertSame('TK', $hrPpk['2026-08-26']['status']);
        $this->assertSame('0.60', $lap['total_presensi']);
    }

    /**
     * Pegawai populasi rekap dengan `created_at` tertentu, hadir semua hari kerja, plus cuti 18 Agu, konket 19 Agu, TB 20
     * Agu.
     */
    private function pegawaiDibuat(string $createdAt): string
    {
        $nip = $this->pegawaiRekap($this->satkerD13, ['created_at' => $createdAt]);
        $this->hadirSepanjangPeriode($nip);
        $this->cutiRekap($nip, '2026-08-18', '2026-08-18');
        $this->konketRekap($nip, '2026-08-19', 1);
        $this->tbRekap($nip, '2026-08-20', '2026-08-20');

        return $nip;
    }
}
