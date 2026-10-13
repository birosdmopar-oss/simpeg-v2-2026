<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Kepegawaian;

/**
 * S0-A (MAKE-002) — StatusRiwayat: nilai ikut legacy 0/1/2/10, status 3 "Diproses" hanya berlaku bila flag aktif
 * (nonaktif default).
 *
 * @internal
 */
final class StatusRiwayatTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Factories::reset('config');

        parent::tearDown();
    }

    public function testNilaiDanLabel(): void
    {
        $this->assertSame(
            [0 => 'Menunggu', 1 => 'Disetujui', 2 => 'Ditolak', 3 => 'Diproses', 10 => 'Dihapus'],
            array_combine(
                array_map(static fn (StatusRiwayat $s): int => $s->value, StatusRiwayat::cases()),
                array_map(static fn (StatusRiwayat $s): string => $s->label(), StatusRiwayat::cases()),
            ),
        );
    }

    public function testFlagStatusDiprosesMatiDefault(): void
    {
        $this->assertFalse((new Kepegawaian())->statusDiprosesAktif);
        $this->assertFalse(config(Kepegawaian::class)->statusDiprosesAktif);

        $this->assertSame(
            [StatusRiwayat::Menunggu, StatusRiwayat::Disetujui, StatusRiwayat::Ditolak, StatusRiwayat::Dihapus],
            StatusRiwayat::berlaku(),
        );
        $this->assertFalse(StatusRiwayat::Diproses->isBerlaku());
        $this->assertNull(StatusRiwayat::dariNilai(3));
    }

    public function testFlagAktifMembuatDiprosesBerlaku(): void
    {
        $this->assertContains(StatusRiwayat::Diproses, StatusRiwayat::berlaku(true));
        $this->assertTrue(StatusRiwayat::Diproses->isBerlaku(true));

        $config                      = new Kepegawaian();
        $config->statusDiprosesAktif = true;
        Factories::injectMock('config', Kepegawaian::class, $config);

        $this->assertCount(5, StatusRiwayat::berlaku());
        $this->assertSame(StatusRiwayat::Diproses, StatusRiwayat::dariNilai('3'));
    }

    public function testDariNilai(): void
    {
        $this->assertSame(StatusRiwayat::Menunggu, StatusRiwayat::dariNilai(0));
        $this->assertSame(StatusRiwayat::Disetujui, StatusRiwayat::dariNilai('1'));
        $this->assertSame(StatusRiwayat::Ditolak, StatusRiwayat::dariNilai(2));
        $this->assertSame(StatusRiwayat::Dihapus, StatusRiwayat::dariNilai('10'));

        foreach ([null, '', 'x', '-1', 4, 9, 11, ' 1'] as $nilai) {
            $this->assertNull(StatusRiwayat::dariNilai($nilai), var_export($nilai, true));
        }
    }
}
