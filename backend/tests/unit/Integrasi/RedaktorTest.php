<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Http\CurlTransport;
use App\Libraries\Integrasi\Http\Redaktor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-063 (BP-5, AC-K.1) — redaksi log integrasi: kunci `username`/`client_id`, nilai rahasia literal dalam bentuk
 * JSON-escaped (`\/`), token Bearer/Basic yang memuat `\`, angka 18 digit (bentuk NIP) di body galat, dan pesan galat
 * cURL tanpa host/IP tujuan. Nilai fiktif.
 *
 * @internal
 */
final class RedaktorTest extends CIUnitTestCase
{
    public function testUsernameDanClientIdDisensorDiFormDanJson(): void
    {
        $r = new Redaktor();

        $this->assertSame('grant_type=password&client_id=***&username=***', $r->teks('grant_type=password&client_id=klien-uji&username=pengguna-uji'));
        $this->assertSame('{"username":"***","client_id":"***"}', $r->teks('{"username":"pengguna-uji","client_id":"klien-uji"}'));
    }

    public function testRahasiaLiteralBentukJsonEscaped(): void
    {
        $teks = (new Redaktor())->teks('{"detail":"sandi a\/b+c ditolak"}', ['a/b+c']);

        $this->assertStringNotContainsString('a\/b+c', $teks);
        $this->assertStringNotContainsString('a/b+c', $teks);
    }

    public function testTokenBearerDenganBackslashTidakBocorSebagian(): void
    {
        $teks = (new Redaktor())->teks('{"pesan":"header Bearer abc\/def.ghi ditolak"}');

        $this->assertStringContainsString('Bearer ***', $teks);
        $this->assertStringNotContainsString('def.ghi', $teks);
    }

    public function testBentukNipDisensor(): void
    {
        $teks = (new Redaktor())->teks('{"message":"pns 199001012020011001 tidak ditemukan","kode":12345}');

        $this->assertStringNotContainsString('199001012020011001', $teks);
        $this->assertStringContainsString('12345', $teks, 'angka pendek tidak disensor');
    }

    public function testPesanCurlTanpaHost(): void
    {
        $this->assertSame('cURL error 6: Could not resolve hostname', CurlTransport::pesanAman('6 : Could not resolve host: api.contoh.internal'));
        $this->assertStringNotContainsString('10.1.2.3', CurlTransport::pesanAman('7 : Failed to connect to 10.1.2.3 port 443'));
        $this->assertStringNotContainsString('contoh', CurlTransport::pesanAman('galat tak terduga ke https://contoh.internal/x'));
    }
}
