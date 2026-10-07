<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * S0-A (MAKE-002) — value object AturanLampiran (batas 1/2/5 MB ikut legacy).
 *
 * @internal
 */
final class AturanLampiranTest extends CIUnitTestCase
{
    public function testAturanValid(): void
    {
        $aturan = new AturanLampiran(14, true, 2, ['PDF', '.jpg', 'pdf']);

        $this->assertSame(['pdf', 'jpg'], $aturan->ekstensi);
        $this->assertSame(2 * 1024 * 1024, $aturan->batasByte());
        $this->assertTrue($aturan->ekstensiDiizinkan('.PDF'));
        $this->assertFalse($aturan->ekstensiDiizinkan('exe'));
        $this->assertSame(['id_riwayat' => 14, 'wajib' => true, 'batas_mb' => 2, 'ekstensi' => ['pdf', 'jpg']], $aturan->toArray());
    }

    public function testBatasMbHanya125(): void
    {
        foreach ([1, 2, 5] as $mb) {
            $this->assertSame($mb, (new AturanLampiran(1, false, $mb, ['pdf']))->batasMb);
        }

        foreach ([0, 3, 4, 10] as $mb) {
            try {
                new AturanLampiran(1, false, $mb, ['pdf']);
                $this->fail("batas {$mb} MB harus ditolak");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEkstensiWajibDanValid(): void
    {
        foreach ([[], [''], ['p df'], ['../pdf']] as $ekstensi) {
            try {
                new AturanLampiran(1, false, 1, $ekstensi);
                $this->fail('ekstensi ' . json_encode($ekstensi) . ' harus ditolak');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
