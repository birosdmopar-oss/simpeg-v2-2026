<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\HasilTukin;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\KalkulasiTukinIl;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-085 (keputusan Horii 30-10): rekap IL ikut legacy `laporan_tukin_us_skp_il` (`L_presensi.php:13494-15452`) — per
 * aturan IL, dibandingkan dengan DH (`KalkulasiTukin`) pada data yang sama. Periode 16-08 s.d. 15-09-2026 (22 hari kerja),
 * hari ini 27-10-2026, tarif TL1/PSW1 0,5; TK 3; SKP tanpa data 32.
 *
 * @internal
 */
final class KalkulasiTukinIlTest extends CIUnitTestCase
{
    private const HARI_INI = '2026-10-27';

    private PeriodeTukin $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->periode = new PeriodeTukin('2026-08-16', '2026-09-15');
    }

    /** (a) Cuti besar sepanjang periode: IL CUTI 25 × 0,2 = 5 di 01-09 + SKP 32 = 37; DH pengganti cuti besar = 5. */
    public function testCutiBesarSepanjangPeriode(): void
    {
        $harian = $this->cuti('besar', '2026-08-01', '2026-10-31', 66);

        $il = $this->il($harian);
        $this->assertSame([500, 500, 3200, 3700, null], [$il->rincian['CUTI'], $il->totalPresensi, $il->totalSkp, $il->totalPotongan, $il->alasan]);
        $this->assertSame(500, $this->dh($harian)->totalPotongan, 'pembanding DH');
    }

    /** (b) Cuti tahunan sepanjang periode: IL tetap memotong SKP (tanpa `cutiFullPeriode`); DH SKP 0. */
    public function testCutiTahunanSepanjangPeriodeTetapPotongSkp(): void
    {
        $harian = $this->cuti('tahunan', '2026-08-14', '2026-09-16', 24);

        $this->assertSame([0, 3200, 3200], [$this->il($harian)->totalPresensi, $this->il($harian)->totalSkp, $this->il($harian)->totalPotongan]);
        $this->assertSame([0, 'cuti_sepanjang_periode'], [$this->dh($harian)->totalPotongan, $this->dh($harian)->alasan], 'pembanding DH');
    }

    /** (c) TB di hari kerja terakhir dengan potongan presensi sebelumnya: IL total = potongan presensi (SKP 0); DH 0. */
    public function testTbAkhirPeriodeHanyaSkpDinolkan(): void
    {
        $harian = $this->hadirSemua(['2026-08-17']); // 17-08 TK = 3 × 0,2 = 0,6
        foreach (['2026-09-14', '2026-09-15'] as $t) {
            $harian[$t] = ['tugas_belajar' => true];
        }

        $il = $this->il($harian);
        $this->assertSame([60, 0, 60, null], [$il->totalPresensi, $il->totalSkp, $il->totalPotongan, $il->alasan]);
        $this->assertSame([0, 'pemutihan_tb'], [$this->dh($harian)->totalPotongan, $this->dh($harian)->alasan], 'pembanding DH');

        // TB bukan di hari terakhir → SKP tetap.
        unset($harian['2026-09-15']);
        $harian['2026-09-15'] = ['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']];
        $this->assertSame([60, 3200], [$this->il($harian)->totalPresensi, $this->il($harian)->totalSkp]);
    }

    /**
     * Cuti melahirkan IL: anak ≥ 3 (DH > 3); mulai 10-08 (≤ 15) → potong 01-08, 01-09 (lama ≥ 32) 70 × 0,2 = 14, 01-10;
     * hanya 01-09 dalam loop. SKP tidak dinolkan (total_skp = 0 harian ditimpa akhir loop).
     */
    public function testCutiMelahirkanAnakTigaPerTanggal(): void
    {
        $harian = $this->cuti('melahirkan', '2026-08-10', '2026-11-07', 90);

        $il = $this->il($harian, 3);
        $this->assertSame([1400, 3200, 4600], [$il->rincian['CUTI'], $il->totalSkp, $il->totalPotongan]);
        $this->assertSame(0, $this->il($harian, 2)->rincian['CUTI'], 'anak 2: tanpa potongan');

        // Mulai 20-08 (> 15) → potong ke-1 01-09 = 40 × 0,2 = 8.
        $this->assertSame(800, $this->il($this->cuti('melahirkan', '2026-08-20', '2026-09-20', 30), 3)->rincian['CUTI']);
    }

    /**
     * Cuti alasan penting > 14 hari: bulan dengan hari kerja terbanyak (Sep 14 vs Agu 5) → hari kerja pertama 01-09, 50 ×
     * 0,2 = 10; ≤ 14 hari tanpa potongan; DH tidak memotong alasan penting. Libur 01-09 → hari kerja pertama 02-09.
     */
    public function testCutiAlasanPentingLebih14Hari(): void
    {
        $harian = $this->cuti('alasan_penting', '2026-08-25', '2026-09-18', 19);

        $this->assertSame([1000, 4200], [$this->il($harian)->rincian['CUTI'], $this->il($harian)->totalPotongan]);
        $this->assertSame(0, $this->dh($harian)->rincian['CUTI'], 'pembanding DH');
        $this->assertSame(0, $this->il($this->cuti('alasan_penting', '2026-08-25', '2026-09-18', 14))->rincian['CUTI'], 'lama 14');

        $potong = KalkulasiTukinIl::jadwalPotonganCuti('2026-08-16', '2026-09-15', $harian, HariKerja::himpunanLibur(['2026-09-01']), 0, $this->config());
        $this->assertSame(['2026-09-02' => 1000], $potong, 'libur dilewati');
    }

    /** Alasan penting **menimpa** nilai tanggal potong yang sama (`=`), cuti besar menambah (`+=`), urut tanggal mulai. */
    public function testAlasanPentingMenimpaUrutTanggalMulai(): void
    {
        $besar = ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-08-01', 'akhir' => '2026-09-30', 'lama' => 61]];
        $this->assertSame(['2026-08-01' => 500, '2026-09-01' => 500], KalkulasiTukinIl::jadwalPotonganCuti('2026-08-16', '2026-09-15', ['2026-08-17' => $besar], [], 0, $this->config()));

        // Alasan penting (mulai sesudah cuti besar) bertanggal potong 01-09 → nilai 01-09 diganti 10, bukan 5 + 10.
        $gabung = KalkulasiTukinIl::jadwalPotonganCuti('2026-08-16', '2026-09-15', [
            '2026-08-17' => $besar,
            '2026-09-01' => ['cuti' => ['jenis' => 'alasan_penting', 'mulai' => '2026-09-01', 'akhir' => '2026-09-30', 'lama' => 22]],
        ], [], 0, $this->config());
        $this->assertSame(['2026-08-01' => 500, '2026-09-01' => 1000], $gabung);
    }

    /**
     * Urutan cuti sakit IL diputus konket / cuti jenis lain (DH tidak): 16 hari kerja cuti sakit dengan konket di tengah →
     * IL tanpa potongan CS, DH hari ke-15 & 16 dipotong 0,2.
     */
    public function testUrutanCutiSakitDiputusKonketDanCutiLain(): void
    {
        $harian               = array_replace($this->cuti('sakit', '2026-08-17', '2026-08-25', 7), $this->hanyaCuti('sakit', '2026-08-27', '2026-09-08', 9));
        $harian['2026-08-26'] = ['konket' => ['affect_tukin' => 1, 'kategori' => 1]];

        $this->assertSame(0, $this->il($harian)->rincian['CUTI'], 'IL: konket memutus');
        $this->assertSame(40, $this->dh($harian)->rincian['CUTI'], 'DH: konket tidak memutus');

        $harian['2026-08-26'] = ['cuti' => ['jenis' => 'tahunan', 'mulai' => '2026-08-26', 'akhir' => '2026-08-26', 'lama' => 1]];
        $this->assertSame(0, $this->il($harian)->rincian['CUTI'], 'IL: cuti lain memutus');
        $this->assertSame(40, $this->dh($harian)->rincian['CUTI'], 'DH: cuti lain tidak memutus');

        // Tanpa pemutus (26-08 juga cuti sakit, 17 hari): hari ke-15..17 dipotong.
        $harian['2026-08-26'] = $harian['2026-08-25'];
        $this->assertSame([60, ['CS' => 3]], [$this->il($harian)->rincian['CUTI'], array_filter(array_intersect_key($this->il($harian)->jumlah, ['CS' => 1]))]);
    }

    /**
     * Bawaan cuti sakit periode lalu hanya dari cuti sakit yang bulan mulai/akhirnya ∈ {Juli, Agustus} (`$listCS`; periode
     * lalu 16-07..15-08, hari kerja terakhir 14-08 − 1 bulan = Juli, hari kerja pertama 17-08 = Agustus).
     */
    public function testBawaanCutiSakitSaringBulan(): void
    {
        // 03-08..31-08: bawaan 10 hari kerja → hari ke-15 = 21-08; 21, 24..28, 31 = 7 hari × 0,2.
        $dalam = $this->cuti('sakit', '2026-08-03', '2026-08-31', 21);
        $this->assertSame([140, 140], [$this->il($dalam)->rincian['CUTI'], $this->dh($dalam)->rincian['CUTI']]);

        // 15-06..04-09 (bulan 6 & 9): IL tanpa bawaan → hari ke-15 = 04-09 saja; DH membawa 22 hari → 15 hari dipotong.
        $luar = $this->cuti('sakit', '2026-06-15', '2026-09-04', 82);
        $this->assertSame(20, $this->il($luar)->rincian['CUTI'], 'IL: bulan di luar saringan');
        $this->assertSame(300, $this->dh($luar)->rincian['CUTI'], 'pembanding DH');
    }

    /**
     * Ambang cuti besar memakai jumlah **hari kalender** `tgl_mulai`..`tgl_akhir` (`$iLC->days`, :14301-14304), bukan
     * `lama_cuti`: 10-08..20-09 = 42 hari (lama 30) → potong 01-08 & 01-09 → periode Sep CUTI 5.
     */
    public function testCutiBesarAmbangHariKalender(): void
    {
        $this->assertSame([500, 3700], [$this->il($this->cuti('besar', '2026-08-10', '2026-09-20', 30))->rincian['CUTI'], $this->il($this->cuti('besar', '2026-08-10', '2026-09-20', 30))->totalPotongan]);
    }

    /**
     * Ambang cuti melahirkan memakai `lama_cuti` (:14396, :14410), bukan hari kalender: 03-08..04-09 = 33 hari, lama 25 →
     * hanya potong 01-08 (di luar periode Sep) → CUTI 0.
     */
    public function testCutiMelahirkanAmbangLamaCuti(): void
    {
        $this->assertSame(0, $this->il($this->cuti('melahirkan', '2026-08-03', '2026-09-04', 25), 3)->rincian['CUTI']);
    }

    /** Lintas periode November (16-10..15-11): cuti besar 05-10..30-11 → potong 01-10, 01-11 → CUTI 5 di periode Nov. */
    public function testCutiBesarLintasPeriodeNovember(): void
    {
        $nov    = new PeriodeTukin('2026-10-16', '2026-11-15');
        $harian = [];

        foreach (HariKerja::kalender('2026-10-05', '2026-11-15') as $t) {
            $harian[$t] = ['cuti' => ['jenis' => 'besar', 'mulai' => '2026-10-05', 'akhir' => '2026-11-30', 'lama' => 40]];
        }

        $h = KalkulasiTukinIl::hitung($nov, [], $harian, $this->config(), '2026-12-01', new DataPegawaiTukin(null));
        $this->assertSame([500, 3200, 3700], [$h->rincian['CUTI'], $h->totalSkp, $h->totalPotongan]);
    }

    /**
     * B (CR-085 review): riwayat yang semua tanggalnya tertimpa (cuti besar id 100 01-09..30-11 ditimpa cuti tahunan id 101
     * 01-09..18-09 di setiap tanggal periode) tetap dijadwalkan bila daftar riwayat utuh diberikan (legacy `$listCuti`):
     * CUTI 5 (total 37); tanpa daftar (rekonstruksi harian) tidak terlihat (32).
     */
    public function testRiwayatTertimpaTetapDijadwalkan(): void
    {
        $harian  = $this->hanyaCuti('tahunan', '2026-09-01', '2026-09-18', 14) + $this->hadirSemua();
        $riwayat = [
            ['id' => 101, 'jenis' => 'tahunan', 'mulai' => '2026-09-01', 'akhir' => '2026-09-18', 'lama' => 14],
            ['id' => 100, 'jenis' => 'besar', 'mulai' => '2026-09-01', 'akhir' => '2026-11-30', 'lama' => 65],
            ['id' => 100, 'jenis' => 'besar', 'mulai' => '2026-09-01', 'akhir' => '2026-11-30', 'lama' => 65], // ganda → satu
        ];

        $h = KalkulasiTukinIl::hitung($this->periode, [], $harian, $this->config(), self::HARI_INI, new DataPegawaiTukin(null), $riwayat);
        $this->assertSame([500, 3700], [$h->rincian['CUTI'], $h->totalPotongan]);
        $this->assertSame(3200, $this->il($harian)->totalPotongan, 'tanpa daftar riwayat: tertimpa hilang');
    }

    // ---------------------------------------------------------------- bantu

    /**
     * @param array<string, array<string, mixed>> $harian
     */
    private function il(array $harian, int $anak = 0): HasilTukin
    {
        return KalkulasiTukinIl::hitung($this->periode, [], $harian, $this->config(), self::HARI_INI, new DataPegawaiTukin(null, $anak));
    }

    /**
     * @param array<string, array<string, mixed>> $harian
     */
    private function dh(array $harian): HasilTukin
    {
        return KalkulasiTukin::hitung($this->periode, [], $harian, $this->config(), self::HARI_INI, new DataPegawaiTukin(null));
    }

    /**
     * Cuti pada setiap tanggal [max(mulai, 16-07), min(akhir, 15-09)] (input harian adapter); hari kerja lain hadir.
     *
     * @return array<string, array<string, mixed>>
     */
    private function cuti(string $jenis, string $mulai, string $akhir, int $lama): array
    {
        return $this->hanyaCuti($jenis, $mulai, $akhir, $lama) + $this->hadirSemua();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hanyaCuti(string $jenis, string $mulai, string $akhir, int $lama): array
    {
        $harian = [];

        foreach (HariKerja::kalender(max($mulai, '2026-07-16'), min($akhir, '2026-09-15')) as $t) {
            $harian[$t] = ['cuti' => ['jenis' => $jenis, 'mulai' => $mulai, 'akhir' => $akhir, 'lama' => $lama]];
        }

        return $harian;
    }

    /**
     * @param list<string> $tanpa
     *
     * @return array<string, array<string, mixed>>
     */
    private function hadirSemua(array $tanpa = []): array
    {
        $harian = [];

        foreach (HariKerja::daftar($this->periode, []) as $t) {
            if (! in_array($t, $tanpa, true)) {
                $harian[$t] = ['presensi' => ['masuk' => '07:30', 'pulang' => HariKerja::nomorHari($t) === 5 ? '16:30' : '16:00']];
            }
        }

        return $harian;
    }

    private function config(): KonfigurasiTukin
    {
        return new KonfigurasiTukin(['TL1/PSW1' => '0.5', 'TL2/PSW2' => 1, 'TL3/PSW3' => '1.25', 'TA' => '1.5', 'TK' => 3]);
    }
}
