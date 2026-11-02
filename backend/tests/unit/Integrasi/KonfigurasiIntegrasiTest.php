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
}
