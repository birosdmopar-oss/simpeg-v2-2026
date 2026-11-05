<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Stat;

use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Integrasi\Stat\RekomendasiKgb;
use App\Libraries\Integrasi\Stat\RekomendasiPensiun;
use App\Libraries\Kepegawaian\Kalkulasi\Pensiun;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-061 AC-08.1 — KGB tiga cabang (<24, 24–36, >36 bulan) `get_all_kgb` legacy; pensiun BUP (`umur_pensiun` → 60
 * E.I/E.II → 58) dan tanggal `getTanggalPensiun` legacy (luapan bulan dipertahankan); penyaji format legacy (`d M Y`,
 * `M Y`, `F`, eselon, URL berkas, nama unduhan). Hari ini = 2026-11-05.
 *
 * @internal
 */
final class RekomendasiKgbPensiunTest extends CIUnitTestCase
{
    private const HARI_INI = '2026-11-05';

    /**
     * @return iterable<string, array{array<string, mixed>, string, int|string, int|string}>
     */
    public static function kgb(): iterable
    {
        yield '< 24 bulan → acuan + 2 tahun' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2025-03-01'], '2027-03-01', 1, 8];

        yield '24–36 bulan → hari ini' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2024-06-01'], '2026-11-05', 2, 5];

        yield 'tepat 24 → hari ini' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2024-11-05'], '2026-11-05', 2, 0];

        yield 'tepat 36 → hari ini' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2023-11-05'], '2026-11-05', 3, 0];

        yield '37 bulan → +4 tahun (≥ hari ini)' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2023-10-05'], '2027-10-05', 3, 1];

        yield '> 36 lama → +4 lalu +2 sampai ≥ hari ini' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2020-01-01'], '2028-01-01', 6, 10];

        yield 'PNS: TMT CPNS lebih baru menang' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2020-01-01', 'tmtsk_cpns' => '2025-01-01'], '2027-01-01', 1, 10];

        yield 'PNS tanpa KGB: acuan CPNS' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '', 'tmtsk_cpns' => '2025-01-01'], '2027-01-01', 1, 10];

        yield 'PPPK: acuan TMT jabatan, CPNS diabaikan' => [['id_jenis_pegawai' => '6', 'tmtsk_kgb' => '', 'tmtsk_cpns' => '2010-01-01', 'tmtsk_pmj' => '2025-06-01'], '2027-06-01', 1, 5];

        yield 'tanpa acuan → kosong' => [['id_jenis_pegawai' => '1', 'tmtsk_kgb' => null, 'tmtsk_cpns' => null], '', '', ''];
    }

    /**
     * @param array<string, mixed> $r
     */
    #[DataProvider('kgb')]
    public function testKgbTigaCabang(array $r, string $tmtskKgb, int|string $th, int|string $bl): void
    {
        $h = (new RekomendasiKgb(self::HARI_INI))->hitung($r + ['no_sk_last_kgb' => 'SK-1', 'gaji_last_kgb' => '3000000']);

        $this->assertSame($tmtskKgb, $h['tmtsk_kgb']);
        $this->assertSame([$th, $bl], [$h['kgb_th'], $h['kgb_bl']]);
    }

    public function testKgbKolomTerakhirHanyaBilaAdaBarisKgb(): void
    {
        $k = new RekomendasiKgb(self::HARI_INI);

        $ada = $k->hitung(['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '2025-03-01', 'no_sk_last_kgb' => 'SK-9', 'gaji_last_kgb' => '4000000']);
        $this->assertSame(['SK-9', '2025-03-01', '4000000'], [$ada['no_sk_last_kgb'], $ada['tmtsk_last_kgb'], $ada['gaji_last_kgb']]);

        $tanpa = $k->hitung(['id_jenis_pegawai' => '1', 'tmtsk_kgb' => '', 'tmtsk_cpns' => '2025-01-01', 'no_sk_last_kgb' => 'SK-9']);
        $this->assertSame(['', '', ''], [$tanpa['no_sk_last_kgb'], $tanpa['tmtsk_last_kgb'], $tanpa['gaji_last_kgb']]);
    }

    public function testPensiunBatasUsiaSamaDenganKalkulasiV2(): void
    {
        $this->assertSame(65, RekomendasiPensiun::batasUsia('65', 1, 2));
        $this->assertSame(60, RekomendasiPensiun::batasUsia('0', '1', '2'));
        $this->assertSame(60, RekomendasiPensiun::batasUsia(null, 1, 1));
        $this->assertSame(58, RekomendasiPensiun::batasUsia(null, 1, 3));
        $this->assertSame(58, RekomendasiPensiun::batasUsia('', '', ''));
        $this->assertSame(Pensiun::batasUsia(null, 1, 2), RekomendasiPensiun::batasUsia(null, 1, 2));
    }

    public function testPensiunTanggalLegacyDenganLuapanBulan(): void
    {
        $this->assertSame('2026-04-01', RekomendasiPensiun::tanggalPensiun('1968-03-15', 58));
        $this->assertSame('2028-04-01', RekomendasiPensiun::tanggalPensiun('1968-03-15', 60));
        // Luapan bulan legacy [K]: 31-01-1968 + 58 th + 1 bln = 03-03-2026 → 2026-03-01 (v2 Kalkulasi\Pensiun: 2026-02-01).
        $this->assertSame('2026-03-01', RekomendasiPensiun::tanggalPensiun('1968-01-31', 58));
        $this->assertSame('2026-02-01', Pensiun::tanggalPensiun('1968-01-31', 58));
        $this->assertSame('', RekomendasiPensiun::tanggalPensiun('', 58));
        $this->assertSame('', RekomendasiPensiun::tanggalPensiun(null, 58));

        $k = new RekomendasiPensiun(self::HARI_INI);
        $this->assertSame(['tgl_pensiun' => '2026-04-01', 'pensiun_th' => '2026', 'pensiun_bl' => '4'], $k->hitung('1968-03-15', null, 1, 3));
        $this->assertSame(['tgl_pensiun' => '', 'pensiun_th' => '', 'pensiun_bl' => ''], $k->hitung(null, null, null, null));
        $this->assertSame([58, 7], $k->usia('1968-03-15'));
        $this->assertSame(['', ''], $k->usia(''));
    }

    public function testPenyajiFormatLegacy(): void
    {
        $this->assertSame('01 Apr 2026', PenyajiStat::tanggalPengguna('2026-04-01'));
        $this->assertSame('', PenyajiStat::tanggalPengguna(''));
        $this->assertSame('', PenyajiStat::tanggalPengguna(null));
        $this->assertSame('Apr 2026', PenyajiStat::periodeKp('2026-04-01'));
        $this->assertSame('April', PenyajiStat::bulanKp('2026-04-01'));
        $this->assertSame('October', PenyajiStat::bulanKp('2026-10-01'));
        $this->assertSame('E.II', PenyajiStat::eselon(2));
        $this->assertSame('E.IV', PenyajiStat::eselon('4'));
        $this->assertSame('', PenyajiStat::eselon(5));
        $this->assertSame('', PenyajiStat::eselon(null));
        $this->assertSame('Dr. Budi, M.Si.', PenyajiStat::namaGelar('Dr.', 'Budi', 'M.Si.'));
        $this->assertSame('Budi', PenyajiStat::namaGelar(null, 'Budi', null));
    }

    public function testPenyajiUrlBerkasDanNamaUnduhan(): void
    {
        $p = new PenyajiStat('http://localhost:8080/');

        $this->assertSame('http://localhost:8080/services/s_stat/berkas/kp/199001012015011001/12', $p->urlLampiran('kp', '199001012015011001', 12));
        $this->assertSame('http://localhost:8080/services/s_stat/berkas/tb/199001012015011001/7/file_sp', $p->urlBerkas('tb', '199001012015011001', 7, 'file_sp'));
        $this->assertSame('s_stat_kp_12.pdf', PenyajiStat::namaUnduhan('kp', 12, 'arsip/kp/x/y.PDF'));
        $this->assertSame('s_stat_tb_7.bin', PenyajiStat::namaUnduhan('tb', 7, 'arsip/tanpa-ekstensi'));
        $this->assertSame(['kp' => 11, 'skp' => 32, 'jabatan' => 9, 'kgb' => 10, 'lampiran_tb' => 46, 'surat_tb' => 47, 'lampiran_ib' => 49, 'surat_ib' => 50], PenyajiStat::JENIS_LAMPIRAN);
    }
}
