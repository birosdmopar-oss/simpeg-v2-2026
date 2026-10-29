<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Rekap;

use App\Libraries\Presensi\Rekap\PenyesuaianTukin;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PRD 5B §7.3.10 AC-D08.11 (bagian murni, MAKE-041): baris aktif yang mencakup bulan laporan (status 2/10 diabaikan),
 * susunan keterangan (penyesuaian, TB, CLTN, daftar cuti) dan akhiran total PDF; `total_potongan` tidak disentuh di sini.
 *
 * @internal
 */
final class PenyesuaianTukinTest extends CIUnitTestCase
{
    private const BARIS = [
        ['id_penyesuaian_tukin' => 1, 'bulan_mulai' => '2026-04-01', 'bulan_akhir' => '2026-06-01', 'persen' => '-20.00', 'alasan' => 'Predikat Kinerja Sangat Kurang', 'status' => 1],
        ['id_penyesuaian_tukin' => 2, 'bulan_mulai' => '2026-07-01', 'bulan_akhir' => '2026-07-01', 'persen' => '-10.50', 'alasan' => 'Predikat Kinerja Kurang', 'status' => 2],
        ['id_penyesuaian_tukin' => 3, 'bulan_mulai' => '2026-08-01', 'bulan_akhir' => '2026-09-01', 'persen' => '5.00', 'alasan' => 'Koreksi', 'status' => 10],
    ];

    public function testAktifHanyaStatus1YangMencakupBulan(): void
    {
        $this->assertSame(['persen' => '-20', 'alasan' => 'Predikat Kinerja Sangat Kurang'], PenyesuaianTukin::aktif(self::BARIS, '2026-04'));
        $this->assertSame(['persen' => '-20', 'alasan' => 'Predikat Kinerja Sangat Kurang'], PenyesuaianTukin::aktif(self::BARIS, '2026-06-01'));
        $this->assertNull(PenyesuaianTukin::aktif(self::BARIS, '2026-03'));
        $this->assertNull(PenyesuaianTukin::aktif(self::BARIS, '2026-07'), 'status 2 diabaikan');
        $this->assertNull(PenyesuaianTukin::aktif(self::BARIS, '2026-08'), 'status 10 diabaikan');
    }

    public function testKeterangan(): void
    {
        $p = ['persen' => '-20', 'alasan' => 'Predikat Kinerja Sangat Kurang'];

        $this->assertSame('', PenyesuaianTukin::keterangan(null, false, null, []));
        $this->assertSame('Penyesuaian Tukin Predikat Kinerja Sangat Kurang -20% (sebelum potongan lain).', PenyesuaianTukin::keterangan($p, false, null, []));
        $this->assertSame('Penyesuaian Tukin Predikat Kinerja Sangat Kurang -20% (sebelum potongan lain). Cuti di Luar Tanggungan Negara', PenyesuaianTukin::keterangan($p, true, null, []));
        $this->assertSame('(Tugas Belajar) Universitas Uji', PenyesuaianTukin::keterangan(null, true, 'Universitas Uji', []), 'TB menimpa CLTN [K]');
        $this->assertSame(
            "Cuti di Luar Tanggungan Negara\nCuti Tahunan (02 September 2026 s.d 04 September 2026)\nCuti Sakit (10 September 2026)",
            PenyesuaianTukin::keterangan(null, true, null, [['Cuti Tahunan', '2026-09-02', '2026-09-04'], ['Cuti Sakit', '2026-09-10', '2026-09-10']]),
        );
        $this->assertSame('Cuti Tahunan (02 September 2026)', PenyesuaianTukin::keterangan(null, false, null, [['Cuti Tahunan', '2026-09-02', '2026-09-02']]), 'tanpa baris kosong di awal');
    }

    public function testAkhiranTotalDanPersen(): void
    {
        $this->assertSame('', PenyesuaianTukin::akhiranTotal(null));
        $this->assertSame(' + 20%', PenyesuaianTukin::akhiranTotal(['persen' => '-20', 'alasan' => 'x']));
        $this->assertSame(' - 5.5%', PenyesuaianTukin::akhiranTotal(['persen' => '5.5', 'alasan' => 'x']));
        $this->assertSame(['-20', '-10.5', '100', '-0.01', '7'], array_map([PenyesuaianTukin::class, 'persen'], ['-20.00', '-10.50', '100.00', '-0.01', 7]));
    }
}
