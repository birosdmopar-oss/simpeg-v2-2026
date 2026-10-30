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
    private function barisIl(string $nip, string $wib = '2026-10-27 09:00:00'): array
    {
        $rekap = $this->serviceRekapTukin($wib)->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '100'], true);
        $baris = array_column($rekap['items'], null, 'nip')[$nip] ?? null;
        $this->assertIsArray($baris, "NIP {$nip} ada di rekap IL");
        $this->assertNull($baris['galat']);

        return $baris;
    }
}
