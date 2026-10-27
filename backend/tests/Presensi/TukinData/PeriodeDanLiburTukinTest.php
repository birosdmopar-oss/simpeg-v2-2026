<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\TukinData\DataTukinTidakSah;
use App\Libraries\Presensi\TukinData\SumberHariLibur;
use App\Libraries\Presensi\TukinData\SumberPeriodeTukin;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5A §7.7.4 — AC-TK.1: periode dari `bkn_periode_ekinper` (legacy `L_presensi.php:11852-11876`) atau bawaan 16–15
 * (Januari mulai 16 Desember); hari libur aktif dalam rentang; akhir pekan bukan hari kerja.
 *
 * @internal
 */
final class PeriodeDanLiburTukinTest extends DatabaseTestCase
{
    use TukinDataUjiTrait;

    public function testBarisBknMenjadiPeriode(): void
    {
        $id = $this->barisBkn(2026, 4, '03-14', '04-13');
        $this->barisBkn(2026, 4, '03-01', '04-30', 'Tahunan', 'BKN-LAIN'); // jenis_periode terisi diabaikan [K]

        $bkn = $this->sumber()->barisBkn(2026, 4);
        $this->assertSame(['id' => $id, 'awal' => '2026-03-14', 'akhir' => '2026-04-13'], $bkn);

        $periode = SumberPeriodeTukin::periode(2026, 4, $bkn);
        $this->assertSame(['2026-03-14', '2026-04-13'], [$periode->awal, $periode->akhir]);
    }

    public function testTanpaBarisBknPakaiBawaan(): void
    {
        $this->assertNull($this->sumber()->barisBkn(2026, 5));

        $periode = SumberPeriodeTukin::periode(2026, 5, null);
        $this->assertSame(['2026-04-16', '2026-05-15'], [$periode->awal, $periode->akhir]);

        $januari = SumberPeriodeTukin::periode(2026, 1, null);
        $this->assertSame(['2025-12-16', '2026-01-15'], [$januari->awal, $januari->akhir]);
    }

    public function testJanuariBknTetapMulai16Desember(): void
    {
        $sumber = $this->sumber();
        $this->assertNull($sumber->barisBkn(2025, 1));

        $this->barisBkn(2026, 1, '12-20', '01-19');
        $bkn = $sumber->barisBkn(2026, 1);
        $this->assertNotNull($bkn);
        $this->assertSame('2025-12-16', $bkn['awal'], 'legacy :11859-11862');

        $periode = SumberPeriodeTukin::periode(2026, 1, $bkn);
        $this->assertSame(['2025-12-16', '2026-01-19'], [$periode->awal, $periode->akhir]);
    }

    public function testBarisBknGandaAtauTidakSahDitolak(): void
    {
        $this->barisBkn(2026, 6, '05-16', '06-15', null, 'A');
        $this->barisBkn(2026, 6, '05-17', '06-16', null, 'B');

        try {
            $this->sumber()->barisBkn(2026, 6);
            $this->fail('baris ganda harus ditolak');
        } catch (DataTukinTidakSah $e) {
            $this->assertStringContainsString('lebih dari satu baris', $e->getMessage());
        }

        $this->barisBkn(2026, 7, '2026-06-16', '07-15');
        $this->expectException(DataTukinTidakSah::class);
        $this->sumber()->barisBkn(2026, 7);
    }

    public function testBknYangTidakBerakhirDiBulanItuDitolakTerkendali(): void
    {
        $this->barisBkn(2026, 8, '07-16', '09-15');

        $this->expectException(DataTukinTidakSah::class);
        SumberPeriodeTukin::periode(2026, 8, $this->sumber()->barisBkn(2026, 8));
    }

    public function testHariLiburAktifDalamRentangDanAkhirPekan(): void
    {
        $libur = [
            ['tgl_mulai' => '2026-03-19', 'tgl_akhir' => '2026-03-20', 'nama_libur' => 'Libur uji dua hari', 'status' => 1],
            ['tgl_mulai' => '2026-03-31', 'tgl_akhir' => '2026-03-31', 'nama_libur' => 'Libur uji nonaktif', 'status' => 2],
            ['tgl_mulai' => '2026-05-01', 'tgl_akhir' => '2026-05-01', 'nama_libur' => 'Libur uji di luar rentang', 'status' => 1],
        ];

        foreach ($libur as $baris) {
            $this->db->table('hari_libur')->insert($baris);
        }

        $tanggal = (new SumberHariLibur(service('hariLiburService')))->untuk('2026-03-16', '2026-04-15');
        $this->assertSame(['2026-03-19', '2026-03-20'], $tanggal);

        $hariKerja = HariKerja::daftar(SumberPeriodeTukin::periode(2026, 4, null), $tanggal);
        $this->assertNotContains('2026-03-21', $hariKerja, 'Sabtu');
        $this->assertNotContains('2026-03-22', $hariKerja, 'Minggu');
        $this->assertNotContains('2026-03-19', $hariKerja, 'libur aktif');
        $this->assertContains('2026-03-31', $hariKerja, 'libur nonaktif tetap hari kerja');
        $this->assertContains('2026-03-23', $hariKerja);
    }

    private function sumber(): SumberPeriodeTukin
    {
        return new SumberPeriodeTukin($this->db);
    }
}
