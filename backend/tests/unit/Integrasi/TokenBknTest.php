<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Bkn\GalatTokenBkn;
use App\Libraries\Bkn\TokenBkn;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use Tests\Support\Integrasi\LogIntegrasiTiruan;

/**
 * MAKE-063 — penyedia token BKN (PRD Fase 6 §7.0.2, bentuk ikut legacy CR-092/Q6-17): gateway client_credentials + Basic,
 * SSO password grant (+ totp opsional), cache sampai expires_in − 60, header ganda SIASN, token statis e-Kinerja,
 * 401 → refresh sekali, tanpa token/kredensial di log (AC-K.1), konfigurasi kosong ditolak (AC-K.4). Kredensial fiktif.
 *
 * @internal
 */
final class TokenBknTest extends CIUnitTestCase
{
    private const KONFIG = [
        'baseUrlApi'          => 'https://api.contoh/apisiasn/1.0',
        'baseUrlEkinerja'     => 'https://kinerja.contoh/api_kinerja',
        'tokenUrlGateway'     => 'https://gw.contoh/oauth2/token',
        'tokenUrlSso'         => 'https://sso.contoh/realms/uji/token',
        'clientId'            => 'gw-pengguna-uji',
        'clientSecret'        => 'gw-rahasia-uji',
        'ssoClientId'         => 'sso-klien-uji',
        'ssoUsername'         => 'sso-pengguna-uji',
        'ssoPassword'         => 'sso-sandi-uji',
        'ssoTotp'             => '',
        'ekinerjaToken'       => '',
        'ekinerjaApiUser'     => 'ekin-api-uji',
        'ekinerjaApiPass'     => 'ekin-api-sandi',
        'ekinerjaSsoClientId' => 'ekin-sso-klien',
        'ekinerjaSsoUsername' => 'ekin-sso-pengguna',
        'ekinerjaSsoPassword' => 'ekin-sso-sandi',
        'ekinerjaSsoTotp'     => '123456',
        'tlsVerify'           => true,
        'timeout'             => 30.0,
    ];

    private FakeTransport $transport;

    private LogIntegrasiTiruan $log;

