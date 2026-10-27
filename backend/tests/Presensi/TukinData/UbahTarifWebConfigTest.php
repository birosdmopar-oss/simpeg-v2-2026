<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinDariWebConfig;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5A §7.7.3 — AC-TK.4 (bagian regression D-13 "ubah tarif web_config"): mengubah `TL1/PSW1` lewat
 * `WebConfigService` lalu menghitung ulang mengubah `HasilTukin::totalPotongan` sesuai tarif baru, tanpa membersihkan
 * cache manual.
 *
 * @internal
 */
final class UbahTarifWebConfigTest extends DatabaseTestCase
{
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testUbahTarifLangsungMengubahPotongan(): void
    {
        $periode = PeriodeTukin::dariBulan(2026, 4);
        // Satu hari terlambat 10 menit (TL1) pada Senin 2026-03-16; hari kerja lain hadir tepat waktu.
        $harian = [];

        foreach (['2026-03-16', '2026-03-17'] as $tgl) {
            $harian[$tgl] = ['presensi' => ['masuk' => '07:30:00', 'pulang' => '16:00:00']];
        }
        $harian['2026-03-16']['presensi']['masuk'] = '07:40:00';

        $this->aturWebConfig(self::TARIF_UJI);
        $adapter = new KonfigurasiTukinDariWebConfig(service('webConfigService'));
        $sebelum = KalkulasiTukin::hitung($periode, [], $harian, $adapter->untuk($periode), '2026-03-17');

        service('webConfigService')->set('TL1/PSW1', ['config_value' => '1.5']);
        $sesudah = KalkulasiTukin::hitung($periode, [], $harian, $adapter->untuk($periode), '2026-03-17');

        $this->assertSame(1, $sebelum->jumlah['TL1']);
        // Potongan harian = tarif × faktor 0,2: 0,5% → 10 bp; 1,5% → 30 bp.
        $this->assertSame(20, $sesudah->totalPotongan - $sebelum->totalPotongan);
    }
}
