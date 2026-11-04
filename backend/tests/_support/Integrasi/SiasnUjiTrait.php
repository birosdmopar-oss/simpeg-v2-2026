<?php

declare(strict_types=1);

namespace Tests\Support\Integrasi;

use App\Libraries\Bkn\TokenBkn;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Siasn\SiasnAdapter;
use App\Libraries\Siasn\Tarik\TarikSiasn;
use CodeIgniter\Test\Mock\MockCache;
use Config\Integrations;
use Config\Services;

/**
 * Perangkat uji SIASN (MAKE-067): {@see SiasnAdapter} di atas {@see FakeTransport} (tanpa jaringan), konfigurasi BKN
 * sintetis, akun sistem integrasi siap (Z-1). Panggil {@see self::siapkanSiasn()} di `setUp()` dan
 * {@see self::pulihkanSiasn()} di `tearDown()`.
 */
trait SiasnUjiTrait
{
    protected const DASAR_SIASN = 'https://siasn.contoh/apisiasn/1.0';

    protected FakeTransport $transport;

    protected SiasnAdapter $adapter;

    protected int $idAkun;

    /** @var array<string, bool|float|string> */
    private array $bknAsli;

    protected function siapkanSiasn(): void
    {
        $this->transport = new FakeTransport();
        $this->bknAsli   = config(Integrations::class)->bkn;
        $konfig          = [
            'baseUrlApi'      => self::DASAR_SIASN,
            'tokenUrlGateway' => 'https://gateway.contoh/oauth2/token',
            'tokenUrlSso'     => 'https://sso.contoh/token',
            'clientId'        => 'klien-uji',
            'clientSecret'    => 'rahasia-uji',
            'ssoClientId'     => 'sso-klien-uji',
            'ssoUsername'     => 'pengguna-uji',
            'ssoPassword'     => 'sandi-uji',
        ] + $this->bknAsli;
        config(Integrations::class)->bkn = $konfig;

        $token = new TokenBkn(new KlienHttp($this->transport, new IntegrasiLog($this->db), tidur: static function (int $ms): void {
        }), new CacheService(new MockCache(), 'uji_'), $konfig);
        Services::injectMock('tokenBkn', $token);
        $this->adapter = new SiasnAdapter($token, self::DASAR_SIASN);

        $akun = new AkunSistemIntegrasi($this->db);
        $akun->siapkan();
        $this->idAkun = $akun->id();
    }

    protected function pulihkanSiasn(): void
    {
        config(Integrations::class)->bkn = $this->bknAsli;
        Services::reset(true);
    }

    protected function antreToken(): void
    {
        $this->transport->antreJson(200, ['access_token' => 'gw-uji', 'expires_in' => 3600])
            ->antreJson(200, ['access_token' => 'sso-uji', 'expires_in' => 3600]);
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    protected function antreData(array $data): void
    {
        $this->transport->antreJson(200, ['code' => 1, 'data' => $data]);
    }

    protected function tarik(): TarikSiasn
    {
        return new TarikSiasn($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), TarikSiasn::semuaJenis());
    }
}
