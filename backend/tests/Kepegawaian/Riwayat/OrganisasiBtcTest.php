<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Organisasi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-TC generik B-17 Organisasi (WS-1 M4 MAKE-007) lewat Definisi nyata, PegawaiScope + lampiran nyata. Aturan per jenis:
 * OrganisasiSeminarTest.
 *
 * @internal
 */
final class OrganisasiBtcTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Organisasi $d;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Organisasi();
        $this->pasangEngineNyata([$this->d]);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->d;
    }

    protected function btcPayload(string $nip): array
    {
        return ['nama_organisasi' => 'Korpri', 'kedudukan' => 'Anggota', 'tgl_mulai' => '2015-01-01'];
    }

    protected function btcBaris(string $nip): array
    {
        return ['nama_organisasi' => 'Korpri', 'kedudukan' => 'Anggota', 'tgl_mulai' => '2015-01-01'];
    }

    protected function btcBerkas(): array
    {
        return [Organisasi::LAMPIRAN_ORGANISASI => $this->berkasUji('sertifikat.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }
}
