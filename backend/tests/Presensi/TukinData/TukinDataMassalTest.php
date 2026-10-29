<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\ArgumenTukin;
use App\Libraries\Presensi\TukinData\DataTukinTidakSah;
use App\Libraries\Presensi\TukinData\GalatDataTukin;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinTidakLengkap;
use App\Libraries\Presensi\TukinData\SumberCutiTbFase4;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use App\Libraries\Presensi\TukinData\SumberHarianTukin;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\CutiTbUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;
use Throwable;

/**
 * PRD 5B §7.1.4, AC-D06.9 + keputusan reviewer CR M0 F1 (MAKE-039) — jalur massal Tukin:
 * - kesetaraan: `TukinDataService::siapkanBanyak()` = `siapkan()` per NIP (termasuk galat), begitu pula
 *   `SumberHarianTukin::untukBanyak()` = `untuk()` dan `SumberDataPegawaiTukin::untukBanyak()` = `untuk()`, pada data
 *   sintetis beragam (presensi, konket, cuti, TB + perpanjangan, SKP BKN, anak, PPPK, CPNS);
 * - jumlah kueri tetap terhadap jumlah NIP (dihitung lewat event `DBQuery`, bukan perkiraan);
 * - galat satu pegawai (jam presensi tidak sah, jenis cuti di luar 1..6, NIP tidak ada) tidak menggagalkan pegawai lain;
 *   konfigurasi tarif belum lengkap → semua NIP galat `KonfigurasiTukinTidakLengkap` (AC-D08.7, bukan 0).
 *
 * @internal
 */
final class TukinDataMassalTest extends DatabaseTestCase
{
    use CutiTbUjiTrait;
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    /** @var list<string> */
    private array $kueri = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->aturWebConfig(self::TARIF_UJI);

