<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * WS-1 M1 (MAKE-004) — B-TC generik atas Definisi uji alur admin multi-target (UjiKp). Definisi nyata B-08 memakai trait
 * yang sama di M2.
 *
 * @internal
 */
final class BtcUjiKpTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngine();

        foreach ([1 => 'CPNS', 2 => 'PNS', 3 => 'Reguler', 6 => 'Lainnya'] as $id => $nama) {
            $this->buatJenisKp($id, $nama);
        }
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->kpUji;
    }

    protected function btcPayload(string $nip): array
    {
        return $this->dataKp(3, '2015-04-01');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->btcPayload($nip);
    }
}
