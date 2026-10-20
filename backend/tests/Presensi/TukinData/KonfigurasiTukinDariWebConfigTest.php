<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\JamKerja;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\DataTukinTidakSah;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinDariWebConfig;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinTidakLengkap;
use App\Libraries\Presensi\TukinData\RentangPuasaGanda;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5A §7.7.3 — AC-TK.2 (jam kerja normal/puasa × Jumat dari `web_config`, rentang puasa beririsan) dan AC-TK.3 (tarif
 * dari `web_config`; tarif belum diatur → galat terkendali, bukan 0).
 *
 * @internal
 */
final class KonfigurasiTukinDariWebConfigTest extends DatabaseTestCase
{
    use TukinDataUjiTrait;

    private PeriodeTukin $maret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->maret = PeriodeTukin::dariBulan(2026, 3);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testTarifBelumDiaturDitolakTerkendali(): void
    {
        try {
            $this->adapter()->untuk($this->maret);
            $this->fail('tarif kosong harus ditolak');
        } catch (KonfigurasiTukinTidakLengkap $e) {
            $this->assertSame(KonfigurasiTukin::KUNCI_WAJIB, $e->kunci);
            $this->assertStringContainsString('TL1/PSW1', $e->getMessage());
        }

        $this->aturWebConfig(array_diff_key(self::TARIF_UJI, ['TA' => 1]));

        try {
            $this->adapter()->untuk($this->maret);
            $this->fail('satu tarif kosong harus ditolak');
        } catch (KonfigurasiTukinTidakLengkap $e) {
            $this->assertSame(['TA'], $e->kunci, 'bukan diganti 0');
        }
    }

    public function testTarifDariWebConfigDalamBasisPoin(): void
    {
        $this->aturWebConfig(self::TARIF_UJI);
        $konfigurasi = $this->adapter()->untuk($this->maret);

        $this->assertSame(50, $konfigurasi->tarif('TL1/PSW1'));
        $this->assertSame(100, $konfigurasi->tarif('TL2/PSW2'));
        $this->assertSame(125, $konfigurasi->tarif('TL3/PSW3'));
        $this->assertSame(150, $konfigurasi->tarif('TA'));
        $this->assertSame(300, $konfigurasi->tarif('TK'));
        $this->assertSame(KonfigurasiTukin::TARIF_BAWAAN['CUTI_SAKIT'] * 100, $konfigurasi->tarif('CUTI_SAKIT'), 'tarif opsional = bawaan rekap');
    }

    public function testTarifYangDitolakLibraryJadiGalatTerkendali(): void
    {
        // 0.01% × faktor 0,2 tidak bulat dalam basis poin → library menolak; adapter membungkusnya.
        $this->aturWebConfig(['TL1/PSW1' => '0.01'] + self::TARIF_UJI);

        $this->expectException(DataTukinTidakSah::class);
        $this->adapter()->untuk($this->maret);
    }

    public function testEmpatKombinasiJamKerjaDariWebConfig(): void
    {
        $this->aturWebConfig(self::TARIF_UJI + [
            'jam_masuk_normal'        => '07:15',
            'jam_pulang_normal'       => '16:05',
            'jam_pulang_jumat_normal' => '16:35',
            'jam_masuk_puasa'         => '08:10',
            'jam_pulang_puasa'        => '15:05',
            'jam_pulang_jumat_puasa'  => '15:35',
            'rentang_puasa'           => "2025-03-01..2025-03-30\n2026-02-18..2026-03-19",
        ]);

        $konfigurasi = $this->adapter()->untuk($this->maret);
        $this->assertSame(['2026-02-18', '2026-03-19'], [$konfigurasi->puasaMulai, $konfigurasi->puasaSelesai], 'rentang yang beririsan dipilih');

        $this->assertSame(['masuk' => '07:15:00', 'pulang' => '16:05:00'], JamKerja::untuk('2026-03-10', $this->ubahPuasa($konfigurasi, null)), 'normal Selasa');
        $this->assertSame(['masuk' => '07:15:00', 'pulang' => '16:35:00'], JamKerja::untuk('2026-03-13', $this->ubahPuasa($konfigurasi, null)), 'normal Jumat');
        $this->assertSame(['masuk' => '08:10:00', 'pulang' => '15:05:00'], JamKerja::untuk('2026-03-10', $konfigurasi), 'puasa Selasa');
        $this->assertSame(['masuk' => '08:10:00', 'pulang' => '15:35:00'], JamKerja::untuk('2026-03-13', $konfigurasi), 'puasa Jumat');
        $this->assertSame(['masuk' => '07:15:00', 'pulang' => '16:05:00'], JamKerja::untuk('2026-03-10', $this->adapter()->untuk(PeriodeTukin::dariBulan(2026, 6))), 'periode tanpa irisan puasa');
    }

    public function testJamBawaanBilaBelumDiatur(): void
    {
        $this->aturWebConfig(self::TARIF_UJI);
        $konfigurasi = $this->adapter()->untuk($this->maret);

        $this->assertSame(['masuk' => '07:30:00', 'pulang' => '16:00:00'], JamKerja::untuk('2026-03-10', $konfigurasi));
        $this->assertSame(['masuk' => '07:30:00', 'pulang' => '16:30:00'], JamKerja::untuk('2026-03-13', $konfigurasi));
        $this->assertNull($konfigurasi->puasaMulai);
    }

    public function testDuaRentangPuasaBeririsanDitolak(): void
    {
        $this->aturWebConfig(self::TARIF_UJI + ['rentang_puasa' => "2026-02-10..2026-02-20\n2026-03-01..2026-03-05"]);

        $this->expectException(RentangPuasaGanda::class);
        $this->adapter()->untuk($this->maret);
    }

    public function testPersenKanonik(): void
    {
        $this->assertSame('0.5', KonfigurasiTukinDariWebConfig::persen(0.5));
        $this->assertSame('1', KonfigurasiTukinDariWebConfig::persen(1.0));
        $this->assertSame('3', KonfigurasiTukinDariWebConfig::persen(3));
        $this->assertSame('1.25', KonfigurasiTukinDariWebConfig::persen(1.25));
        $this->assertSame('0.01', KonfigurasiTukinDariWebConfig::persen(0.01));
    }

    private function adapter(): KonfigurasiTukinDariWebConfig
    {
        return new KonfigurasiTukinDariWebConfig(service('webConfigService'));
    }

    private function ubahPuasa(KonfigurasiTukin $k, ?string $mulai): KonfigurasiTukin
    {
        return new KonfigurasiTukin(
            array_map(static fn (int $bp): string => (string) ($bp / 100), array_intersect_key($k->tarif, array_flip(KonfigurasiTukin::KUNCI_WAJIB))),
            $k->jamMasukNormal,
            $k->jamPulangNormal,
            $k->jamPulangJumatNormal,
            $k->jamMasukPuasa,
            $k->jamPulangPuasa,
            $k->jamPulangJumatPuasa,
            $mulai,
            $mulai,
        );
    }
}
