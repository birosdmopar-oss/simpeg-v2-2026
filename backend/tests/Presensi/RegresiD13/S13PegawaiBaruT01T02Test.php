<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S13 — pegawai baru, CR-083 (keputusan Horii 29-10 dan 30-10: T-01, T-02, PPPK tanpa TMT ikut legacy [K]; riset
 * statis UP1-11 B-29/B-31):
 *
 * - T-01: populasi rekap `DATE(created_at) <= akhir` (`L_employee.php:6414`), tetapi cuti/TB/konket/presensi hanya dimuat
 *   bila `peg.created_at < '<akhir periode BKN>'` (`L_presensi.php:12209`, `:12283`, `:12346`, `:12383`). Pegawai yang
 *   dibuat pada hari terakhir periode (jam dinding **WIB**, pukul berapa pun) masuk rekap dengan semua hari kerja TK.
 *   `created_at` v2 = UTC: hari terakhir 15-09 WIB = `[2026-09-14 17:00:00, 2026-09-15 17:00:00)` UTC (GAP-2).
 * - PPPK tanpa TMT: seluruh hari loop dilewati; data tidak sah presensi/konketnya tidak dibaca; pemutihan TB tetap.
 * - Jalur yang tidak terdampak (CPNS ber-TMT, PPPK dengan TMT setelah hari kerja pertama, PNS) tetap. Cabang CPNS tanpa
 *   TMT (T-02) tidak dapat dibentuk lewat basis data (`riwayat_kp.tmtsk` NOT NULL), diuji di
 *   `unit/Presensi/Tukin/LewatiLoopHarianTest`.
 *
 * Fixture `pegawaiRekap` tanpa SKP → potongan SKP `SKP_TIDAK_ADA` (32%).
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

    public function testT01DibuatPadaHariTerakhirPeriodeWibSemuaHariKerjaTk(): void
    {
        // UTC → WIB: 15-09 00:00, 06:59:59, 16:30, 23:59:59.
        foreach (['2026-09-14 17:00:00', '2026-09-14 23:59:59', '2026-09-15 09:30:00', '2026-09-15 16:59:59'] as $dibuat) {
            $nip = $this->pegawaiDibuat($dibuat);

            $laporan = $this->laporanD13($nip);
            $harian  = self::harianD13($laporan);

            $this->assertSame(22, $laporan['jumlah']['TK'], "{$dibuat}: semua hari kerja TK");
            $this->assertSame(['13.20', '13.20', '32.00', '45.20'], [$laporan['rincian']['TK'], $laporan['total_presensi'], $laporan['total_skp'], $laporan['total_potongan']], $dibuat);

            foreach (['2026-08-17' => 'presensi', '2026-08-18' => 'cuti', '2026-08-19' => 'konket', '2026-08-20' => 'TB'] as $tgl => $data) {
                $this->assertSame(['TK', null, null], [$harian[$tgl]['status'], $harian[$tgl]['jam_masuk'], $harian[$tgl]['kategori_konket']], "{$dibuat}: {$data} tidak dimuat/ditampilkan");
            }

            $this->assertStringNotContainsString('Cuti', $laporan['keterangan'], "{$dibuat}: tanpa keterangan cuti");
            $this->assertRekapSamaLaporanD13($nip, $laporan);
        }
    }

    public function testT01BatasZonaWaktu(): void
    {
        // 14-09 23:59:59 WIB: data dimuat.
        $sebelum = $this->pegawaiDibuat('2026-09-14 16:59:59');
        $laporan = $this->laporanD13($sebelum);
        $harian  = self::harianD13($laporan);
        $this->assertSame([], array_filter($laporan['jumlah']), 'tanpa TK');
        $this->assertSame(['0.00', '32.00', '32.00'], [$laporan['total_presensi'], $laporan['total_skp'], $laporan['total_potongan']]);
        $this->assertSame(['cuti', 'konket', 'tugas_belajar'], [$harian['2026-08-18']['status'], $harian['2026-08-19']['status'], $harian['2026-08-20']['status']]);
        $this->assertRekapSamaLaporanD13($sebelum, $laporan);

        // 16-09 00:00–06:59:59 WIB (tanggal UTC masih 15-09): di luar populasi rekap.
        foreach (['2026-09-15 17:00:00', '2026-09-15 23:59:59'] as $dibuat) {
            $nip   = $this->pegawaiDibuat($dibuat);
            $rekap = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerD13, 'per_page' => '100']);
            $this->assertNotContains($nip, array_column($rekap['items'], 'nip'), "{$dibuat}: dibuat 16-09 WIB");
        }
    }

    public function testT01PeriodeBknBerakhirSelainTanggal15(): void
    {
        $this->barisBkn(2026, 9, '08-15', '09-14');
        $nip = $this->pegawaiDibuat('2026-09-14 03:00:00'); // 14-09 10:00 WIB = hari terakhir periode BKN

        $laporan = $this->laporanD13($nip);
        $hk      = count(HariKerja::daftar(new PeriodeTukin('2026-08-15', '2026-09-14'), []));
        $this->assertSame(['awal' => '2026-08-15', 'akhir' => '2026-09-14'], array_intersect_key($laporan['periode'], ['awal' => 1, 'akhir' => 1]));
        $this->assertSame($hk, $laporan['jumlah']['TK'], 'semua hari kerja periode BKN TK');
        $this->assertSame('32.00', $laporan['total_skp']);
        $this->assertRekapSamaLaporanD13($nip, $laporan);

        // Tanggal 15-09 bukan lagi hari terakhir: dibuat 15-09 WIB → di luar populasi, bukan T-01.
        $lewat = $this->pegawaiDibuat('2026-09-14 18:00:00');
        $rekap = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerD13, 'per_page' => '100']);
        $this->assertNotContains($lewat, array_column($rekap['items'], 'nip'));
    }

    public function testJalurPegawaiBaruLainTidakBerubah(): void
    {
        // CPNS ber-TMT (≤ awal periode): semua hari dihitung; satu hari tanpa presensi → TK.
        $cpns = $this->pegawaiHadirD13(['2026-08-24']);
        $this->db->table('riwayat_kp')->insert(['nip' => $cpns, 'tmtsk' => '2026-08-01', 'gol_ruang' => 'CPNS III/a', 'status' => 1]);
        $lap = $this->laporanD13($cpns);
        $this->assertSame(['TK' => 1], array_filter($lap['jumlah']), 'CPNS ber-TMT');
        $this->assertSame(['0.60', '32.00', '32.60'], [$lap['total_presensi'], $lap['total_skp'], $lap['total_potongan']]);
        $this->assertRekapSamaLaporanD13($cpns, $lap);

        // PNS biasa: sama.
        $pns = $this->pegawaiHadirD13(['2026-08-24']);
        $this->db->table('riwayat_kp')->insert(['nip' => $pns, 'tmtsk' => '2020-04-01', 'gol_ruang' => 'III/a', 'status' => 1]);
        $this->assertSame(['0.60', '32.60'], [$this->laporanD13($pns)['total_presensi'], $this->laporanD13($pns)['total_potongan']], 'PNS');

        // PPPK TMT 25 Agu (setelah hari kerja pertama 17 Agu): hari sebelum TMT dilewati; 26 Agu tanpa presensi → TK.
        $pppk = $this->pegawaiRekap($this->satkerD13, ['id_jenis_pegawai' => 6], ['tmtsk' => '2026-08-25', 'gol_pppk' => 'V']);
        $this->hadirSepanjangPeriode($pppk);
        $this->db->table('dh_online')->where('nip', $pppk)->whereIn('tgl', ['2026-08-17', '2026-08-18', '2026-08-24', '2026-08-26'])->delete();
        $lap   = $this->laporanD13($pppk);
        $hrPpk = self::harianD13($lap);
        $this->assertSame(['TK' => 1], array_filter($lap['jumlah']), 'PPPK: hanya 26 Agu');
        $this->assertSame('TK', $hrPpk['2026-08-26']['status']);
        $this->assertSame(['0.60', '32.00', '32.60'], [$lap['total_presensi'], $lap['total_skp'], $lap['total_potongan']]);
    }

    /**
     * CR-083, keputusan Horii 29-10 malam [K `L_presensi.php:12707-12710`, `:12739-12743`]: PPPK tanpa
     * `riwayat_mutasi_jabatan` dan tanpa `riwayat_kp` (TMT kosong) → `new DateTime('')` = saat ekspor > hari kerja pertama
     * → seluruh hari loop dilewati: tanpa potongan presensi walau tidak pernah presensi. Data presensi tidak sah tidak dibaca
     * (keputusan Horii 30-10, C). Semua hari tampil `di_luar_masa_kerja` tanpa jam. Rekap (lingkup semua, jenis PPPK) =
     * laporan.
     */
    public function testPppkTanpaTmtTanpaPotonganPresensi(): void
    {
        $nip = $this->pppkTanpaTmt();
        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => '2026-08-17', 'jam_masuk' => '25:00:00', 'sumber' => 1]);
        $this->konketRekap($nip, '2026-08-18', 1);
        $this->cutiRekap($nip, '2026-08-19', '2026-08-19');

        $laporan = $this->laporanD13($nip);
        $this->assertSame([], array_filter($laporan['jumlah']), 'tanpa TK walau tanpa presensi');
        $this->assertSame(['0.00', '32.00', '32.00'], [$laporan['total_presensi'], $laporan['total_skp'], $laporan['total_potongan']], 'jam 25:00 tidak dibaca (bukan galat)');
        $this->assertSame(['di_luar_masa_kerja'], array_values(array_unique(array_column($laporan['harian'], 'status'))), 'semua hari, termasuk akhir pekan');
        $this->assertSame([null], array_values(array_unique([...array_column($laporan['harian'], 'jam_masuk'), ...array_column($laporan['harian'], 'kategori_konket')])));
        $this->assertStringNotContainsString('Cuti', $laporan['keterangan']);

        $rekap = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'jenis_pegawai' => ['6'], 'per_page' => '100']);
        $baris = array_column($rekap['items'], null, 'nip')[$nip] ?? null;
        $this->assertIsArray($baris, 'PPPK tanpa jabatan tetap di populasi rekap');
        $this->assertNull($baris['galat']);
        $this->assertSame(['0.00', '32.00', '32.00'], [$baris['total_presensi'], $baris['total_skp'], $baris['total_potongan']]);
    }

    /**
     * Pemutihan TB tetap berlaku untuk pegawai `lewatiLoopHarian` (`$endDateIsTB` dicatat sebelum `continue`,
     * :12726-12743): TB di hari kerja terakhir → `pemutihan_tb`, total 0; TB bukan di hari terakhir → hanya SKP (32%).
     */
    public function testPppkTanpaTmtPemutihanTb(): void
    {
        $akhir = $this->pppkTanpaTmt();
        $this->tbRekap($akhir, '2026-09-14', '2026-09-15');
        $lap = $this->laporanD13($akhir);
        $this->assertSame(['pemutihan_tb', '0.00', '0.00'], [$lap['alasan'], $lap['total_skp'], $lap['total_potongan']]);

        $tengah = $this->pppkTanpaTmt();
        $this->tbRekap($tengah, '2026-08-20', '2026-08-20');
        $lap = $this->laporanD13($tengah);
        $this->assertSame([null, '32.00', '32.00'], [$lap['alasan'], $lap['total_skp'], $lap['total_potongan']]);
    }

    private function pppkTanpaTmt(): string
    {
        $nip = $this->buatPegawaiDiSatker($this->satkerD13, ['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 6]);
        $this->assertSame(0, $this->db->table('riwayat_mutasi_jabatan')->where('nip', $nip)->countAllResults());
        $this->assertSame(0, $this->db->table('riwayat_kp')->where('nip', $nip)->countAllResults());

        return $nip;
    }

    /**
     * Pegawai populasi rekap dengan `created_at` (UTC) tertentu, hadir semua hari kerja, plus cuti 18 Agu, konket 19 Agu,
     * TB 20 Agu.
     */
    private function pegawaiDibuat(string $createdAtUtc): string
    {
        $nip = $this->pegawaiRekap($this->satkerD13, ['created_at' => $createdAtUtc]);
        $this->hadirSepanjangPeriode($nip);
        $this->cutiRekap($nip, '2026-08-18', '2026-08-18');
        $this->konketRekap($nip, '2026-08-19', 1);
        $this->tbRekap($nip, '2026-08-20', '2026-08-20');

        return $nip;
    }
}
