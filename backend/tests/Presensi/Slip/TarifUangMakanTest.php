<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\Slip\TarifUangMakan;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.2.3 AC-D09a.3 (MAKE-040) — tarif uang makan [K `Lsl_gaji.php:390-414` `0cf7e67`]: PNS golongan I–IV dari
 * `web_config` `uang_makan_gol_1..4` (ubah nilai lewat `WebConfigService` → hasil langsung berubah); `trim(gol)` harus
 * tepat ("IV/a" → 0); KP terbaru ≤ tanggal 1 bulan kehadiran; PPPK dari `gol_pppk.uang_makan` menurut mutasi jabatan
 * terakhir ≤ tanggal 1; tanpa KP / tarif belum diatur / PTT → 0. Keputusan Horii 28-10-2026: tarif `gol_pppk` tak bulat
 * sampai sen ikut legacy (tanpa galat) — total = tarif × hari dibulatkan setengah-menjauhi-nol ke sen seperti kolom
 * DECIMAL(14,2), hitungan desimal eksak (tarif `.xx5` mengikuti hitungan eksak v2).
 *
 * @internal
 */
final class TarifUangMakanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    private const TARIF = ['uang_makan_gol_1' => '35000', 'uang_makan_gol_2' => '35000', 'uang_makan_gol_3' => '37000', 'uang_makan_gol_4' => '41000'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->aturWebConfig(self::TARIF);

        foreach ([1 => 'Pegawai Negeri Sipil', 2 => 'PTT', 6 => 'Pegawai Pemerintah dengan Perjanjian Kerja'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function golongan(): iterable
    {
        yield 'I' => ['I', 3500000];
        yield 'II' => ['II', 3500000];
        yield 'III dengan spasi' => [' III ', 3700000];
        yield 'IV' => ['IV', 4100000];
        yield 'IV/a tidak cocok' => ['IV/a', 0];
        yield 'kosong' => ['', 0];
    }

    #[DataProvider('golongan')]
    public function testPnsGolonganDariWebConfig(string $gol, int $sen): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->kp($nip, '2020-01-01', $gol);

        $this->assertSame($sen, $this->senPerHari($nip, PeriodeSlip::buat(2026, 9)));
    }

    public function testKpTerbaruSampaiTanggalSatuDanUbahTarifLangsungBerlaku(): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->kp($nip, '2020-01-01', 'II');
        $this->kp($nip, '2026-09-01', 'III');
        $this->kp($nip, '2026-09-02', 'IV');

        $this->assertSame(3700000, $this->senPerHari($nip, PeriodeSlip::buat(2026, 9)), 'KP 2 Sep belum berlaku per 1 Sep');
        $this->assertSame(3500000, $this->senPerHari($nip, PeriodeSlip::buat(2026, 8)));

        $this->aturWebConfig(['uang_makan_gol_3' => '38500']);
        $this->assertSame(3850000, $this->senPerHari($nip, PeriodeSlip::buat(2026, 9)), 'regression pola AC-TK.4');
    }

    public function testTanpaKpPttAtauTarifBelumDiaturNol(): void
    {
        $tanpaKp = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $ptt     = $this->buatPegawai(['id_jenis_pegawai' => 2]);
        $this->assertSame(0, $this->senPerHari($tanpaKp, PeriodeSlip::buat(2026, 9)));
        $this->assertSame(0, $this->senPerHari($ptt, PeriodeSlip::buat(2026, 9)));
        $this->assertSame(0, $this->senPerHari('199912312099121099', PeriodeSlip::buat(2026, 9)), 'pegawai tidak ada');

        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->kp($nip, '2020-01-01', 'I');
        $this->db->table('web_config')->where('config_name', 'uang_makan_gol_1')->delete();
        $this->resetWebConfig();
        $this->assertSame(0, $this->senPerHari($nip, PeriodeSlip::buat(2026, 9)), 'tarif belum diatur');
    }

    public function testPppkDariGolPppkMutasiTerakhir(): void
    {
        $this->golPppk(120, 'IX', 40000.0);
        $this->golPppk(121, 'X', 42000.0);
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->kp($nip, '2020-01-01', 'IV'); // diabaikan untuk PPPK
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2025-01-01', 'id_gol_pppk' => 120, 'gol_pppk' => 'IX']);
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2026-09-15', 'id_gol_pppk' => 121, 'gol_pppk' => 'X']);

        $this->assertSame(4000000, $this->senPerHari($nip, PeriodeSlip::buat(2026, 9)), 'mutasi 15 Sep belum berlaku per 1 Sep');
        $this->assertSame(4200000, $this->senPerHari($nip, PeriodeSlip::buat(2026, 10)));
    }

    public function testTotalSenPembulatanSetengahMenjauhiNol(): void
    {
        $this->assertSame(74000666, TarifUangMakan::totalSen('37000.333', 20), '740006.66 tepat');
        $this->assertSame(11100100, TarifUangMakan::totalSen('37000.333', 3), '111000.999 → 111001.00');
        $this->assertSame(3700034, TarifUangMakan::totalSen('37000.335', 1), 'tepat setengah sen → menjauhi nol (hitungan eksak v2)');
        $this->assertSame(3700033, TarifUangMakan::totalSen('37000.334', 1));
        $this->assertSame(14800050, TarifUangMakan::totalSen('37000.125', 4), '148000.5 tepat');
        $this->assertSame(3700013, TarifUangMakan::totalSen('37000.125', 1), '.125 → .13');
        $this->assertSame(1, TarifUangMakan::totalSen('0.005', 1));
        $this->assertSame(0, TarifUangMakan::totalSen('0.004', 1));
        $this->assertSame(88000000, TarifUangMakan::totalSen('40000', 22), 'tarif normal tidak berubah');
        $this->assertSame(12000150, TarifUangMakan::totalSen('40000.5', 3));
        $this->assertSame(0, TarifUangMakan::totalSen('37000', 0));

        $this->assertSame('37000.333', TarifUangMakan::positif('37000.333'));
        $this->assertSame('37000.333', TarifUangMakan::positif(37000.333), 'float → 14 digit bermakna');
        $this->assertSame('40000', TarifUangMakan::positif('40000.000'));
        $this->assertSame('0', TarifUangMakan::positif(-5.0));
        $this->assertSame('0', TarifUangMakan::positif('0.00'));
        $this->assertSame('0', TarifUangMakan::positif(null));
        $this->assertSame('0', TarifUangMakan::positif('abc'));
    }

    /**
     * Pembulatan v2 = konversi MySQL/MariaDB ke `gaji_pegawai.uang_makan` DECIMAL(14,2) atas nilai desimal yang sama
     * (dibandingkan langsung di DB; legacy menulis hasil float × hari ke kolom itu, `Lsl_gaji.php:193`).
     */
    public function testSamaDenganKonversiKolomDecimal(): void
    {
        foreach ([['37000.333', 20], ['37000.333', 3], ['37000.125', 1], ['41234.567', 21], ['40000', 22], ['39999.995', 1]] as [$tarif, $hari]) {
            $eksak = $this->kaliEksak($tarif, $hari);
            $db    = (string) $this->db->query('SELECT CAST(? AS DECIMAL(14,2)) AS v', [$eksak])->getRowArray()['v'];

            $this->assertSame(str_replace('.', '', $db), (string) TarifUangMakan::totalSen($tarif, $hari), "{$tarif} × {$hari} = {$eksak}");
        }
    }

    public function testPppkTarifTidakBulatIkutLegacyTanpaGalat(): void
    {
        $this->golPppk(122, 'XI', 37000.333);
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2025-01-01', 'id_gol_pppk' => 122, 'gol_pppk' => 'XI']);

        $tarif = $this->tarif()->tarifRupiah($nip, PeriodeSlip::buat(2026, 9));
        $this->assertSame('37000.333', $tarif);
        $this->assertSame(74000666, TarifUangMakan::totalSen($tarif, 20));
    }

    private function senPerHari(string $nip, PeriodeSlip $periode): int
    {
        return TarifUangMakan::totalSen($this->tarif()->tarifRupiah($nip, $periode), 1);
    }

    /**
     * Hasil kali desimal eksak sebagai teks (pembanding untuk CAST DB).
     */
    private function kaliEksak(string $tarif, int $hari): string
    {
        [$bulat, $pecahan] = array_pad(explode('.', $tarif), 2, '');
        $angka             = (string) ((int) ($bulat . $pecahan) * $hari);
        $skala             = strlen($pecahan);

        if ($skala === 0) {
            return $angka;
        }

        $angka = str_pad($angka, $skala + 1, '0', STR_PAD_LEFT);

        return substr($angka, 0, -$skala) . '.' . substr($angka, -$skala);
    }

    private function tarif(): TarifUangMakan
    {
        return new TarifUangMakan($this->db, service('webConfigService'));
    }

    private function kp(string $nip, string $tmtsk, string $gol): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $tmtsk, 'gol' => $gol, 'gol_ruang' => $gol, 'status' => 1]);
    }

    private function golPppk(int $id, string $gol, float $uangMakan): void
    {
        $this->db->table('gol_pppk')->insert(['id_gol_pppk' => $id, 'gol_pppk' => $gol, 'uang_makan' => $uangMakan, 'status' => 1]);
    }
}
