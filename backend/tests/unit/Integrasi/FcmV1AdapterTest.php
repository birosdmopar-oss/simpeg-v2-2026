<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use App\Libraries\Push\FcmV1Adapter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Tests\Support\Integrasi\KunciRsaUji;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-066 (H-04, F6-11) — adapter FCM HTTP v1 dengan FakeTransport (tanpa jaringan): JWT RS256 service account → token
 * akses (cache), `messages:send`, multicast + `invalid_tokens` dari UNREGISTERED/INVALID_ARGUMENT token (AC-04.2), POST tanpa
 * retry, 401 membuang token akses, fail-fast konfigurasi (AC-K.4), tanpa token/kunci di log (AC-K.1). Kunci RSA dibuat saat
 * test berjalan (tidak ada kunci di repo).
 *
 * @internal
 */
final class FcmV1AdapterTest extends CIUnitTestCase
{
    private const TOKEN_URI = 'https://oauth.contoh/token';

    private static string $kunciPrivat = '';

    private static string $kunciPublik = '';

    private FakeTransport $transport;

    private LogIntegrasiTiruan $log;

    private MockCache $cache;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        ['privat' => self::$kunciPrivat, 'publik' => self::$kunciPublik] = KunciRsaUji::pasangan();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->log       = new LogIntegrasiTiruan();
        $this->cache     = new MockCache();
    }

    public function testKonfigurasiKosongDanBerkasRusakGagalSaatDibuat(): void
    {
        try {
            new FcmV1Adapter($this->klien(), new CacheService($this->cache, 'u_'), ['projectId' => '', 'credentialsPath' => '']);
            $this->fail('harus gagal');
        } catch (KonfigurasiIntegrasiTidakLengkap $e) {
            $this->assertSame(['projectId', 'credentialsPath'], $e->kunci);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kolom private_key kosong');
        new FcmV1Adapter($this->klien(), new CacheService($this->cache, 'u_'), ['projectId' => 'proyek-uji', 'credentialsPath' => '/x.json'], static fn (): string => (string) json_encode(['client_email' => 'sa@contoh', 'token_uri' => self::TOKEN_URI]));
    }

    public function testTokenAksesJwtServiceAccountDanCache(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        $fcm = $this->fcm();

        $this->assertSame('akses-uji', $fcm->tokenAkses());
        $this->assertSame('akses-uji', $fcm->tokenAkses(), 'cache hit');
        $this->assertCount(1, $this->transport->tercatat());

        $p = $this->transport->tercatat()[0]['permintaan'];
        $this->assertSame(['POST', self::TOKEN_URI, 'urn:ietf:params:oauth:grant-type:jwt-bearer'], [$p->metode, $p->url, $p->form['grant_type'] ?? null]);
        JWT::$timestamp = 1_800_000_000;

        try {
            $klaim = (array) JWT::decode((string) $p->form['assertion'], new Key(self::$kunciPublik, 'RS256'));
        } finally {
            JWT::$timestamp = null;
        }

        $this->assertSame(['sa-uji@proyek-uji.contoh', FcmV1Adapter::SCOPE, self::TOKEN_URI, 1_800_000_000, 1_800_003_600], [$klaim['iss'], $klaim['scope'], $klaim['aud'], $klaim['iat'], $klaim['exp']]);
    }

    public function testSendSukses(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])->antreJson(200, ['name' => 'projects/proyek-uji/messages/42']);

        $r = $this->fcm()->send('tok-perangkat-1', 'Cuti Online', 'Pengajuan cuti baru', ['modul' => 'cuti', 'id_rwy' => '7']);

        $this->assertSame(['status_code' => '200', 'message' => 'Terkirim.', 'message_id' => 'projects/proyek-uji/messages/42'], $r);
        $p = $this->transport->tercatat()[1]['permintaan'];
        $this->assertSame('https://fcm.googleapis.com/v1/projects/proyek-uji/messages:send', $p->url);
        $this->assertSame('Bearer akses-uji', $p->header['Authorization']);
        $this->assertSame(['message' => ['token' => 'tok-perangkat-1', 'notification' => ['title' => 'Cuti Online', 'body' => 'Pengajuan cuti baru'], 'data' => ['modul' => 'cuti', 'id_rwy' => '7']]], $p->json);
    }

    public function testMulticastTokenTidakSahDanTanpaRetry(): void
    {
        $this->transport
            ->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['name' => 'm/1'])
            ->antreJson(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]])
            ->antreJson(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']])
            ->antreJson(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument: data too big']])
            ->antreJson(503, ['error' => ['status' => 'UNAVAILABLE']])
            ->antre(new GalatTransport('Operation timed out', true));

        $r = $this->fcm()->sendMulticast(['tok-a', 'tok-b', 'tok-c', 'tok-d', 'tok-e', 'tok-f'], 'Judul', 'Isi');

        $this->assertSame(['200', 1, 5, ['tok-b', 'tok-c']], [$r['status_code'], $r['success_count'], $r['failure_count'], $r['invalid_tokens']], 'INVALID_ARGUMENT payload (tok-d) tidak menghapus token');
        $this->assertCount(7, $this->transport->tercatat(), 'POST 503/timeout tidak di-retry (BP-3)');
    }

    public function testPesanPerPlatform(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['name' => 'm/w'])->antreJson(200, ['name' => 'm/a']);
        $fcm = $this->fcm();

        $fcm->sendMulticastPlatform(FcmV1Adapter::WEB, ['tok-web'], 'Riwayat KGB Disetujui', 'Data: x', ['link' => '/pegawai/1/riwayat?tab=kgb']);
        $fcm->sendMulticastPlatform(FcmV1Adapter::ANDROID, ['tok-andr'], 'Cuti', 'Isi', ['modul' => 'cuti']);

        $web = $this->transport->tercatat()[1]['permintaan']->json['message'];
        $this->assertSame(['icon' => FcmV1Adapter::IKON_BAWAAN], $web['webpush']['notification']);
        $this->assertSame('/pegawai/1/riwayat?tab=kgb', $web['data']['link']);
        $this->assertArrayNotHasKey('fcm_options', $web['webpush']);
        $this->assertSame(['priority' => 'HIGH', 'notification' => ['sound' => 'default', 'click_action' => 'Cuti']], $this->transport->tercatat()[2]['permintaan']->json['message']['android']);

        $this->expectException(RuntimeException::class);
        $fcm->sendMulticastPlatform(9, ['t'], 'J', 'I');
    }

    public function testSemuaGagalDan401MembuangTokenAkses(): void
    {
        $this->transport
            ->antreJson(200, ['access_token' => 'akses-lama', 'expires_in' => 3599])
            ->antreJson(401, ['error' => ['status' => 'UNAUTHENTICATED']])
            ->antreJson(200, ['access_token' => 'akses-baru', 'expires_in' => 3599])
            ->antreJson(200, ['name' => 'm/2']);
        $fcm = $this->fcm();

        $this->assertSame(['502', 0, 1, []], array_values(array_intersect_key($fcm->sendMulticast(['tok-a'], 'J', 'I'), array_flip(['status_code', 'success_count', 'failure_count', 'invalid_tokens']))));
        $this->assertSame('200', $fcm->send('tok-a', 'J', 'I')['status_code']);
        $this->assertSame('Bearer akses-baru', $this->transport->tercatat()[3]['permintaan']->header['Authorization']);
    }

    public function testLogTanpaTokenDanKunci(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'akses-rahasia', 'expires_in' => 3599])
            ->antreJson(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'token tok-perangkat-rahasia tidak sah; bearer akses-rahasia']]);

        $this->fcm()->send('tok-perangkat-rahasia', 'J', 'I');

        $teks = $this->log->semuaTeks();
        $this->assertStringNotContainsString('tok-perangkat-rahasia', $teks);
        $this->assertStringNotContainsString('akses-rahasia', $teks);
        $this->assertStringNotContainsString('PRIVATE KEY', $teks);
    }

    public function testDriverFcmDiServicesGagalSaatDibuatBilaKonfigurasiKosong(): void
    {
        $push                                     = config(\Config\Integrations::class)->push;
        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];

        try {
            $this->expectException(KonfigurasiIntegrasiTidakLengkap::class);
            \Config\Services::pushNotifGateway(false);
        } finally {
            config(\Config\Integrations::class)->push = $push;
        }
    }

    private function fcm(): FcmV1Adapter
    {
        return new FcmV1Adapter(
            $this->klien(),
            new CacheService($this->cache, 'u_'),
            ['projectId' => 'proyek-uji', 'credentialsPath' => '/di/luar/repo/sa.json'],
            static fn (): string => (string) json_encode(['client_email' => 'sa-uji@proyek-uji.contoh', 'private_key' => self::$kunciPrivat, 'token_uri' => self::TOKEN_URI]),
            static fn (): int => 1_800_000_000,
        );
    }

    private function klien(): KlienHttp
    {
        return new KlienHttp($this->transport, $this->log, tidur: static function (int $ms): void {
        });
    }
}
