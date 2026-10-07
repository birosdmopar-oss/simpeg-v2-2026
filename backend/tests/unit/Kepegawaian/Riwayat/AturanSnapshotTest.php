<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * S0-A (MAKE-002) — value object AturanSnapshot (aturan pengisi snapshot `pegawai_*` sebagai data).
 *
 * @internal
 */
final class AturanSnapshotTest extends CIUnitTestCase
{
    public function testAturanSnapshotMenolakBentukSalah(): void
    {
        $ok = new AturanSnapshot('pegawai_kp', ['id_riwayat_kp'], ['id_jenis_kp !=' => 6], ['tmtsk' => 'DESC']);
        $this->assertSame('nip', $ok->kunci);

        $salah = [
            static fn () => new AturanSnapshot('riwayat_kp', ['id_riwayat_kp']),
            static fn () => new AturanSnapshot('pegawai_kp', []),
            static fn () => new AturanSnapshot('pegawai_kp', ['id_riwayat_kp'], [], ['tmtsk' => 'desc']),
        ];

        foreach ($salah as $i => $buat) {
            try {
                $buat();
                $this->fail("aturan #{$i} harus ditolak");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
