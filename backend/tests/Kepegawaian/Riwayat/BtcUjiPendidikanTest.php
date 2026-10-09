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
 * WS-1 M1 (MAKE-004) — B-TC generik atas Definisi uji self-service (UjiPendidikan). Definisi nyata B-10 memakai trait
 * yang sama di M2.
 *
 * @internal
 */
final class BtcUjiPendidikanTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngine();
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->pendidikanUji;
    }

    protected function btcPayload(string $nip): array
    {
        return ['tgl_lulus' => '2012-08-30', 'institusi_pendidikan' => 'Universitas Uji'];
    }

    protected function btcBaris(string $nip): array
    {
        return $this->btcPayload($nip);
    }
}
