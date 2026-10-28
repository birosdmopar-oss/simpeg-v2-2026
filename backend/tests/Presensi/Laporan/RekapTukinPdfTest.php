<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Laporan\RekapTukinService;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.5 AC-D08.6 (MAKE-041, F5B-16): PDF rekap hanya lingkup unit/satker, maksimal `pdfRekapMaksBaris` (di atas
 * batas / lingkup `0` → 422 `errors.unit_satker` "Persempit lingkup atau unduh XLSX."), lanskap, blok penanda tangan dicetak.
 *
 * @internal
 */
final class RekapTukinPdfTest extends DatabaseTestCase
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

    public function testLingkupSatkerDanBatasBaris(): void
    {
        $satker = $this->buatSatker($this->buatUnit());
        $this->pegawaiRekap($satker);
        $this->pegawaiRekap($satker);
        $q = ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $satker];

        $berkas = $this->serviceRekapTukin()->pdf($this->adminRekap(), $q);
        $this->assertSame(['laporan_tukin_sat_' . $satker . '_2026-09.pdf', 'application/pdf'], [$berkas['nama'], $berkas['mime']]);
        $this->assertSame('L', $this->pdfTertangkap[0]->orientasi);
        $this->assertStringContainsString('TOTAL POTONGAN (%) (PRESENSI + SKP)', $this->pdfTertangkap[0]->html);
        $this->assertStringContainsString('Pejabat tidak ditemukan', $this->pdfTertangkap[0]->html, 'blok penanda tangan selalu dicetak');

        $config                    = new Presensi();
        $config->pdfRekapMaksBaris = 1;

        foreach ([[new RekapTukinService($this->db, self::jamRekap('2026-10-27 09:00:00'), null, null, $config), $q], [$this->serviceRekapTukin(), ['bulan' => '2026-09', 'unit_satker' => '0']]] as [$service, $query]) {
            try {
                $service->pdf($this->adminRekap(), $query);
                $this->fail('harus 422');
            } catch (ApiException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame(['Persempit lingkup atau unduh XLSX.'], $e->getErrors()['unit_satker'] ?? null);
            }
        }

        $this->assertCount(1, $this->pdfTertangkap, 'ditolak sebelum render');
    }
}
