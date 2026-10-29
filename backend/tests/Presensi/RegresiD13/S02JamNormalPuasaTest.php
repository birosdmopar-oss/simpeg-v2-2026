<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S02 — Jam kerja normal vs puasa, Jumat beda jam (PRD 5C §7.6): TL/PSW dinilai terhadap jam `web_config`
 * (`jam_masuk_normal`, `jam_pulang_normal`, `jam_pulang_jumat_normal`, `jam_masuk_puasa`, `jam_pulang_puasa`,
 * `jam_pulang_jumat_puasa`) dan `rentang_puasa` (inklusif). Nilai di test sengaja berbeda dari bawaan 07:30/16:00/16:30 agar
 * terbukti dibaca dari `web_config`. Legacy [K] `L_presensi.php:12722-12725` (jam & rentang puasa), `:12888-12931` (TL/PSW).
 *
 * Test lama yang dicakup: `Presensi/TukinData/KonfigurasiTukinDariWebConfigTest::testEmpatKombinasiJamKerjaDariWebConfig`
 * (adapter saja), `unit/Presensi/Rekap/KlasifikasiKehadiranTest` (rekap kehadiran, bukan Tukin).
 *
 * @internal
 */
#[Group('d13')]
final class S02JamNormalPuasaTest extends DatabaseTestCase
{
    use RegresiD13UjiTrait;

    /** Puasa uji Senin 7 – Jumat 11 Sep 2026. */
    private const PUASA = ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11'];

    private const JUMAT = ['2026-08-21', '2026-08-28', '2026-09-04', '2026-09-11'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanD13();
        $this->aturWebConfig([
            'jam_masuk_normal'        => '07:15',
            'jam_pulang_normal'       => '16:05',
            'jam_pulang_jumat_normal' => '16:35',
            'jam_masuk_puasa'         => '08:10',
            'jam_pulang_puasa'        => '15:05',
            'jam_pulang_jumat_puasa'  => '15:35',
            'rentang_puasa'           => '2026-09-07..2026-09-11',
        ]);
    }

    protected function tearDown(): void
    {
        $this->bersihkanD13();
        parent::tearDown();
    }

    public function testTlPswMemakaiJamWebConfigNormalPuasaDanJumat(): void
    {
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->tepatWaktuSepanjangPeriode($nip);

        $this->presensiD13($nip, '2026-08-19', '07:30:00', '16:05:00'); // normal Rabu: 15 menit terlambat → TL1 (bawaan 07:30 = tepat)
        $this->presensiD13($nip, '2026-08-20', '07:15:00', '16:05:00'); // normal Kamis pulang 16:05 → tepat
        $this->presensiD13($nip, '2026-08-21', '07:15:00', '16:05:00'); // normal Jumat pulang 16:05 → PSW1 (Jumat 16:35)
        $this->presensiD13($nip, '2026-09-08', '08:10:00', '15:05:00'); // puasa Selasa → tepat (normal = TL2)
        $this->presensiD13($nip, '2026-09-09', '08:20:00', '15:05:00'); // puasa Rabu 10 menit terlambat → TL1
        $this->presensiD13($nip, '2026-09-10', '08:10:00', '15:05:00'); // puasa Kamis pulang 15:05 → tepat
        $this->presensiD13($nip, '2026-09-11', '08:10:00', '15:05:00'); // puasa Jumat pulang 15:05 → PSW1 (Jumat puasa 15:35)
        $this->presensiD13($nip, '2026-09-14', '08:10:00', '16:05:00'); // sesudah rentang puasa: jam normal → 55 menit → TL2

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        foreach (['2026-08-20', '2026-09-07', '2026-09-08', '2026-09-10'] as $tgl) {
            $this->assertSame('0.00', $harian[$tgl]['potongan'], "tepat waktu {$tgl}");
        }

        $this->assertSame(['0.10', '0.10', '0.10', '0.10', '0.20'], [
            $harian['2026-08-19']['potongan'], $harian['2026-08-21']['potongan'], $harian['2026-09-09']['potongan'], $harian['2026-09-11']['potongan'], $harian['2026-09-14']['potongan'],
        ]);
        $this->assertSame(['TL1' => 2, 'TL2' => 1, 'PSW1' => 2], array_intersect_key(array_filter($laporan['jumlah']), ['TL1' => 0, 'TL2' => 0, 'PSW1' => 0]));
        $this->assertSame(['0.40', '0.20', '0.00', '0.60'], [$laporan['rincian']['TL'], $laporan['rincian']['PSW'], $laporan['rincian']['TK'] ?? '0.00', $laporan['total_presensi']]);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testUbahJamWebConfigLangsungBerlaku(): void
    {
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->tepatWaktuSepanjangPeriode($nip);
        $this->presensiD13($nip, '2026-08-19', '07:30:00', '16:05:00');
        $this->assertSame('0.10', $this->laporanD13($nip)['rincian']['TL']);

        $this->aturWebConfig(['jam_masuk_normal' => '07:30']);
        $this->assertSame('0.00', $this->laporanD13($nip)['rincian']['TL'] ?? '0.00', 'jam masuk 07:30 → 07:30 tepat');
    }

    /** Presensi tepat waktu menurut jam uji di semua hari kerja periode 2026-09. */
    private function tepatWaktuSepanjangPeriode(string $nip): void
    {
        foreach ([...array_map(static fn (int $h): string => sprintf('2026-08-%02d', $h), [17, 18, 19, 20, 21, 24, 25, 26, 27, 28, 31]),
            ...array_map(static fn (int $h): string => sprintf('2026-09-%02d', $h), [1, 2, 3, 4, 7, 8, 9, 10, 11, 14, 15])] as $tgl) {
            $puasa = in_array($tgl, self::PUASA, true);
            $jumat = in_array($tgl, self::JUMAT, true);
            $this->hadir($nip, $tgl, $puasa ? '08:10:00' : '07:15:00', match (true) {
                $puasa && $jumat => '15:35:00',
                $puasa           => '15:05:00',
                $jumat           => '16:35:00',
                default          => '16:05:00',
            });
        }
    }
}
