<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.3 AC-D08.1–.4, .7, .8 (MAKE-041): rekap Tukin atas data DB — baris (pengurangan, total persen dua desimal),
 * angka per pegawai = baris rekap (K-1), lingkup role 3 dipaksa, filter jenis pegawai, validasi bulan (2024-03 → 422
 * `periode_tidak_didukung`; bulan depan hanya setelah tanggal 15), periode berjalan dipotong hari ini, galat per pegawai
 * tidak menggagalkan rekap, tarif belum lengkap → semua baris `konfigurasi_tukin_tidak_lengkap`, ubah tarif langsung
 * berlaku.
 *
 * @internal
 */
final class RekapTukinTest extends DatabaseTestCase
{
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    private int $satkerA;

    private int $satkerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
        $unit          = $this->buatUnit();
        $this->satkerA = $this->buatSatker($unit);
        $this->satkerB = $this->buatSatker($unit);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        parent::tearDown();
    }

    public function testBarisRekapSamaDenganLaporanPerPegawai(): void
    {
        $rajin = $this->pegawaiRekap($this->satkerA);
        $absen = $this->pegawaiRekap($this->satkerA);
        $this->hadirSepanjangPeriode($rajin);

        $rekap = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA]);
        $this->assertSame(['awal' => '2026-08-16', 'akhir' => '2026-09-15'], $rekap['periode']);
        $this->assertSame([2, 0], [$rekap['total'], $rekap['jumlah_galat']]);

        $baris = array_column($rekap['items'], null, 'nip');
        $this->assertSame(['TL' => '0.00', 'PSW' => '0.00', 'TK' => '0.00', 'TA' => '0.00', 'CUTI' => '0.00', 'TB' => '0.00'], $baris[$rajin]['pengurangan']);
        $this->assertSame('0.00', $baris[$rajin]['total_presensi']);
        $this->assertSame('13.20', $baris[$absen]['pengurangan']['TK'], '22 hari TK × 3% × 0,2');
        $this->assertSame('13.20', $baris[$absen]['total_presensi']);

        foreach ([$rajin, $absen] as $n) {
            $laporan = $this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, ['bulan' => '2026-09']);
            $this->assertSame(
                [$baris[$n]['total_presensi'], $baris[$n]['total_skp'], $baris[$n]['total_potongan']],
                [$laporan['total_presensi'], $laporan['total_skp'], $laporan['total_potongan']],
                "AC-D08.2 laporan per pegawai = baris rekap {$n}",
            );
            $this->assertSame($baris[$n]['pengurangan']['TK'], $laporan['rincian']['TK'] ?? '0.00');
        }
    }

    public function testLingkupRole3DanJenisPegawai(): void
    {
        $a    = $this->pegawaiRekap($this->satkerA);
        $b    = $this->pegawaiRekap($this->satkerB);
        $pppk = $this->pegawaiRekap($this->satkerA, ['id_jenis_pegawai' => 6]);

        $r3 = $this->serviceRekapTukin()->daftar($this->adminRekap(Role::ADMIN_SATKER, (string) $this->satkerB), ['bulan' => '2026-09', 'unit_satker' => '0']);
        $this->assertSame([$b], array_column($r3['items'], 'nip'), 'role 3 dipaksa satkernya (tidak bocor)');

        $this->assertSame([$pppk], array_column($this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA, 'jenis_pegawai' => ['6']])['items'], 'nip'));
        $this->assertContains($a, array_column($this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09'])['items'], 'nip'));

        try {
            $this->serviceRekapTukin()->daftar($this->adminRekap(Role::ADMIN_SATKER), ['bulan' => '2026-09']);
            $this->fail('role 3 tanpa satker & unit harus 403');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testValidasiBulan(): void
    {
        try {
            $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2024-03']);
            $this->fail('2024-03 harus 422');
        } catch (ApiException $e) {
            $this->assertSame([422, 'periode_tidak_didukung'], [$e->getStatusCode(), $e->getKode()]);
        }

        $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2024-04']);
        $this->serviceRekapTukin('2026-10-16 09:00:00')->daftar($this->adminRekap(), ['bulan' => '2026-11']);

        foreach ([['2026-10-15 09:00:00', '2026-11'], ['2026-10-27 09:00:00', '2026-12'], ['2026-10-27 09:00:00', '2026-9']] as [$wib, $bulan]) {
            try {
                $this->serviceRekapTukin($wib)->daftar($this->adminRekap(), ['bulan' => $bulan]);
                $this->fail("{$bulan} pada {$wib} harus 422");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('bulan', $e->getErrors());
            }
        }
    }

    public function testPeriodeBerjalanDipotongHariIni(): void
    {
        $n = $this->pegawaiRekap($this->satkerA);

        $penuh    = $this->serviceRekapTukin('2026-10-27 09:00:00')->daftar($this->adminRekap(), ['bulan' => '2026-09'])['items'][0]['pengurangan']['TK'];
        $berjalan = $this->serviceRekapTukin('2026-08-21 09:00:00')->daftar($this->adminRekap(), ['bulan' => '2026-09'])['items'];

        $this->assertSame('13.20', $penuh);
        $this->assertSame('3.00', array_column($berjalan, null, 'nip')[$n]['pengurangan']['TK'], '17–21 Agu = 5 hari TK');
    }

    /**
     * AC-D08.4 bagian kedua (M7 MAKE-043): periode BKN tidak standar (`bkn_periode_ekinper`, adapter 5A AC-TK.1) dipakai
     * rekap dan laporan per pegawai, bukan bawaan 16–15.
     */
    public function testPeriodeBknTidakStandarDipakai(): void
    {
        $n = $this->pegawaiRekap($this->satkerA);
        $this->barisBkn(2026, 9, '08-14', '09-13');

        $rekap   = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09']);
        $laporan = $this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, ['bulan' => '2026-09']);

        $this->assertSame(['awal' => '2026-08-14', 'akhir' => '2026-09-13'], $rekap['periode']);
        $this->assertSame(['awal' => '2026-08-14', 'akhir' => '2026-09-13', 'dihitung_sampai' => '2026-09-13', 'sumber' => 'bkn'], $laporan['periode']);
        $baris = array_column($rekap['items'], null, 'nip')[$n];
        $this->assertSame('12.60', $baris['pengurangan']['TK'], '21 hari kerja 14 Agu – 13 Sep × 3% × 0,2 (bawaan 16–15 = 22 hari)');
        $this->assertSame([$baris['pengurangan']['TK'], $baris['total_presensi']], [$laporan['rincian']['TK'] ?? null, $laporan['total_presensi']]);
    }

    public function testTarifBelumLengkapSemuaBarisGalatDanUbahTarifBerlaku(): void
    {
        $n = $this->pegawaiRekap($this->satkerA);
        $this->hadir($n, '2026-09-01', '07:45:00', '16:00:00');

        $tl = fn (): string => array_column($this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09'])['items'], null, 'nip')[$n]['pengurangan']['TL'];
        $this->assertSame('0.10', $tl());

        $this->aturWebConfig(['TL1/PSW1' => '1']);
        $this->assertSame('0.20', $tl(), 'AC-D08.8: ubah tarif lewat WebConfigService langsung berlaku');

        $this->db->table('web_config')->where('config_name', 'TA')->delete();
        $this->resetWebConfig();
        $rekap = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-09']);

        $this->assertSame($rekap['total'], $rekap['jumlah_galat']);
        $this->assertSame('konfigurasi_tukin_tidak_lengkap', $rekap['items'][0]['galat']['code']);
        $this->assertNull($rekap['items'][0]['total_potongan'], 'bukan 0');

        try {
            $this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, ['bulan' => '2026-09']);
            $this->fail('laporan per pegawai harus 422 ber-code');
        } catch (ApiException $e) {
            $this->assertSame([422, 'konfigurasi_tukin_tidak_lengkap'], [$e->getStatusCode(), $e->getKode()]);
        }
    }
}
