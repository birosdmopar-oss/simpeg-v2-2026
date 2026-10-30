<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S14 — rekap Tukin **IL** ikut legacy `laporan_tukin_us_skp_il` (`L_presensi.php:13494-15452`; CR-085, keputusan
 * Horii 30-10) lewat jalur nyata `RekapTukinService::daftar(..., il: true)` → `KalkulasiTukinIl`; pembanding jalur DH =
 * laporan per pegawai (`KalkulasiTukin`) atas pegawai & data yang sama. Pegawai IL: snapshot & `riwayat_mutasi_jabatan`
 * jenis jabatan 2, hadir tepat waktu kecuali disebut, tanpa SKP (32).
 *
 * @internal
 */
#[Group('d13')]
final class S14RekapIlIkutLegacyTest extends DatabaseTestCase
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

    /** (a) Cuti besar sepanjang periode: IL CUTI 25 × 0,2 = 5 (01-09) + SKP 32 = 37; DH pengganti cuti besar = 5. */
    public function testCutiBesarSepanjangPeriode(): void
    {
        $nip = $this->pegawaiIl();
        $this->cuti($nip, 'besar', '2026-08-01', '2026-10-31', 66);

        $b = $this->barisIl($nip);
        $this->assertSame(['5.00', '5.00', '32.00', '37.00'], [$b['pengurangan']['CUTI'], $b['total_presensi'], $b['total_skp'], $b['total_potongan']]);
        $this->assertSame('5.00', $this->laporanD13($nip)['total_potongan'], 'pembanding DH');
    }

    /** (b) Cuti tahunan sepanjang periode: IL tetap memotong SKP 32 (tanpa cuti sepanjang periode); DH 0. */
    public function testCutiTahunanSepanjangPeriode(): void
    {
        $nip = $this->pegawaiIl();
        $this->cuti($nip, 'tahunan', '2026-08-14', '2026-09-16', 24);

        $this->assertSame(['0.00', '32.00', '32.00'], array_values(array_intersect_key($this->barisIl($nip), array_flip(['total_presensi', 'total_skp', 'total_potongan']))));
        $this->assertSame('0.00', $this->laporanD13($nip)['total_potongan'], 'pembanding DH');
    }

    /** (c) TB di hari kerja terakhir + TK sebelumnya: IL total = 0,6 (SKP 0, presensi tidak dinolkan); DH pemutihan 0. */
    public function testTbAkhirPeriodeDenganPotonganSebelumnya(): void
    {
        $nip = $this->pegawaiIl(['2026-08-17']);
        $this->tbRekap($nip, '2026-09-14', '2026-09-15');

        $this->assertSame(['0.60', '0.00', '0.60'], array_values(array_intersect_key($this->barisIl($nip), array_flip(['total_presensi', 'total_skp', 'total_potongan']))));
        $lap = $this->laporanD13($nip);
        $this->assertSame(['pemutihan_tb', '0.00'], [$lap['alasan'], $lap['total_potongan']], 'pembanding DH');
    }

    /**
     * Cuti alasan penting > 14 hari dengan hari ini 31-08: hari kerja 17-08..16-09 = Agu 11 / Sep 11 (16-09 libur, di luar
     * rentang libur bawaan → adapter memperluas libur ke rentang cuti) → seri → Agustus → 17-08 (dalam loop) 50 × 0,2 = 10.
     */
    public function testAlasanPentingLiburDiRentangCuti(): void
    {
        $nip = $this->pegawaiIl();
        $this->cuti($nip, 'alasan_penting', '2026-08-17', '2026-09-16', 22);
        $this->libur('2026-09-16');

        $b = $this->barisIl($nip, '2026-08-31 09:00:00');
        $this->assertSame(['10.00', '42.00'], [$b['pengurangan']['CUTI'], $b['total_potongan']]);
    }

    /**
     * Cuti melahirkan IL: anak ≥ 3 dihitung s.d. `de_tukin` (akhir dipotong hari ini, :13840, :13851). Anak ke-3 lahir 10-09:
     * hari ini 05-09 → 2 anak, tanpa potongan; hari ini 27-10 → 3 anak, 01-09 70 × 0,2 = 14 (DH: anak > 3, tanpa potongan).
     */
    public function testCutiMelahirkanAnakDihitungSampaiHariIni(): void
    {
        $nip = $this->pegawaiIl();
        $this->cuti($nip, 'melahirkan', '2026-08-10', '2026-11-07', 90);
        $this->db->table('riwayat_keluarga')->insert(['nip' => $nip, 'tgl_perkawinan' => '2010-01-01', 'nama_pasangan' => 'Pasangan Uji', 'status' => 1]);
        $kel = (int) $this->db->insertID();

        foreach (['2012-01-01', '2015-01-01', '2026-09-10'] as $lahir) {
            $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => $kel, 'tgl_lahir' => $lahir, 'nama' => 'Anak Uji']);
        }

        $this->assertSame('0.00', $this->barisIl($nip, '2026-09-05 09:00:00')['pengurangan']['CUTI'], 'anak 2 per hari ini');
        $this->assertSame('14.00', $this->barisIl($nip)['pengurangan']['CUTI'], 'anak 3');
    }

    /**
     * B (CR-085 review): legacy menjadwalkan potongan dari seluruh `$listCuti[nip][id]` (:13903-13911, :14176-14423).
     * Cuti besar 01-09..30-11 (id kecil) ditimpa cuti tahunan 01-09..18-09 (id besar, F5B-17) di setiap tanggal periode →
     * tetap dipotong 01-09: CUTI 5, total 37.
     */
    public function testRiwayatCutiTertimpaTetapDijadwalkan(): void
    {
        $nip = $this->pegawaiIl();
        $this->cuti($nip, 'besar', '2026-09-01', '2026-11-30', 65);
        $this->cuti($nip, 'tahunan', '2026-09-01', '2026-09-18', 14);

        $b = $this->barisIl($nip);
        $this->assertSame(['5.00', '37.00'], [$b['pengurangan']['CUTI'], $b['total_potongan']]);
    }

    /**
     * F (keputusan Horii 30-10, [K] legacy IL): pegawai keluar `status = 2` — data harian dimuat hanya bila `tmt_status >
     * de_tukin` (`L_presensi.php:13906`, `:13957`, `:14045`, `:14084`), tanpa `tmtKeluar`. Periode Okt (16-09..15-10, 22
     * hari kerja), hadir 16–30 Sep, tanpa SKP.
     */
    public function testPegawaiKeluarIkutLegacyIl(): void
    {
        // TMT keluar 01-10, ekspor 30-10 (de_tukin 15-10): tanpa data → 22 TK = 13,20 + 32.
        $tmt1 = $this->pegawaiIlKeluar('2026-10-01');
        $this->assertSame(['13.20', '45.20'], $this->tkTotal($this->barisIl($tmt1, '2026-10-30 09:00:00', '2026-10')));

        // Ekspor 05-10 (de_tukin 05-10 ≥ TMT): tanpa data, loop s.d. 05-10 → 14 TK = 8,40 + 32.
        $this->assertSame(['8.40', '40.40'], $this->tkTotal($this->barisIl($tmt1, '2026-10-05 09:00:00', '2026-10')));

        // Batas `>` ketat: TMT = de_tukin (05-10) → tanpa data.
        $this->assertSame(['8.40', '40.40'], $this->tkTotal($this->barisIl($this->pegawaiIlKeluar('2026-10-05'), '2026-10-05 09:00:00', '2026-10')));

        // TMT keluar 10-10, ekspor 05-10 (TMT > de_tukin): data dimuat → TK hanya 01, 02, 05 Okt = 1,80 + 32.
        $tmt10 = $this->pegawaiIlKeluar('2026-10-10');
        $this->assertSame(['1.80', '33.80'], $this->tkTotal($this->barisIl($tmt10, '2026-10-05 09:00:00', '2026-10')));
        // … dan tanpa tmtKeluar: hari ≥ TMT tetap dihitung (ekspor 30-10, de_tukin 15-10 ≥ TMT → tanpa data, 22 TK).
        $this->assertSame(['13.20', '45.20'], $this->tkTotal($this->barisIl($tmt10, '2026-10-30 09:00:00', '2026-10')));
    }

    /** F: cuti besar (potong 01-10) & TB ikut tidak dimuat bila TMT keluar ≤ de_tukin; dimuat bila TMT > de_tukin. */
    public function testPegawaiKeluarCutiBesarDanTbIkutKosong(): void
    {
        $kosong = $this->pegawaiIlKeluar('2026-10-01');
        $this->cuti($kosong, 'besar', '2026-09-01', '2026-11-30', 65);
        $this->tbRekap($kosong, '2026-10-14', '2026-10-15');
        $b = $this->barisIl($kosong, '2026-10-30 09:00:00', '2026-10');
        $this->assertSame(['0.00', '13.20', '32.00', '45.20', ''], [$b['pengurangan']['CUTI'], $b['pengurangan']['TK'], $b['total_skp'], $b['total_potongan'], (string) $b['keterangan']]);

        $dimuat = $this->pegawaiIlKeluar('2026-10-20');
        $this->cuti($dimuat, 'besar', '2026-09-01', '2026-11-30', 65);
        $b = $this->barisIl($dimuat, '2026-10-30 09:00:00', '2026-10');
        $this->assertSame(['5.00', '0.00', '37.00'], [$b['pengurangan']['CUTI'], $b['pengurangan']['TK'], $b['total_potongan']]);
    }

    private function pegawaiIlKeluar(string $tmt): string
    {
        $nip = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2, 'status' => 2, 'tmt_status' => $tmt], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($nip, ['jenis_jabatan' => 2]);

        foreach ([16, 17, 18, 21, 22, 23, 24, 25, 28, 29, 30] as $h) {
            $this->hadir($nip, sprintf('2026-09-%02d', $h), '07:30:00', $h === 18 || $h === 25 ? '16:30:00' : '16:00:00');
        }

        return $nip;
    }

    /**
     * @param array<string, mixed> $baris
     *
     * @return list<string> [TK, total potongan]
     */
    private function tkTotal(array $baris): array
    {
        return [$baris['pengurangan']['TK'], $baris['total_potongan']];
    }

    /**
     * Pegawai IL: snapshot & riwayat jabatan jenis 2, hadir sepanjang periode 2026-09 kecuali `$tanpa`.
     *
     * @param list<string> $tanpa
     */
    private function pegawaiIl(array $tanpa = []): string
    {
        $nip = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($nip, ['jenis_jabatan' => 2]);
        $this->hadirSepanjangPeriode($nip);

        if ($tanpa !== []) {
            $this->db->table('dh_online')->where('nip', $nip)->whereIn('tgl', $tanpa)->delete();
        }

        return $nip;
    }

    /**
     * @return array<string, mixed>
     */
    private function barisIl(string $nip, string $wib = '2026-10-27 09:00:00', string $bulan = '2026-09'): array
    {
        $rekap = $this->serviceRekapTukin($wib)->daftar($this->adminRekap(), ['bulan' => $bulan, 'per_page' => '100'], true);
        $baris = array_column($rekap['items'], null, 'nip')[$nip] ?? null;
        $this->assertIsArray($baris, "NIP {$nip} ada di rekap IL");
        $this->assertNull($baris['galat']);

        return $baris;
    }
}
