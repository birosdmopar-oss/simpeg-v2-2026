<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Seminar;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-TC generik B-11 Kursus/Seminar (WS-1 M4 MAKE-007) lewat Definisi nyata, PegawaiScope + lampiran nyata. Aturan per jenis:
 * OrganisasiSeminarTest.
 *
 * @internal
 */
final class SeminarBtcTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Seminar $d;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Seminar();
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
        return ['jenis_seminar' => 1, 'bidang_seminar' => 'Pariwisata', 'nama_seminar' => 'Seminar A', 'no_sertifikat' => 'S-1', 'tgl_sertifikat' => '2024-01-01', 'instansi_penyelenggara' => 'Kemenparekraf'];
    }

    protected function btcBaris(string $nip): array
    {
        return ['jenis_seminar' => 1, 'bidang_seminar' => 'Pariwisata', 'nama_seminar' => 'Seminar A', 'no_sertifikat' => 'S-1', 'tgl_sertifikat' => '2024-01-01', 'instansi_penyelenggara' => 'Kemenparekraf'];
    }

    protected function btcBerkas(): array
    {
        return [Seminar::LAMPIRAN_SERTIFIKAT => $this->berkasUji('sertifikat.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }
}
