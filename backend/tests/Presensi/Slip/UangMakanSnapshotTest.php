<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\Slip\UangMakanBelumTersedia;
use App\Libraries\Presensi\Slip\UangMakanSlip;
use App\Libraries\Presensi\Slip\UangMakanSlipInterface;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.3.7 (AC-D11.10): snapshot uang makan N−1 saat slip dibuka — fake bernilai > 0 → `uang_makan`,
 * `uang_makan_hari`, `uang_makan_periode = m-YYYY` (bulan kehadiran = periode slip − 1) ditulis; fake 0 sedangkan nilai
 * lama > 0 → nilai lama dipertahankan; fake 0 dan lama 0 → ditulis 0; `UangMakanBelumTersedia` (bawaan 5A) → kolom tidak
 * berubah & `tersedia = false`; snapshot yang sudah ada tidak dihitung ulang (termasuk setelah buka ulang); bawaan
 * `Config\Presensi::$sumberUangMakan` = `UangMakanBelumTersedia`.
 *
 * @internal
 */
final class UangMakanSnapshotTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
        $this->nip = $this->pegawaiSlip()['nip'];
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        parent::tearDown();
    }

    public function testFakeBernilaiMenulisSnapshot(): void
    {
        $fake = $this->fake(new UangMakanSlip(42500000, 17, 2500000));
        $id   = $this->slip($this->nip, 2026, 1);

        $hasil = $this->slipPegawai('2026-10-27 09:00:00', $fake)->buka($this->authPegawaiSlip($this->nip), '2026-01', '203.0.113.1');

        $this->assertSame(['nominal' => '425000.00', 'hari' => 17, 'periode_kehadiran' => '2025-12', 'tersedia' => true], $hasil['uang_makan']);
        $this->assertSame('5025000.00', $hasil['ringkasan']['total_penghasilan'], 'total termasuk uang makan');
        $this->assertSame([[$this->nip, '2025-12']], $fake->panggilan, 'bulan kehadiran = periode slip − 1');
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'uang_makan' => '425000.00', 'uang_makan_hari' => 17, 'uang_makan_periode' => '12-2025']);

        // Buka ulang lalu buka lagi: snapshot dipertahankan, tidak dihitung ulang.
        $this->slipAdmin()->bukaUlang($this->authAdminSlip(), $id);
        $fakeLain = $this->fake(new UangMakanSlip(1, 1, 1));
        $this->slipPegawai('2026-10-27 10:00:00', $fakeLain)->buka($this->authPegawaiSlip($this->nip), '2026-01', '203.0.113.1');
        $this->assertSame([], $fakeLain->panggilan);
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'uang_makan' => '425000.00', 'uang_makan_periode' => '12-2025']);
    }

    public function testNolDanNilaiLama(): void
    {
        $lama = $this->slip($this->nip, 2026, 2, ['uang_makan' => '150000.00', 'uang_makan_hari' => 6]);
        $this->slipPegawai('2026-10-27 09:00:00', $this->fake(new UangMakanSlip(0, 0, 2500000)))->buka($this->authPegawaiSlip($this->nip), '2026-02', '203.0.113.1');
        $this->seeInDatabase('gaji_pegawai', ['id' => $lama, 'uang_makan' => '150000.00', 'uang_makan_hari' => 6, 'uang_makan_periode' => null]);

        $nol = $this->slip($this->nip, 2026, 3);
        $this->slipPegawai('2026-10-27 09:00:00', $this->fake(new UangMakanSlip(0, 0, 2500000)))->buka($this->authPegawaiSlip($this->nip), '2026-03', '203.0.113.1');
        $this->seeInDatabase('gaji_pegawai', ['id' => $nol, 'uang_makan' => '0.00', 'uang_makan_hari' => 0, 'uang_makan_periode' => '2-2026']);
    }

    public function testBelumTersediaTidakMengubahKolom(): void
    {
        $this->assertSame(UangMakanBelumTersedia::class, (new Presensi())->sumberUangMakan);
        $id = $this->slip($this->nip, 2026, 4, ['uang_makan' => '10000.00']);

        $hasil = $this->slipPegawai()->buka($this->authPegawaiSlip($this->nip), '2026-04', '203.0.113.1');

        $this->assertFalse($hasil['uang_makan']['tersedia']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'uang_makan' => '10000.00', 'uang_makan_hari' => null, 'uang_makan_periode' => null]);
    }

    private function fake(?UangMakanSlip $hasil): UangMakanPalsu
    {
        return new UangMakanPalsu($hasil);
    }
}

/**
 * Sumber uang makan palsu: mengembalikan hasil tetap dan mencatat setiap panggilan (nip, periode kehadiran).
 *
 * @internal
 */
final class UangMakanPalsu implements UangMakanSlipInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $panggilan = [];

    public function __construct(private readonly ?UangMakanSlip $hasil)
    {
    }

    public function hitung(string $nip, PeriodeSlip $periodeKehadiran): ?UangMakanSlip
    {
        $this->panggilan[] = [$nip, $periodeKehadiran->kunci()];

        return $this->hasil;
    }
}
