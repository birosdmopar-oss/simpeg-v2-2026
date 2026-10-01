<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Libraries\Kepegawaian\Kalkulasi\MasaKerja;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-025 — masa kerja golongan kalender murni (legacy `L_employee.php:2673-2698`), TMT CPNS dari NIP, format
 * `formatMker`.
 *
 * @internal
 */
final class MasaKerjaTest extends CIUnitTestCase
{
    public function testTambahMembawaBulanKeTahun(): void
    {
        $this->assertSame(['tahun' => 11, 'bulan' => 6], MasaKerja::tambah(7, 6, '2022-04-01', '2026-04-01'));
        $this->assertSame(['tahun' => 12, 'bulan' => 0], MasaKerja::tambah(7, 6, '2022-04-01', '2026-10-01'));
        $this->assertSame(['tahun' => 7, 'bulan' => 6], MasaKerja::tambah(7, 6, '2026-04-01', '2026-04-01'));
        $this->assertSame(['tahun' => 1, 'bulan' => 2], MasaKerja::tambah(0, 14, '2026-04-01', '2026-04-30'));
    }

    public function testTambahNegatifDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MasaKerja::tambah(-1, 0, '2022-04-01', '2026-04-01');
    }

    public function testSelisih(): void
    {
        $this->assertSame(['tahun' => 11, 'bulan' => 6], MasaKerja::selisih('2015-03-01', '2026-09-30'));
        $this->assertSame(['tahun' => 2, 'bulan' => 0], MasaKerja::selisih('2024-02-29', '2026-02-28'));
        $this->assertSame(['tahun' => 0, 'bulan' => 1], MasaKerja::selisih('2026-01-31', '2026-02-28'));
        $this->assertSame(['tahun' => 0, 'bulan' => 0], MasaKerja::selisih('2026-01-31', '2026-02-27'));

        $this->expectException(InvalidArgumentException::class);
        MasaKerja::selisih('2026-02-01', '2026-01-31');
    }

    public function testTmtCpnsDariNip(): void
    {
        $this->assertSame('2015-03-01', MasaKerja::tmtCpnsDariNip('199001012015031001'));
        $this->assertSame('2000-12-01', MasaKerja::tmtCpnsDariNip('197501012000121001'));

        $tidakValid = [
            '19900101201503100', '1990010120150310011', '19900101201503100A', ' 199001012015031001',
            '199001012015131001', '199001012015001001', '', '199001011899121001',
        ];

        foreach ($tidakValid as $nip) {
            $this->assertNull(MasaKerja::tmtCpnsDariNip($nip), var_export($nip, true));
        }
    }

    public function testFormat(): void
    {
        $this->assertSame('02 Tahun 05 Bulan', MasaKerja::format(2, 5));
        $this->assertSame('12 Tahun 00 Bulan', MasaKerja::format(12, 0));

        $this->expectException(InvalidArgumentException::class);
        MasaKerja::format(0, -1);
    }
}
