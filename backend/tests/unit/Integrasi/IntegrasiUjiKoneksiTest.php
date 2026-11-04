<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Bkn\TokenBkn;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Respons;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Integrations;
use Config\Services;
use Tests\Support\Integrasi\KunciRsaUji;
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

    /** @var array<string, string> */
    private array $pushAsli;

    private ?string $berkasSa = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bknAsli  = config(Integrations::class)->bkn;
        $this->pushAsli = config(Integrations::class)->push;
    }

    protected function tearDown(): void
    {
        config(Integrations::class)->bkn  = $this->bknAsli;
        config(Integrations::class)->push = $this->pushAsli;
        Services::reset(true);

        if ($this->berkasSa !== null && is_file($this->berkasSa)) {
            unlink($this->berkasSa);
        }

        parent::tearDown();
    }

    public function testArgumenSalahDanDriverMock(): void
    {
        command('integrasi:uji-koneksi');
        $this->assertStringContainsString('Layanan wajib salah satu dari bkn, siasn, esign, fcm', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('integrasi:uji-koneksi fcm');
        $this->assertStringContainsString('hanya untuk integrations.push.driver = fcm', $this->getStreamFilterBuffer(), 'driver mock bawaan (CR-101)');

        $this->resetStreamFilterBuffer();
        command('integrasi:uji-koneksi esign');
        $this->assertStringContainsString('hanya untuk integrations.esign.driver = bsre', $this->getStreamFilterBuffer(), 'driver mock bawaan');
    }

    public function testKonfigurasiKosongDitolak(): void
    {
        command('integrasi:uji-koneksi siasn');
        $this->assertStringContainsString("Konfigurasi integrasi 'bkn' belum lengkap", $this->getStreamFilterBuffer());
    }

    public function testFcmValidateOnlyTanpaMencetakProjectIdAtauTokenDanGagalIzin(): void
    {
        // CR-101: berkas service account sintetis sementara (kunci dibuat saat test), FakeTransport tanpa jaringan.
        $this->berkasSa = (string) tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($this->berkasSa, (string) json_encode(['client_email' => 'sa-uji@proyek.contoh', 'private_key' => KunciRsaUji::pasangan()['privat'], 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(Integrations::class)->push = ['driver' => 'fcm', 'projectId' => 'proyek-uji-rahasia', 'credentialsPath' => $this->berkasSa];

        $transport = (new FakeTransport())
            ->antreJson(200, ['access_token' => 'akses-rahasia', 'expires_in' => 3599])
            ->antreJson(200, ['name' => 'projects/proyek-uji-rahasia/messages/fake_message_id'])
            ->antreJson(200, ['access_token' => 'akses-rahasia', 'expires_in' => 3599])
            ->antre(new Respons(403, (string) json_encode(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'denied proyek-uji-rahasia']])));
        Services::injectMock('klienHttp', new KlienHttp($transport, new LogIntegrasiTiruan()));
        Services::injectMock('cacheService', new CacheService(new MockCache(), 'uji_'));

        command('integrasi:uji-koneksi fcm');
        $ok = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('Uji koneksi fcm OK', $ok);
        $this->assertStringContainsString('validate_only', $ok);

        $this->resetStreamFilterBuffer();
        command('integrasi:uji-koneksi fcm');
        $gagal = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('GAGAL: messages:send validate_only PERMISSION_DENIED (HTTP 403)', $gagal);

        foreach (['proyek-uji-rahasia', 'akses-rahasia', 'PRIVATE KEY', $this->berkasSa] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $ok . $gagal);
        }

        // Berkas service account tidak terbaca → gagal tanpa path/nilai.
        unlink($this->berkasSa);
        $this->resetStreamFilterBuffer();
        command('integrasi:uji-koneksi fcm');
        $this->assertStringContainsString('Uji koneksi fcm GAGAL: Berkas service account FCM (integrations.push.credentialsPath) tidak terbaca.', $this->getStreamFilterBuffer());
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
