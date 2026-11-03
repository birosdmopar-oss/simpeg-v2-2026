<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\KonfigurasiIntegrasi;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Integrations;

/**
 * MAKE-063 AC-K.4 — driver real dengan konfigurasi kosong gagal saat dibuat; pesan menyebut kunci `.env`, bukan nilai.
 * Bawaan `Config\Integrations` (repo publik) kosong untuk semua alamat & kredensial BKN.
 *
 * @internal
 */
final class KonfigurasiIntegrasiTest extends CIUnitTestCase
{
    public function testKonfigurasiKosongDitolakDenganNamaKunciTanpaNilai(): void
    {
        try {
            KonfigurasiIntegrasi::pastikan('bkn', ['baseUrlApi' => '  ', 'clientId' => 'id-uji-rahasia', 'clientSecret' => ''], ['baseUrlApi', 'clientId', 'clientSecret', 'ssoUsername']);
            $this->fail('harus dilempar');
        } catch (KonfigurasiIntegrasiTidakLengkap $e) {
            $this->assertSame(['baseUrlApi', 'clientSecret', 'ssoUsername'], $e->kunci);
            $this->assertStringContainsString('integrations.bkn.baseUrlApi', $e->getMessage());
            $this->assertStringNotContainsString('id-uji-rahasia', $e->getMessage());
        }
    }

    public function testOpsiTransportKetatTlsDanTimeout(): void
    {
        $o = KonfigurasiIntegrasi::opsiTransport('bkn', ['tlsVerify' => 'true', 'timeout' => '12'], wajibTls: true);
        $this->assertSame([5.0, 12.0, true], [$o->connectTimeout, $o->timeout, $o->verifyTls]);

        $o = KonfigurasiIntegrasi::opsiTransport('esign', ['tlsVerify' => 'false', 'timeout' => 3]);
        $this->assertSame([3.0, 3.0, false], [$o->connectTimeout, $o->timeout, $o->verifyTls], 'non-BKN boleh false (gateway internal)');

        $o = KonfigurasiIntegrasi::opsiTransport('bkn', [], wajibTls: true);
        $this->assertSame([5.0, 30.0, true], [$o->connectTimeout, $o->timeout, $o->verifyTls], 'tidak ada = bawaan aman');

        foreach ([
            [['tlsVerify' => ''], 'tlsVerify'],
            [['tlsVerify' => 'ya'], 'tlsVerify'],
            [['tlsVerify' => null], 'tlsVerify'],
            [['tlsVerify' => false], 'tlsVerify'],
            [['tlsVerify' => '0'], 'tlsVerify'],
            [['timeout' => ''], 'timeout'],
            [['timeout' => 0], 'timeout'],
            [['timeout' => '-1'], 'timeout'],
            [['timeout' => 'tiga'], 'timeout'],
        ] as [$konfigurasi, $kunci]) {
            try {
                KonfigurasiIntegrasi::opsiTransport('bkn', $konfigurasi, wajibTls: true);
                $this->fail('harus ditolak: ' . json_encode($konfigurasi));
            } catch (KonfigurasiIntegrasiTidakLengkap $e) {
                $this->assertSame([$kunci], $e->kunci, (string) json_encode($konfigurasi));
                $this->assertStringContainsString("integrations.bkn.{$kunci}", $e->getMessage());
            }
        }
    }

    public function testKonfigurasiLengkapLolos(): void
    {
        KonfigurasiIntegrasi::pastikan('esign', ['baseUrl' => 'https://gateway.contoh'], ['baseUrl']);
        $this->addToAssertionCount(1);
    }

    public function testBawaanRepoKosongSehinggaDriverRealGagalSaatDibuat(): void
    {
        $bkn = config(Integrations::class)->bkn;

        $this->expectException(KonfigurasiIntegrasiTidakLengkap::class);
        KonfigurasiIntegrasi::pastikan('bkn', $bkn, ['baseUrlApi', 'baseUrlEkinerja', 'clientId', 'clientSecret']);
    }

    public function testRetensiLogBawaan180Hari(): void
    {
        $this->assertSame(180, config(Integrations::class)->logRetensiHari);
    }

    public function testServiceLogDanKlienTerdaftar(): void
    {
        $this->assertInstanceOf(\App\Interfaces\Integrasi\IntegrasiLogInterface::class, service('integrasiLog', false));
        $this->assertInstanceOf(\App\Libraries\Integrasi\Http\KlienHttp::class, service('klienHttp', false));
    }
}