        foreach ([1 => 'Pegawai Negeri Sipil', 6 => 'Pegawai Pemerintah dengan Perjanjian Kerja'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('DBQuery');
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testSiapkanBanyakSamaDenganSiapkanPerNip(): void
    {
        $nip = $this->dataBeragam();
        $nip = [...$nip, '199912312099121099'];

        $massal = $this->serviceTukinFase4()->siapkanBanyak([...$nip, $nip[0]], 2026, 4);

        $this->assertSame($nip, array_map('strval', array_keys($massal)), 'satu kunci per NIP unik, urutan masukan');

        foreach ($nip as $n) {
            try {
                $tunggal = $this->serviceTukinFase4()->siapkan($n, 2026, 4);
                $this->assertInstanceOf(ArgumenTukin::class, $massal[$n], $n);
                $this->assertEquals($tunggal, $massal[$n], $n);
            } catch (GalatDataTukin $e) {
                $this->assertInstanceOf($e::class, $massal[$n], $n);
                $this->assertSame($e->getMessage(), $massal[$n]->getMessage(), $n);
            }
        }

        [$normal, , , , $jamRusak, $cutiRusak] = $nip;
        $this->assertInstanceOf(ArgumenTukin::class, $massal[$normal]);
        $this->assertInstanceOf(DataTukinTidakSah::class, $massal[$jamRusak], 'jam presensi tidak sah');
        $this->assertInstanceOf(DataTukinTidakSah::class, $massal[$cutiRusak], 'jenis cuti di luar 1..6');
        $this->assertInstanceOf(DataTukinTidakSah::class, $massal['199912312099121099'], 'NIP tidak ada');
    }

    public function testSumberHarianDanDataPegawaiMassalSamaDenganTunggal(): void
    {
        $nip     = $this->dataBeragam();
        $periode = PeriodeTukin::dariBulan(2026, 4);
        $harian  = new SumberHarianTukin($this->db, new SumberCutiTbFase4($this->db));
        $pegawai = new SumberDataPegawaiTukin($this->db);
        $bkn     = 'BKN-2026-04-utama';

        $mH = $harian->untukBanyak($nip, '2026-02-16', '2026-04-15');
        $mP = $pegawai->untukBanyak($nip, $periode, $bkn, ['2026-03-18']);

        foreach ($nip as $n) {
            $this->assertSame($this->hasilAtauGalat(static fn () => $harian->untuk($n, '2026-02-16', '2026-04-15')), $this->ringkas($mH[$n]), "harian {$n}");
            $this->assertEquals($this->hasilAtauGalat(static fn () => $pegawai->untuk($n, $periode, $bkn, ['2026-03-18'])), $this->ringkas($mP[$n]), "pegawai {$n}");
        }
    }

    public function testJumlahKueriTetapTerhadapJumlahNip(): void
    {
        $beragam = $this->dataBeragam();
        $sedikit = $beragam;
        $banyak  = [...$beragam];

        for ($i = 0; $i < 24; $i++) {
            $n = $this->buatPegawai(['id_jenis_pegawai' => 1]);
            $this->presensiHadir($n, '2026-03-16');
            $banyak[] = $n;
        }

        $service = $this->serviceTukinFase4();
        // Pemanasan: cache web_config & hari libur terisi, supaya yang dibandingkan hanya kueri data per NIP.
        $service->siapkanBanyak($sedikit, 2026, 4);
        $kSedikit = $this->hitungKueri(static fn () => $service->siapkanBanyak($sedikit, 2026, 4));
        $kBanyak  = $this->hitungKueri(static fn () => $service->siapkanBanyak($banyak, 2026, 4));

        $this->assertCount(count($sedikit), $kSedikit[0]);
        $this->assertCount(count($banyak), $kBanyak[0]);
        $this->assertSame($kSedikit[1], $kBanyak[1], "kueri {$kSedikit[1]} untuk " . count($sedikit) . " NIP vs {$kBanyak[1]} untuk " . count($banyak) . ' NIP');

        // Batch lebih kecil: kueri per batch berulang, tetap tidak per NIP (CR-083: batch 15, bukan 10 — dengan ±10 kueri per
        // batch, batch 10 membuat batas "< jumlah NIP" terlalu sempit untuk satu kueri tambahan T-01).
        [, $kBatch] = $this->hitungKueri(static fn () => $service->siapkanBanyak($banyak, 2026, 4, null, 15));
        $this->assertLessThan(count($banyak), $kBatch);
        $this->assertGreaterThan($kBanyak[1], $kBatch);
    }

    /**
     * CR-083 T-01 [K]: pegawai yang dibuat pada hari terakhir periode BKN (`created_at` tidak < 'akhir') tidak dimuat
     * cuti/TB/konket/presensinya (`L_presensi.php:12209`, `:12283`, `:12346`, `:12383`) — termasuk data tidak sah, yang
     * karenanya tidak menjadi galat. Jalur tunggal dan massal sama; sehari sebelumnya dimuat.
     */
    public function testT01DibuatPadaHariTerakhirPeriodeTanpaDataHarian(): void
    {
        $this->barisBkn(2026, 4, '03-16', '04-15');

        $akhir = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-04-15 23:59:59']);
        $this->presensiHadir($akhir, '2026-03-16');
        $this->cuti($akhir, 1, '2026-03-17', '2026-03-17');

        $rusak = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-04-15 00:00:00']);
        $this->db->table('dh_online')->insert(['nip' => $rusak, 'tgl' => '2026-03-16', 'jam_masuk' => '25:00:00', 'sumber' => 1]);
        $this->cuti($rusak, 7, '2026-03-17', '2026-03-17');

        $sebelum = $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => '2026-04-14 23:59:59']);
        $this->presensiHadir($sebelum, '2026-03-16');

        $massal = $this->serviceTukinFase4()->siapkanBanyak([$akhir, $rusak, $sebelum], 2026, 4);

        foreach ([$akhir, $rusak] as $n) {
            $this->assertInstanceOf(ArgumenTukin::class, $massal[$n], $n);
            $this->assertSame([], $massal[$n]->harian, "{$n}: tanpa data harian");
            $this->assertEquals($this->serviceTukinFase4()->siapkan($n, 2026, 4), $massal[$n], "{$n}: tunggal = massal");
        }

        $this->assertInstanceOf(ArgumenTukin::class, $massal[$sebelum]);
        $this->assertArrayHasKey('2026-03-16', $massal[$sebelum]->harian, 'sehari sebelum akhir: dimuat');
        $this->assertSame([$akhir, $rusak], (new SumberDataPegawaiTukin($this->db))->tanpaDataHarian([$akhir, $rusak, $sebelum], PeriodeTukin::dariBulan(2026, 4)));
    }

