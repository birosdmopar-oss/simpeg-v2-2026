<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Bkn\TokenBkn;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Integrations;
use Config\Services;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-063 — `integrasi:uji-koneksi` (manual, bukan check.sh): validasi argumen, konfigurasi kosong ditolak, bkn memakai
 * token e-Kinerja + GET referensi/periode (FakeTransport, tanpa jaringan), keluaran tanpa token.
 *
 * @internal
 */
final class IntegrasiUjiKoneksiTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    /** @var array<string, bool|float|string> */
    private array $bknAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bknAsli = config(Integrations::class)->bkn;
    }

    protected function tearDown(): void
    {
        config(Integrations::class)->bkn = $this->bknAsli;
        Services::reset(true);
        parent::tearDown();
    }

    public function testArgumenSalahDanLayananBelumTersedia(): void
    {
        command('integrasi:uji-koneksi');
        $this->assertStringContainsString('Layanan wajib salah satu dari bkn, siasn, esign, fcm', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('integrasi:uji-koneksi esign');
        $this->assertStringContainsString('belum tersedia', $this->getStreamFilterBuffer());
    }

    public function testKonfigurasiKosongDitolak(): void
    {
        command('integrasi:uji-koneksi siasn');
        $this->assertStringContainsString("Konfigurasi integrasi 'bkn' belum lengkap", $this->getStreamFilterBuffer());
    }

    public function testBknOkTanpaMencetakToken(): void
    {
        $konfig                          = $this->bknAsli;
        $konfig['baseUrlEkinerja']       = 'https://kinerja.contoh/api_kinerja';
        $konfig['ekinerjaToken']         = 'tok-statis-rahasia';
        config(Integrations::class)->bkn = $konfig;

        $transport = (new FakeTransport())->antreJson(200, ['success' => 1, 'data' => [['id' => 'P1'], ['id' => 'P2']]]);
        Services::injectMock('tokenBkn', new TokenBkn(new KlienHttp($transport, new LogIntegrasiTiruan()), new CacheService(new MockCache(), 'uji_'), $konfig));

        command('integrasi:uji-koneksi bkn');
        $keluaran = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Uji koneksi bkn OK', $keluaran);
        $this->assertStringContainsString('2 baris', $keluaran);
        $this->assertStringNotContainsString('tok-statis-rahasia', $keluaran);
        $this->assertSame('https://kinerja.contoh/api_kinerja/referensi/periode', $transport->tercatat()[0]['permintaan']->url);
    }
}
