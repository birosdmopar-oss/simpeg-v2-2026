<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S12 — Ubah tarif `web_config` (regresi Fase 2; PRD 5C §7.6, QAFUNC-006 "regression ubah tarif"): tarif TL1/PSW1 dan
 * TK diubah lewat `WebConfigService` → rekap & laporan per pegawai langsung berubah tanpa langkah lain; tarif wajib
 * dihapus → semua baris galat `konfigurasi_tukin_tidak_lengkap` (bukan 0). Legacy [K]: tarif dibaca dari `web_config`
 * setiap permintaan (`L_presensi.php` rekap `laporan_tukin_us_skp`), faktor presensi 0,2 (`:12854`).
 *
 * Test lama yang dicakup: `Presensi/TukinData/UbahTarifWebConfigTest::testUbahTarifLangsungMengubahPotongan`,
 * `Presensi/Laporan/RekapTukinTest::testTarifBelumLengkapSemuaBarisGalatDanUbahTarifBerlaku`.
 *
 * @internal
 */
#[Group('d13')]
final class S12UbahTarifWebConfigTest extends DatabaseTestCase
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

    public function testUbahTarifLangsungMengubahRekapDanLaporan(): void
    {
        $nip = $this->pegawaiHadirD13(['2026-08-19']);
        $this->presensiD13($nip, '2026-08-18', '07:45:00', '16:00:00'); // TL1

        $sebelum = $this->laporanD13($nip);
        $this->assertSame(['0.10', '0.60', '0.70'], [$sebelum['rincian']['TL'], $sebelum['rincian']['TK'], $sebelum['total_presensi']]);
        $this->assertRekapSamaLaporanD13($nip, $sebelum);

        $this->aturWebConfig(['TL1/PSW1' => '1', 'TK' => '4']);

        $sesudah = $this->laporanD13($nip);
        $this->assertSame(['0.20', '0.80', '1.00'], [$sesudah['rincian']['TL'], $sesudah['rincian']['TK'], $sesudah['total_presensi']], 'laporan per pegawai langsung berubah');
        $this->assertRekapSamaLaporanD13($nip, $sesudah);
        $this->assertSame('1.00', $this->barisRekapD13($nip)['total_presensi'], 'rekap langsung berubah');
    }

    public function testTarifWajibDihapusSemuaBarisGalat(): void
    {
        $nip = $this->pegawaiHadirD13();
        $this->db->table('web_config')->where('config_name', 'TK')->delete();
        $this->resetWebConfig();

        $baris = $this->barisRekapD13($nip);
        $this->assertSame('konfigurasi_tukin_tidak_lengkap', $baris['galat']['code']);
        $this->assertNull($baris['total_potongan'], 'bukan 0');
    }
}
