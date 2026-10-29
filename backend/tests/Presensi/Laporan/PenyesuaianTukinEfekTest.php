<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.10 AC-D08.11 (efek, MAKE-041): baris penyesuaian aktif yang mencakup bulan laporan → `penyesuaian` +
 * keterangan di laporan per pegawai, PDF, rekap, XLSX; `total_potongan` hasil `KalkulasiTukin` **tidak** berubah; status
 * 2/10 dan bulan lain diabaikan.
 *
 * @internal
 */
final class PenyesuaianTukinEfekTest extends DatabaseTestCase
{
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        parent::tearDown();
    }

    public function testEfekTanpaMengubahTotalPotongan(): void
    {
        $n = $this->pegawaiRekap($this->buatSatker($this->buatUnit()));
        $q = ['bulan' => '2026-09'];

        $sebelum = $this->serviceRekapTukin()->daftar($this->adminRekap(), $q)['items'][0];
        $this->assertNull($sebelum['penyesuaian']);

        foreach ([['2026-09-01', '2026-10-01', '-20.00', 'Predikat Kinerja Sangat Kurang', 1], ['2026-09-01', '2026-09-01', '-10.00', 'Nonaktif', 2], ['2026-08-01', '2026-08-01', '-5.00', 'Bulan lain', 1]] as [$mulai, $akhir, $persen, $alasan, $status]) {
            $this->db->table('penyesuaian_tukin')->insert(['nip' => $n, 'bulan_mulai' => $mulai, 'bulan_akhir' => $akhir, 'persen' => $persen, 'alasan' => $alasan, 'status' => $status]);
        }

        $teks    = 'Penyesuaian Tukin Predikat Kinerja Sangat Kurang -20% (sebelum potongan lain).';
        $sesudah = $this->serviceRekapTukin()->daftar($this->adminRekap(), $q)['items'][0];
        $this->assertSame(['persen' => '-20', 'alasan' => 'Predikat Kinerja Sangat Kurang'], $sesudah['penyesuaian']);
        $this->assertSame($teks, $sesudah['keterangan']);
        $this->assertSame([$sebelum['total_presensi'], $sebelum['total_skp'], $sebelum['total_potongan']], [$sesudah['total_presensi'], $sesudah['total_skp'], $sesudah['total_potongan']], 'total tidak berubah');

        $laporan = $this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, $q);
        $this->assertSame([$sesudah['penyesuaian'], $sebelum['total_potongan']], [$laporan['penyesuaian'], $laporan['total_potongan']]);

        $this->serviceLaporanTukin()->pdf($this->adminRekap(), $n, $q);
        $this->assertStringContainsString(esc($teks), $this->pdfTertangkap[0]->html);
        $this->assertStringContainsString(esc($sebelum['total_potongan'] . '% + 20%'), $this->pdfTertangkap[0]->html, '"<total>%<penyesuaian>" [K :254]');

        $tmp = tempnam(sys_get_temp_dir(), 'pte');
        file_put_contents((string) $tmp, $this->serviceRekapTukin()->xlsx($this->adminRekap(), $q)['isi']);
        $sheet = IOFactory::load((string) $tmp)->getActiveSheet();
        @unlink((string) $tmp);
        $this->assertSame($teks, $sheet->getCell('O9')->getValue(), 'keterangan XLSX');
        $this->assertSame((float) $sebelum['total_potongan'], (float) $sheet->getCell('N9')->getValue());

        $agustus = $this->serviceRekapTukin()->daftar($this->adminRekap(), ['bulan' => '2026-08'])['items'][0];
        $this->assertSame(['persen' => '-5', 'alasan' => 'Bulan lain'], $agustus['penyesuaian'], 'baris per bulan masing-masing');
    }
}