    public function testKonfigurasiBelumLengkapSemuaNipGalat(): void
    {
        $a = $this->buatPegawai();
        $b = $this->buatPegawai();
        // Tarif belum diatur sama sekali (nilai bawaan katalog kosong), pola KonfigurasiTukinDariWebConfigTest.
        $this->db->table('web_config')->whereIn('config_name', array_keys(self::TARIF_UJI))->delete();
        $this->resetWebConfig();

        $massal = $this->serviceTukinFase4()->siapkanBanyak([$a, $b], 2026, 4);

        $this->assertInstanceOf(KonfigurasiTukinTidakLengkap::class, $massal[$a]);
        $this->assertInstanceOf(KonfigurasiTukinTidakLengkap::class, $massal[$b]);
        $this->assertSame([], $this->serviceTukinFase4()->siapkanBanyak([], 2026, 4));
    }

    /**
     * Enam NIP: (0) normal lengkap — presensi, konket, cuti sakit, SKP BKN, 4 anak, KP; (1) TB + perpanjangan;
     * (2) PPPK baru; (3) CPNS; (4) jam presensi tidak sah; (5) jenis cuti di luar 1..6.
     *
     * @return list<string>
     */
    private function dataBeragam(): array
    {
        $this->barisBkn(2026, 4, '03-16', '04-15');
        $this->libur('2026-03-18');

        $normal = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->presensiHadir($normal, '2026-03-16');
        $this->konketBebas($normal, '2026-03-17', '2026-03-17', 13);
        $this->cuti($normal, 'sakit', '2026-03-19', '2026-03-20', 2);
        $this->db->table('riwayat_skp_periodik')->insert(['id_pns' => 'PNS-' . $normal, 'nip' => $normal, 'nama' => 'Uji', 'periode_id' => 'BKN-2026-04-utama', 'hasil_akhir' => 'Baik', 'status' => 1]);
        $this->keluarga($normal, 4);
        $this->db->table('riwayat_kp')->insert(['nip' => $normal, 'tmtsk' => '2020-01-01', 'gol_ruang' => 'III/a', 'status' => 1]);

        $tb = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $id = $this->tb($tb, '2026-03-01', '2026-03-20');
        $this->perpanjanganTb($id, '2026-03-25', '2026-04-10');

        $pppk = $this->buatPegawai(['id_jenis_pegawai' => 6]);
        $this->buatRiwayatMutasiJabatan($pppk, ['tmtsk' => '2026-04-01', 'gol_pppk' => 'IX']);

        $cpns = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->db->table('riwayat_kp')->insert(['nip' => $cpns, 'tmtsk' => '2026-03-01', 'gol_ruang' => 'CPNS III/a', 'status' => 1]);

        $jamRusak = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->db->table('dh_online')->insert(['nip' => $jamRusak, 'tgl' => '2026-03-16', 'jam_masuk' => '25:00:00', 'sumber' => 1]);

        $cutiRusak = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->cuti($cutiRusak, 7, '2026-03-17', '2026-03-17');

        return [$normal, $tb, $pppk, $cpns, $jamRusak, $cutiRusak];
    }

    private function hasilAtauGalat(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (GalatDataTukin $e) {
            return [$e::class, $e->getMessage()];
        }
    }

    private function ringkas(mixed $hasil): mixed
    {
        return $hasil instanceof Throwable ? [$hasil::class, $hasil->getMessage()] : $hasil;
    }

    /**
     * @return array{0: mixed, 1: int} hasil `$aksi` dan jumlah kueri SELECT
     */
    private function hitungKueri(callable $aksi): array
    {
        $this->kueri = [];
        Events::on('DBQuery', function (Query $q): void {
            if (stripos(ltrim($q->getQuery()), 'SELECT') === 0) {
                $this->kueri[] = $q->getQuery();
            }
        });

        try {
            $hasil = $aksi();
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        return [$hasil, count($this->kueri)];
    }
}
