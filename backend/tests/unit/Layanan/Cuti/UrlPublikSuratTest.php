<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Layanan;
use Config\Services;
use RuntimeException;

/**
 * `layanan.urlPublikSurat` (MAKE-065, review B4a P5): dasar URL QR surat cuti yang dibekukan di PDF ber-TTE — URL absolut
 * http/https, wajib eksplisit dan https di production, gagal-cepat dengan pesan tanpa nilai; dipakai `Services::tokenSuratCuti`.
 *
 * @internal
 */
final class UrlPublikSuratTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    public function testKosongDiLuarProductionMemakaiBaseUrl(): void
    {
        $config                 = new Layanan();
        $config->urlPublikSurat = '';

        $this->assertSame(rtrim((string) config(App::class)->baseURL, '/') . '/', $config->urlPublikSurat('development'));
    }

    public function testIsianSahDinormalisasiDenganGarisMiring(): void
    {
        $config                 = new Layanan();
        $config->urlPublikSurat = ' https://simpeg.contoh.test/ ';

        $this->assertSame('https://simpeg.contoh.test/', $config->urlPublikSurat('production'));
        $config->urlPublikSurat = 'http://localhost:8080';
        $this->assertSame('http://localhost:8080/', $config->urlPublikSurat('testing'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function tidakSah(): iterable
    {
        yield 'kosong di production' => ['', 'production', 'wajib diisi di production'];
        yield 'http di production' => ['http://simpeg.contoh.test', 'production', 'wajib https di production'];
        yield 'relatif' => ['/simpeg', 'development', 'harus URL absolut http/https'];
        yield 'skema lain' => ['ftp://simpeg.contoh.test', 'development', 'harus URL absolut http/https'];
        yield 'tanpa host' => ['https://', 'production', 'harus URL absolut http/https'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tidakSah')]
    public function testTidakSahGagalCepatTanpaMenyebutNilai(string $isian, string $lingkungan, string $pesan): void
    {
        $config                 = new Layanan();
        $config->urlPublikSurat = $isian;

        try {
            $config->urlPublikSurat($lingkungan);
            $this->fail('harus gagal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('layanan.urlPublikSurat', $e->getMessage());
            $this->assertStringContainsString($pesan, $e->getMessage());

            if ($isian !== '') {
                $this->assertStringNotContainsString($isian, $e->getMessage(), 'nilai tidak dibocorkan');
            }
        }
    }

    public function testServicesTokenSuratCutiMemakaiDasarUrlPublik(): void
    {
        config(Layanan::class)->urlPublikSurat = 'https://simpeg.contoh.test/';

        try {
            $token = Services::tokenSuratCuti(false);
            $this->assertSame('https://simpeg.contoh.test/verify/surat-cuti/' . str_repeat('ab', 32), $token->urlVerifikasi(str_repeat('ab', 32)));

            config(Layanan::class)->urlPublikSurat = 'bukan-url';
            $this->expectException(RuntimeException::class);
            Services::tokenSuratCuti(false);
        } finally {
            config(Layanan::class)->urlPublikSurat = '';
        }
    }
}
