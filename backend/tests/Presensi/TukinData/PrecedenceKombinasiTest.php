<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\CutiTbUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.1.5 (MAKE-039) — kalkulasi rekap (K-1) atas data Fase 4 nyata lewat `SumberCutiTbFase4`:
 * - AC-D06.6 kombinasi bertumpuk (satu kasus per skenario): satu status per hari menurut precedence masa kerja →
 *   libur/akhir pekan → TB → cuti → konket → presensi, tanpa potongan ganda;
 * - AC-D06.1 TB penuh → potongan 0 `pemutihan_tb`; TB berakhir di tengah periode → hari sesudahnya dinilai presensi;
 *   celah TB–perpanjangan tidak bebas;
 * - AC-D06.2 cuti tahunan / alasan penting / melahirkan / CLTN → hari cuti tanpa potongan harian.
 *
 * Periode uji = bulan Tukin April 2026 tanpa baris BKN (16 Maret–15 April 2026; 16 Maret = Senin).
 *
 * @internal
 */
final class PrecedenceKombinasiTest extends DatabaseTestCase
{
    use CutiTbUjiTrait;
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->aturWebConfig(self::TARIF_UJI);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string}> [skenario, tanggal diperiksa, status harapan (null = bukan hari kerja)]
     */
    public static function kombinasi(): iterable
    {
        yield 'libur + cuti' => ['libur_cuti', '2026-03-18', null];
        yield 'akhir pekan + TB' => ['akhir_pekan_tb', '2026-03-21', null];
        yield 'TB + cuti' => ['tb_cuti', '2026-03-17', 'tugas_belajar'];
        yield 'cuti + konket' => ['cuti_konket', '2026-03-19', 'cuti'];
        yield 'konket + presensi' => ['konket_presensi', '2026-03-20', 'konket'];
        yield 'cuti + presensi tercatat' => ['cuti_presensi', '2026-03-23', 'cuti'];
        yield 'libur + cuti + konket (hari libur)' => ['libur_cuti_konket', '2026-03-25', null];
        yield 'libur + cuti + konket (hari kerja)' => ['libur_cuti_konket', '2026-03-26', 'cuti'];
    }

    #[DataProvider('kombinasi')]
    public function testKombinasiSatuStatusTanpaPotonganGanda(string $skenario, string $tanggal, ?string $status): void
    {
        $nip = $this->buatPegawai();

        switch ($skenario) {
            case 'libur_cuti':
                $this->libur('2026-03-18');
                $this->cuti($nip, 'tahunan', '2026-03-18', '2026-03-18');
                break;

            case 'akhir_pekan_tb':
                $this->tb($nip, '2026-03-21', '2026-03-22');
                break;

            case 'tb_cuti':
                $this->tb($nip, '2026-03-17', '2026-03-17');
                $this->cuti($nip, 'tahunan', '2026-03-17', '2026-03-17');
                break;

            case 'cuti_konket':
                $this->cuti($nip, 'alasan_penting', '2026-03-19', '2026-03-19');
                $this->konketBebas($nip, '2026-03-19', '2026-03-19');
                break;

            case 'konket_presensi':
                $this->konketBebas($nip, '2026-03-20', '2026-03-20');
                $this->presensiHadir($nip, '2026-03-20');
                break;

            case 'cuti_presensi':
                $this->cuti($nip, 'tahunan', '2026-03-23', '2026-03-23');
                $this->presensiHadir($nip, '2026-03-23');
                break;

            default: // libur_cuti_konket
                $this->libur('2026-03-25');
                $this->cuti($nip, 'tahunan', '2026-03-24', '2026-03-26', 2);
                $this->konketBebas($nip, '2026-03-24', '2026-03-26');
        }

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-03-27');

        if ($status === null) {
            $this->assertArrayNotHasKey($tanggal, $hasil->harian, 'bukan hari kerja: tidak dievaluasi');
        } else {
            $this->assertSame($status, $hasil->harian[$tanggal]->status);
            $this->assertSame(0, $hasil->harian[$tanggal]->potongan, 'tanpa potongan ganda');
        }
    }

    public function testTbPenuhPemutihan(): void
    {
        $nip = $this->buatPegawai();
        $this->tb($nip, '2026-03-01', '2026-04-30');

        $hasil = $this->hitungTukin($nip, 2026, 4);

        $this->assertSame('pemutihan_tb', $hasil->alasan);
        $this->assertSame(0, $hasil->totalPotongan);
        $this->assertSame('tugas_belajar', $hasil->harian['2026-04-15']->status);
    }

    public function testTbBerakhirDiTengahLaluPresensiNormalDanCelahPerpanjangan(): void
    {
        $nip = $this->buatPegawai();
        $id  = $this->tb($nip, '2026-03-01', '2026-03-20');
        $this->perpanjanganTb($id, '2026-03-25', '2026-03-26');
        $this->presensiHadir($nip, '2026-03-27');

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-03-27');

        $this->assertSame('tugas_belajar', $hasil->harian['2026-03-20']->status);
        $this->assertSame('TK', $hasil->harian['2026-03-23']->status, 'celah TB–perpanjangan tidak bebas');
        $this->assertSame('tugas_belajar', $hasil->harian['2026-03-25']->status, 'perpanjangan bebas');
        $this->assertSame('hadir', $hasil->harian['2026-03-27']->status, 'setelah TB dinilai presensi');
        $this->assertNotSame('pemutihan_tb', $hasil->alasan, 'hari kerja terakhir bukan TB');
        $this->assertGreaterThan(0, $hasil->totalPotongan);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function cutiTanpaPotonganHarian(): iterable
    {
        yield 'tahunan' => ['tahunan'];
        yield 'alasan penting' => ['alasan_penting'];
        yield 'melahirkan' => ['melahirkan'];
        yield 'cltn' => ['cltn'];
    }

    #[DataProvider('cutiTanpaPotonganHarian')]
    public function testCutiTanpaPotonganHarian(string $jenis): void
    {
        $nip = $this->buatPegawai();
        $this->cuti($nip, $jenis, '2026-03-16', '2026-03-20', 5);

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-03-20');

        foreach (['2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20'] as $tgl) {
            $this->assertSame('cuti', $hasil->harian[$tgl]->status, $tgl);
            $this->assertSame(0, $hasil->harian[$tgl]->potongan, $tgl);
        }

        $this->assertSame(0, $hasil->potonganPresensi);
    }
}