    /** @var MockCache&object{ttl: array<string, int>} */
    private MockCache $cacheMentah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport   = new FakeTransport();
        $this->log         = new LogIntegrasiTiruan();
        $this->cacheMentah = new class () extends MockCache {
            /** @var array<string, int> */
            public array $ttl = [];

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->ttl[$key] = $ttl;

                return parent::save($key, $value, $ttl);
            }
        };
    }

    public function testTokenGatewayBasicClientCredentialsDanCacheSampaiExpiresInKurang60(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'tok-gw-1', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        $bkn = $this->bkn();

        $this->assertSame('tok-gw-1', $bkn->tokenGateway());
        $this->assertSame('tok-gw-1', $bkn->tokenGateway(), 'cache hit tanpa permintaan baru');
        $this->assertCount(1, $this->transport->tercatat());

        $p = $this->transport->tercatat()[0]['permintaan'];
        $this->assertSame(['POST', 'https://gw.contoh/oauth2/token', ['grant_type' => 'client_credentials']], [$p->metode, $p->url, $p->form]);
        $this->assertSame('Basic ' . base64_encode('gw-pengguna-uji:gw-rahasia-uji'), $p->header['Authorization']);
        $this->assertSame(['bkn_token_gateway' => 3540], $this->cacheMentah->ttl, 'TTL = expires_in − 60');
    }

    public function testSsoPasswordGrantTanpaTotpBilaKosongDanHeaderGandaSiasn(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'tok-gw', 'expires_in' => 3600])->antreJson(200, ['access_token' => 'tok-sso', 'expires_in' => 1800, 'refresh_token' => 'r']);

        $header = $this->bkn()->headerSiasn();

        $this->assertSame(['Authorization' => 'Bearer tok-gw', 'Auth' => 'bearer tok-sso', 'accept' => 'application/json'], $header);
        $sso = $this->transport->tercatat()[1]['permintaan'];
        $this->assertSame(['grant_type' => 'password', 'client_id' => 'sso-klien-uji', 'username' => 'sso-pengguna-uji', 'password' => 'sso-sandi-uji'], $sso->form);
        $this->assertSame('Basic ' . base64_encode('gw-pengguna-uji:gw-rahasia-uji'), $sso->header['Authorization'], 'SSO memakai Basic kredensial gateway');
    }

    public function testEkinerjaTokenStatisTanpaPermintaanToken(): void
    {
        $bkn = $this->bkn(['ekinerjaToken' => 'tok-statis-ekin']);

        $this->assertSame(['Authorization' => 'Bearer tok-statis-ekin', 'accept' => 'application/json'], $bkn->headerEkinerja());
        $this->assertSame([], $this->transport->tercatat());
    }

    public function testEkinerjaSsoDenganTotpBilaTokenStatisKosong(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'tok-sso-ekin', 'expires_in' => 600]);

        $this->assertSame('tok-sso-ekin', $this->bkn()->tokenEkinerja());

        $p = $this->transport->tercatat()[0]['permintaan'];
        $this->assertSame('https://sso.contoh/realms/uji/token', $p->url);
        $this->assertSame(['grant_type' => 'password', 'client_id' => 'ekin-sso-klien', 'username' => 'ekin-sso-pengguna', 'password' => 'ekin-sso-sandi', 'totp' => '123456'], $p->form);
        $this->assertSame('Basic ' . base64_encode('ekin-api-uji:ekin-api-sandi'), $p->header['Authorization']);
    }

    public function testSiasn401MembuangTokenDanMengulangSekali(): void
    {
        $this->transport
            ->antreJson(200, ['access_token' => 'gw-lama', 'expires_in' => 3600])
            ->antreJson(200, ['access_token' => 'sso-lama', 'expires_in' => 3600])
            ->antreJson(401, ['message' => 'expired'])
            ->antreJson(200, ['access_token' => 'gw-baru', 'expires_in' => 3600])
            ->antreJson(200, ['access_token' => 'sso-baru', 'expires_in' => 3600])
            ->antreJson(401, ['message' => 'masih ditolak']);

        $r = $this->bkn()->kirimSiasn(static fn (array $h): Permintaan => new Permintaan('GET', 'https://api.contoh/apisiasn/1.0/pns/data-utama/199001012020011001', 'siasn', 'data_utama', header: $h));

        $this->assertSame(401, $r->status, 'hanya satu kali ulang');
        $tercatat = $this->transport->tercatat();
        $this->assertCount(6, $tercatat);
        $this->assertSame('Bearer gw-baru', $tercatat[5]['permintaan']->header['Authorization']);
        $this->assertSame('bearer sso-baru', $tercatat[5]['permintaan']->header['Auth']);
        $this->assertContains('gw-baru', $tercatat[5]['permintaan']->rahasia, 'token disensor dari log respons');
    }

    public function testEkinerjaSso401MembuangTokenDanMengulangSekali(): void
    {
        $this->transport
            ->antreJson(200, ['access_token' => 'ekin-lama', 'expires_in' => 3600])
            ->antreJson(401, ['message' => 'expired'])
            ->antreJson(200, ['access_token' => 'ekin-baru', 'expires_in' => 3600])
            ->antreJson(200, ['data' => []]);
        $bkn = $this->bkn();

        $r = $bkn->kirimEkinerja(static fn (array $h): Permintaan => new Permintaan('GET', 'https://kinerja.contoh/api_kinerja/referensi/periode', 'ekinerja', 'periode', header: $h));

        $this->assertSame(200, $r->status);
        $tercatat = $this->transport->tercatat();
        $this->assertCount(4, $tercatat);
        $this->assertSame('Bearer ekin-baru', $tercatat[3]['permintaan']->header['Authorization']);
        $this->assertSame('ekin-baru', $bkn->tokenEkinerja(), 'token baru tersimpan di cache');
        $this->assertCount(4, $this->transport->tercatat());
    }

    public function testTlsVerifyDanTimeoutDariKonfigurasiSampaiKeOpsiTransport(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'tok-gw', 'expires_in' => 3600]);

        $this->bkn(['tlsVerify' => 'true', 'timeout' => '7.5'])->tokenGateway();

        $opsi = $this->transport->tercatat()[0]['opsi'];
        $this->assertSame([5.0, 7.5, true], [$opsi->connectTimeout, $opsi->timeout, $opsi->verifyTls]);
    }

    public function testTlsMatiAtauTimeoutKosongDitolakSaatPastikan(): void
    {
        foreach ([['tlsVerify' => ''], ['tlsVerify' => '0'], ['tlsVerify' => false], ['timeout' => ''], ['timeout' => 0]] as $ubah) {
            foreach (['pastikanSiasn', 'pastikanEkinerja'] as $metode) {
                try {
                    $this->bkn($ubah)->{$metode}();
                    $this->fail("{$metode} harus menolak " . json_encode($ubah));
                } catch (KonfigurasiIntegrasiTidakLengkap $e) {
                    $this->assertSame([array_key_first($ubah)], $e->kunci);
                }
            }
        }

        $this->assertSame([], $this->transport->tercatat(), 'tidak ada permintaan keluar dengan TLS mati');
    }

    public function testParameterKredensialBertandaSensitive(): void
    {
        $periksa = static function (string $kelas, string $metode, array $nama): array {
            $tanda = [];

            foreach ((new \ReflectionMethod($kelas, $metode))->getParameters() as $p) {
                if (in_array($p->getName(), $nama, true)) {
                    $tanda[$p->getName()] = $p->getAttributes(\SensitiveParameter::class) !== [];
                }
            }

            return $tanda;
        };

        $this->assertSame(['user' => true, 'pass' => true, 'body' => true], $periksa(TokenBkn::class, 'minta', ['user', 'pass', 'body']));
        $this->assertSame(['password' => true, 'totp' => true], $periksa(TokenBkn::class, 'bodySso', ['password', 'totp']));
        $this->assertSame(['header' => true, 'form' => true, 'rahasia' => true], $periksa(Permintaan::class, '__construct', ['header', 'form', 'rahasia']));
        $this->assertSame(['rahasia' => true], $periksa(\App\Libraries\Integrasi\Http\Redaktor::class, 'teks', ['rahasia']));
    }

    public function testEkinerjaStatis401TidakDiulang(): void
    {
        $this->transport->antreJson(401, ['message' => 'token tidak sah']);

        $r = $this->bkn(['ekinerjaToken' => 'tok-statis'])->kirimEkinerja(static fn (array $h): Permintaan => new Permintaan('GET', 'https://kinerja.contoh/api_kinerja/referensi/periode', 'ekinerja', 'periode', header: $h));

        $this->assertSame(401, $r->status);
        $this->assertCount(1, $this->transport->tercatat());
    }

    public function testTokenGagalMelemparTanpaRahasiaDanLogBersih(): void
    {
        $this->transport->antreJson(401, ['error' => 'invalid_client', 'detail' => 'client gw-rahasia-uji ditolak', 'access_token' => 'bocor-ga']);

        try {
            $this->bkn()->tokenGateway();
            $this->fail('GalatTokenBkn harus dilempar');
        } catch (GalatTokenBkn $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        $teks = $this->log->semuaTeks() . $e->getMessage();

        foreach (['gw-rahasia-uji', 'gw-pengguna-uji', 'bocor-ga', base64_encode('gw-pengguna-uji:gw-rahasia-uji')] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $teks, "bocor: {$rahasia}");
        }

        $this->assertSame([], $this->cacheMentah->ttl, 'token gagal tidak di-cache');
    }

    public function testTanpaExpiresInTidakDiCache(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'a'])->antreJson(200, ['access_token' => 'b']);
        $bkn = $this->bkn();

        $this->assertSame('a', $bkn->tokenGateway());
        $this->assertSame('b', $bkn->tokenGateway());
        $this->assertSame([], $this->cacheMentah->ttl);
    }

    public function testKonfigurasiKosongDitolakPerPemakaian(): void
    {
        $kosong = new TokenBkn(new KlienHttp($this->transport, $this->log), new CacheService($this->cacheMentah, 'uji_'), []);

        foreach (['pastikanSiasn', 'pastikanEkinerja'] as $metode) {
            try {
                $kosong->{$metode}();
                $this->fail("{$metode} harus menolak");
            } catch (KonfigurasiIntegrasiTidakLengkap $e) {
                $this->assertContains('baseUrlEkinerja', $metode === 'pastikanEkinerja' ? $e->kunci : ['baseUrlEkinerja']);
            }
        }

        $this->bkn(['ekinerjaToken' => 'x', 'ekinerjaApiUser' => ''])->pastikanEkinerja();
        $this->bkn()->pastikanSiasn();
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, bool|float|string> $ubah
     */
    private function bkn(array $ubah = []): TokenBkn
    {
        return new TokenBkn(new KlienHttp($this->transport, $this->log), new CacheService($this->cacheMentah, 'uji_'), array_merge(self::KONFIG, $ubah));
    }

}
