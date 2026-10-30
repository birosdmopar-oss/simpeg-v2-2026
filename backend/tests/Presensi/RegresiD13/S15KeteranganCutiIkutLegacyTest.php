<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S15 — keterangan cuti ikut legacy (CR-085, keputusan Horii 30-10): `$arrKetCuti` dibangun di dalam loop harian
 * hanya untuk hari cuti yang benar-benar dilalui — setelah libur/akhir pekan, TB, pegawai baru (DH), dan pemotongan ke hari
 * ini (DH `L_presensi.php:12776-12792`, IL `:14645-14650`). Sebelumnya v2 mencetak semua `riwayat_cuti` periode.
 *
 * @internal
 */
#[Group('d13')]
final class S15KeteranganCutiIkutLegacyTest extends DatabaseTestCase
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

    /**
     * DH, CPNS ber-TMT 25-08 (laporan per pegawai; dibuat setelah akhir periode → pangkat ≤ akhir periode): cuti 18–19 Agu
     * (sebelum TMT, hari dilewati) tidak dicetak; cuti 27 Agu dicetak.
     */
    public function testCutiSebelumTmtCpnsTidakDicetak(): void
    {
        $nip = $this->pegawaiRekap($this->satkerD13, ['created_at' => '2026-09-20 03:00:00']);
        $this->hadirSepanjangPeriode($nip);
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => '2026-08-25', 'gol_ruang' => 'CPNS III/a', 'status' => 1]);
        $this->cuti($nip, 'tahunan', '2026-08-18', '2026-08-19', 2);
        $this->cuti($nip, 'tahunan', '2026-08-27', '2026-08-27');

        $ket = $this->laporanD13($nip)['keterangan'];
        $this->assertStringContainsString('27 Agustus 2026', $ket);
        $this->assertStringNotContainsString('18 Agustus 2026', $ket, 'sebelum TMT');
    }

    /** DH, periode berjalan (hari ini 01-09): cuti 03-09 belum dilalui loop → tidak dicetak; cuti 20-08 dicetak. */
    public function testCutiSetelahHariIniTidakDicetak(): void
    {
        $nip = $this->pegawaiHadirD13();
        $this->cuti($nip, 'tahunan', '2026-08-20', '2026-08-20');
        $this->cuti($nip, 'tahunan', '2026-09-03', '2026-09-03');

        $ket = $this->laporanD13($nip, '2026-09', '2026-09-01 09:00:00')['keterangan'];
        $this->assertStringContainsString('20 Agustus 2026', $ket);
        $this->assertStringNotContainsString('3 September 2026', $ket);
        $this->assertStringContainsString('3 September 2026', $this->laporanD13($nip)['keterangan'], 'pembanding: periode lewat');
    }

    /** DH, cuti hanya di hari libur / akhir pekan (dilewati sebelum cek cuti, :12793-12800) → tidak dicetak. */
    public function testCutiDiHariLiburDanAkhirPekanTidakDicetak(): void
    {
        $nip = $this->pegawaiHadirD13();
        $this->libur('2026-08-19');
        $this->cuti($nip, 'tahunan', '2026-08-19', '2026-08-19');
        $this->cuti($nip, 'tahunan', '2026-08-22', '2026-08-23', 2);
        $this->cuti($nip, 'tahunan', '2026-08-20', '2026-08-20');

        $ket = $this->laporanD13($nip)['keterangan'];
        $this->assertStringContainsString('20 Agustus 2026', $ket);
        $this->assertStringNotContainsString('19 Agustus 2026', $ket, 'libur');
        $this->assertStringNotContainsString('22 Agustus 2026', $ket, 'akhir pekan');
    }

    /** IL: aturan yang sama di rekap IL (hari ini 01-09: cuti 03-09 tidak dicetak; libur 19-08 tidak dicetak). */
    public function testRekapIl(): void
    {
        $nip = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($nip, ['jenis_jabatan' => 2]);
        $this->hadirSepanjangPeriode($nip);
        $this->libur('2026-08-19');

        foreach (['2026-08-19', '2026-08-20', '2026-09-03'] as $t) {
            $this->cuti($nip, 'tahunan', $t, $t);
        }

        $rekap = $this->serviceRekapTukin('2026-09-01 09:00:00')->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '100'], true);
        $ket   = (string) (array_column($rekap['items'], null, 'nip')[$nip]['keterangan'] ?? '');
        $this->assertStringContainsString('20 Agustus 2026', $ket);
        $this->assertStringNotContainsString('19 Agustus 2026', $ket);
        $this->assertStringNotContainsString('3 September 2026', $ket);
    }
}
