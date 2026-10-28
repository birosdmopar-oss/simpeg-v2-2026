<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\TukinData\DataTukinTidakSah;
use App\Libraries\Presensi\TukinData\SumberCutiTbFase4;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\CutiTbUjiTrait;

/**
 * PRD 5B §7.1.2–7.1.3 (MAKE-039): `SumberCutiTbFase4` dari data Fase 4 — hanya `riwayat_cuti.status = 1` (AC-D06.7);
 * `id_jenis_cuti` 1..6 → kode jenis (AC-D06.2); di luar 1..6 → `DataTukinTidakSah`, tidak jatuh ke "bebas" (AC-D06.8,
 * F5B-17); tumpang tindih → `id_riwayat_cuti` terbesar (F5B-17); ekspansi kalender dipotong [awal, akhir]; TB lewat
 * `rentangBebasPresensiBanyak` Fase 4 (celah perpanjangan tidak bebas); `untuk()` = `untukBanyak()[nip]`; galat satu NIP
 * tidak merusak NIP lain di varian massal.
 *
 * @internal
 */
final class SumberCutiTbFase4Test extends DatabaseTestCase
{
    use CutiTbUjiTrait;
    use PegawaiFixtureTrait;

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function jenisCuti(): iterable
    {
        yield 'tahunan' => [1, 'tahunan'];
        yield 'besar' => [2, 'besar'];
        yield 'sakit' => [3, 'sakit'];
        yield 'melahirkan' => [4, 'melahirkan'];
        yield 'alasan penting' => [5, 'alasan_penting'];
        yield 'cltn' => [6, 'cltn'];
    }

    #[DataProvider('jenisCuti')]
    public function testIdJenisCutiKeKodeJenis(int $id, string $kode): void
    {
        $nip = $this->buatPegawai();
        $this->cuti($nip, $id, '2026-03-17', '2026-03-18', 2);

        $data = $this->sumber()->untuk($nip, '2026-03-16', '2026-04-15');

        $this->assertSame(['jenis' => $kode, 'mulai' => '2026-03-17', 'akhir' => '2026-03-18', 'lama' => 2], $data['2026-03-17']['cuti']);
        $this->assertArrayHasKey('2026-03-18', $data);
    }

    public function testHanyaStatusSatuDanEkspansiDipotongRentang(): void
    {
        $nip = $this->buatPegawai();

        foreach ([0, 2, 3, 4] as $status) {
            $this->cuti($nip, 'tahunan', '2026-03-16', '2026-03-20', 5, $status);
        }

        $this->assertCount(0, $this->sumber()->untuk($nip, '2026-03-16', '2026-04-15'), 'status 0/2/3/4 diabaikan');

        // Cuti melintasi batas rentang: hanya tanggal di dalam [awal, akhir], termasuk akhir pekan (library yang melewati).
        $this->cuti($nip, 'besar', '2026-03-10', '2026-03-22', 13);
        $data = $this->sumber()->untuk($nip, '2026-03-16', '2026-04-15');

        $this->assertSame(['2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20', '2026-03-21', '2026-03-22'], array_keys($data));
        $this->assertSame(['jenis' => 'besar', 'mulai' => '2026-03-10', 'akhir' => '2026-03-22', 'lama' => 13], $data['2026-03-21']['cuti'], 'mulai/akhir/lama asli');
    }

    public function testJenisDiLuarSatuSampaiEnamGagalTerkendali(): void
    {
        $nip = $this->buatPegawai();
        $this->cuti($nip, 7, '2026-03-17', '2026-03-17');

        $this->expectException(DataTukinTidakSah::class);
        $this->expectExceptionMessageMatches('/id_jenis_cuti 7/');

        $this->sumber()->untuk($nip, '2026-03-16', '2026-04-15');
    }

    public function testTumpangTindihIdTerbesarMenang(): void
    {
        $nip = $this->buatPegawai();
        // Baris belakangan (id lebih besar) punya tgl_mulai lebih awal: v2 = id terbesar menang (legacy = tgl_mulai terbesar).
        $this->cuti($nip, 'tahunan', '2026-03-18', '2026-03-19', 2);
        $this->cuti($nip, 'alasan_penting', '2026-03-17', '2026-03-18', 2);

        $data = $this->sumber()->untuk($nip, '2026-03-16', '2026-04-15');

        $this->assertSame('tahunan', $data['2026-03-19']['cuti']['jenis']);
        $this->assertSame('alasan_penting', $data['2026-03-18']['cuti']['jenis'], 'id terbesar menang pada tanggal yang sama');
        $this->assertSame('alasan_penting', $data['2026-03-17']['cuti']['jenis']);
    }

    public function testTugasBelajarLewatAturanFase4DanCelahPerpanjangan(): void
    {
        $nip = $this->buatPegawai();
        $id  = $this->tb($nip, '2026-03-01', '2026-03-18');
        $this->perpanjanganTb($id, '2026-03-23', '2026-03-24');
        $this->tb($nip, '2026-03-26', '2026-03-26', 0);

        $data = $this->sumber()->untuk($nip, '2026-03-16', '2026-04-15');

        $this->assertSame(['2026-03-16', '2026-03-17', '2026-03-18', '2026-03-23', '2026-03-24'], array_keys($data), 'celah 19–22 tidak bebas; TB status 0 diabaikan');
        $this->assertSame(['tugas_belajar' => true], $data['2026-03-23']);
    }

    public function testUntukSamaDenganUntukBanyakDanGalatPerNip(): void
    {
        $a = $this->buatPegawai();
        $b = $this->buatPegawai();
        $c = $this->buatPegawai();
        $this->cuti($a, 'sakit', '2026-03-16', '2026-03-17', 2);
        $this->tb($a, '2026-03-19', '2026-03-19');
        $this->cuti($b, 7, '2026-03-17', '2026-03-17');
        $this->cuti($c, 'cltn', '2026-04-01', '2026-04-30', 30);

        $massal = $this->sumber()->untukBanyak([$a, $b, $c, $a], '2026-03-16', '2026-04-15');

        $this->assertSame([$a, $b, $c], array_map('strval', array_keys($massal)), 'satu kunci per NIP unik');
        $this->assertSame($this->sumber()->untuk($a, '2026-03-16', '2026-04-15'), $massal[$a]);
        $this->assertSame($this->sumber()->untuk($c, '2026-03-16', '2026-04-15'), $massal[$c]);
        $this->assertInstanceOf(DataTukinTidakSah::class, $massal[$b], 'galat hanya NIP itu');
        $this->assertSame([], $this->sumber()->untukBanyak([], '2026-03-16', '2026-04-15'));
    }

    private function sumber(): SumberCutiTbFase4
    {
        return new SumberCutiTbFase4($this->db);
    }
}
