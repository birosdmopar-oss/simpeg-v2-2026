<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\Slip\TarifUangMakan;
use App\Libraries\Presensi\Slip\UangMakanSlipPresensi;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.2 AC-D09a.1/.4/.5 (MAKE-040) — tiga cabang snapshot uang makan 5A (`SlipGajiService`, tidak diubah) diuji
 * ulang dengan sumber asli {@see UangMakanSlipPresensi} di atas data DB: nilai > 0 → ditulis; 0 dengan nilai lama > 0 →
 * nilai lama dipertahankan; 0 dan lama 0 → ditulis 0. Slip Januari memakai kehadiran Desember tahun sebelumnya. Bawaan
 * `Config\Presensi::$sumberUangMakan` = sumber asli (blocker F5-1 ditutup).
 *
 * @internal
 */
final class UangMakanSnapshotAsliTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SlipUjiTrait;
    use TukinDataUjiTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
        $this->resetWebConfig();
        $this->aturWebConfig(['uang_makan_gol_3' => '37000']);
        $this->nip = $this->pegawaiSlip()['nip'];
        $this->db->table('riwayat_kp')->insert(['nip' => $this->nip, 'tmtsk' => '2020-01-01', 'gol' => 'III', 'gol_ruang' => 'III/a', 'status' => 1]);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        $this->bersihkanSlip();
        parent::tearDown();
    }

    public function testBawaanKonfigurasiSumberAsli(): void
    {
        $this->assertSame(UangMakanSlipPresensi::class, (new Presensi())->sumberUangMakan);
    }

    public function testNilaiPositifDitulis(): void
    {
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $t) {
            $this->hadir($t);
        }

        $id    = $this->slip($this->nip, 2026, 10);
        $hasil = $this->buka('2026-10-27 09:00:00', '2026-10');

        $this->assertSame(['nominal' => '111000.00', 'hari' => 3, 'periode_kehadiran' => '2026-09', 'tersedia' => true], $hasil['uang_makan']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'uang_makan' => '111000.00', 'uang_makan_hari' => 3, 'uang_makan_periode' => '9-2026']);
    }

    public function testNolMempertahankanNilaiLamaAtauMenulisNol(): void
    {
        $lama = $this->slip($this->nip, 2026, 10, ['uang_makan' => '150000.00', 'uang_makan_hari' => 6]);
        $this->buka('2026-10-27 09:00:00', '2026-10');
        $this->seeInDatabase('gaji_pegawai', ['id' => $lama, 'uang_makan' => '150000.00', 'uang_makan_hari' => 6, 'uang_makan_periode' => null]);

        $nol = $this->slip($this->nip, 2026, 11);
        $this->buka('2026-11-27 09:00:00', '2026-11');
        $this->seeInDatabase('gaji_pegawai', ['id' => $nol, 'uang_makan' => '0.00', 'uang_makan_hari' => 0, 'uang_makan_periode' => '10-2026']);
    }

    public function testSlipJanuariMemakaiDesemberTahunSebelumnya(): void
    {
        $this->hadir('2026-12-01');
        $this->hadir('2026-12-02');
        $this->hadir('2027-01-04'); // bulan slip, tidak dihitung

        $id    = $this->slip($this->nip, 2027, 1);
        $hasil = $this->buka('2027-01-10 09:00:00', '2027-01');

        $this->assertSame('2026-12', $hasil['uang_makan']['periode_kehadiran']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'uang_makan' => '74000.00', 'uang_makan_hari' => 2, 'uang_makan_periode' => '12-2026']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buka(string $wib, string $periode): array
    {
        $sumber = new UangMakanSlipPresensi($this->db, new TarifUangMakan($this->db, service('webConfigService')), service('hariLiburService'), self::jamWib($wib));

        return $this->slipPegawai($wib, $sumber)->buka($this->authPegawaiSlip($this->nip), $periode, '203.0.113.1');
    }

    private function hadir(string $tgl): void
    {
        $this->db->table('dh_online')->insert(['nip' => $this->nip, 'tgl' => $tgl, 'jam_masuk' => '07:30:00', 'jam_pulang' => '16:30:00', 'sumber' => 1]);
    }
}
