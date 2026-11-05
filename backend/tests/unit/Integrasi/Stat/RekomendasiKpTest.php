<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Stat;

use App\Libraries\Integrasi\Stat\KalenderStat;
use App\Libraries\Integrasi\Stat\RekomendasiKp;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-061 AC-08.1 — deteksi KP `get_all_kp` legacy: periode bulan TMT (≤4 → 1 April, 5–10 → 1 Oktober, >10 → 1 April
 * tahun depan), siklus `% 4`, jenis KP 2 (−1 tahun), plafon pendidikan/eselon, percepatan struktural (Pilihan vs Reguler),
 * anomali legacy yang dipertahankan (`gol_ruang` kosong, TMT kosong = 1970), dan kolom turunan MuDig.
 * Hari ini = 2026-11-05.
 *
 * @internal
 */
final class RekomendasiKpTest extends CIUnitTestCase
{
    private const HARI_INI = '2026-11-05';

    private RekomendasiKp $kelas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kelas = new RekomendasiKp(self::HARI_INI, [
            16 => ['id_pangkat' => 16, 'pangkat' => 'Penata Muda', 'gol_ruang' => 'III/a'],
            17 => ['id_pangkat' => 17, 'pangkat' => 'Penata Muda Tk. I', 'gol_ruang' => 'III/b'],
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null, string, string|null}>
     */
    public static function deteksi(): iterable
    {
        $dasar = ['gol_ruang' => 'III/a', 'tmtsk_pangkat' => '2022-04-01', 'id_jenis_kp' => '3', 'strata' => 'S.1', 'eselon' => ''];

        yield 'reguler bulan ≤ 4 → 1 April tahun ini' => [$dasar, null, '2026-04-01', '3'];

        yield 'reguler bulan 5–10 → 1 Oktober' => [['tmtsk_pangkat' => '2022-10-01'] + $dasar, null, '2026-10-01', '3'];

        yield 'reguler bulan > 10 → 1 April tahun depan' => [['tmtsk_pangkat' => '2022-11-15'] + $dasar, null, '2027-04-01', '3'];

        yield 'siklus % 4 tidak pas → tidak ada' => [['tmtsk_pangkat' => '2023-04-01'] + $dasar, null, '', null];

        yield 'TMT tahun ini (jarak 0) → tidak ada' => [['tmtsk_pangkat' => '2026-04-01'] + $dasar, null, '', null];

        yield 'jenis KP 2: tahun TMT − 1' => [['tmtsk_pangkat' => '2023-04-01', 'id_jenis_kp' => '2'] + $dasar, null, '2026-04-01', '3'];

        yield 'plafon pendidikan S.1 = III/d: III/d tidak naik' => [['gol_ruang' => 'III/d'] + $dasar, null, '', null];

        yield 'plafon eselon E.III S.1 = IV/b: III/d naik (reguler)' => [['gol_ruang' => 'III/d', 'eselon' => 'E.III'] + $dasar, null, '2026-04-01', '3'];

        yield 'plafon eselon E.I S.1 = IV/e: IV/d naik' => [['gol_ruang' => 'IV/d', 'eselon' => 'E.I'] + $dasar, null, '2026-04-01', '3'];

        yield 'strata tidak dikenal: plafon 0 → tidak naik' => [['strata' => 'XYZ'] + $dasar, null, '', null];

        yield 'percepatan E.IV III/b lebih awal dari reguler → Pilihan' => [['gol_ruang' => 'III/b', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2022-10-01'] + $dasar, '2025-01-15', '2026-04-01', '4'];

        yield 'percepatan tidak lebih awal → tetap Reguler' => [['gol_ruang' => 'III/b', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2022-01-01'] + $dasar, '2025-11-01', '2026-04-01', '3'];

        yield 'percepatan tanpa reguler: TMT+1 th ≤ 1 April → 1 April' => [['gol_ruang' => 'III/b', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, '2025-03-31', '2026-04-01', '4'];

        yield 'percepatan: TMT+1 th antara April–Oktober → 1 Oktober' => [['gol_ruang' => 'III/b', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, '2025-05-01', '2026-10-01', '4'];

        yield 'percepatan: TMT+1 th setelah Oktober → 1 April tahun berikut' => [['gol_ruang' => 'III/b', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, '2025-11-01', '2027-04-01', '4'];

        yield 'E.IV III/c (= minimal) tidak percepatan' => [['gol_ruang' => 'III/c', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, '2025-01-15', '', null];

        yield 'percepatan tanpa TMT struktural → tidak ada' => [['gol_ruang' => 'III/b', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, null, '', null];

        yield '[K] gol_ruang kosong + strata dikenal → tetap terdeteksi reguler' => [['gol_ruang' => ''] + $dasar, null, '2026-04-01', '3'];

        yield '[K] gol_ruang kosong tidak ikut percepatan' => [['gol_ruang' => '', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, '2025-01-15', '', null];

        yield '[K] gol_ruang tak dikenal (null < n) ikut percepatan' => [['gol_ruang' => 'X', 'eselon' => 'E.IV', 'tmtsk_pangkat' => '2023-01-01'] + $dasar, '2025-01-15', '2026-04-01', '4'];

        yield '[K] TMT pangkat kosong dibaca 1970 → siklus pas, bulan 1 → 1 April' => [['tmtsk_pangkat' => ''] + $dasar, null, '2026-04-01', '3'];
    }

    /**
     * @param array<string, mixed> $p
     */
    #[DataProvider('deteksi')]
    public function testDeteksi(array $p, ?string $tmtStruktural, string $tglKp, ?string $idJenis): void
    {
        $hasil = $this->kelas->deteksi($p, $tmtStruktural);

        $this->assertSame($tglKp, $hasil['tgl_kp']);
        $this->assertSame($idJenis, $hasil['id_jenis_kp']);
        $this->assertSame($idJenis === null ? null : ($idJenis === '3' ? 'Reguler' : 'Pilihan'), $hasil['jenis_kp']);
    }

    public function testTurunanMudigMasaKerjaDariMasaKerjaLama(): void
    {
        $m = ['nip' => '199001012015011001', 'tmt_kp_old' => '2022-04-01', 'mker_th_old' => '10', 'mker_bl_old' => '5', 'order_pangkat_old' => '16'];
        $t = $this->kelas->turunanMudig($m, '2026-04-01');

        $this->assertSame(14, $t['mker_th_new']);   // 125 + 48 bulan = 173 → 14 th 5 bl
        $this->assertSame(5, $t['mker_bl_new']);
        $this->assertSame('14 Tahun 5 Bulan', $t['mker_new']);
        $this->assertSame(17, $t['id_pangkat_new']);
        $this->assertSame('Penata Muda Tk. I', $t['pangkat_new']);
        $this->assertSame('III/b', $t['gol_ruang_new']);
        $this->assertSame('', $t['gaji_pokok_new']);
    }

    public function testTurunanMudigMasaKerjaNolDariDigitNip(): void
    {
        $m = ['nip' => '199001012015011001', 'tmt_kp_old' => '', 'mker_th_old' => '0', 'mker_bl_old' => '', 'order_pangkat_old' => '99'];
        $t = $this->kelas->turunanMudig($m, '2026-04-01');

        $this->assertSame([11, 3, '11 Tahun 3 Bulan'], [$t['mker_th_new'], $t['mker_bl_new'], $t['mker_new']]); // CPNS 2015-01
        $this->assertSame('', $t['id_pangkat_new'], 'order 100 tidak ada di master → kosong');

        // NIP tanpa tanggal sah → hari ini ([V2] penjaga, legacy fatal).
        $t = $this->kelas->turunanMudig(['nip' => 'ABC', 'mker_th_old' => 0, 'mker_bl_old' => 0], '2026-11-05');
        $this->assertSame([0, 0], [$t['mker_th_new'], $t['mker_bl_new']]);
    }

    public function testKalenderStatSemantikLegacy(): void
    {
        $this->assertSame(1970, KalenderStat::tahunLegacy(''));
        $this->assertSame(1, KalenderStat::bulanLegacy(null));
        $this->assertSame(2022, KalenderStat::tahunLegacy('2022-10-01'));
        $this->assertSame(10, KalenderStat::bulanLegacy('2022-10-01'));
        $this->assertSame('2026-11-05', KalenderStat::tanggalAtauHariIni('', '2026-11-05')->format('Y-m-d'));
        $this->assertSame('2024-02-29', KalenderStat::tanggalAtauHariIni('2024-02-29', '2026-11-05')->format('Y-m-d'));
        $this->assertFalse(KalenderStat::valid('2023-02-29'));
        $this->assertSame(20, KalenderStat::selisihBulan(KalenderStat::parse('2026-11-05'), KalenderStat::parse('2025-03-01')));
    }
}
