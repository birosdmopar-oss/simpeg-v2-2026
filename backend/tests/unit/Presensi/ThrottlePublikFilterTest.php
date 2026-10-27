<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi;

use App\Filters\ThrottlePublikFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Services;

/**
 * PRD 5A §6.7, AC-D14.4/AC-D14.5 (bagian filter): 30 permintaan per 60 detik per IP; permintaan ke-31 → 429 dengan
 * `Retry-After`, `X-Robots-Tag`, `Cache-Control: no-store`; bucket per IP (IP lain tidak terdampak); token terisi lagi
 * setelah waktu berjalan. Waktu disuntik lewat `Throttler::setTestTime`. IP = rentang dokumentasi (RFC 5737).
 *
 * @internal
 */
final class ThrottlePublikFilterTest extends CIUnitTestCase
{
    private Throttler $throttler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttler = new Throttler(new MockCache());
        $this->throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $this->throttler);
        Services::resetSingle('response');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        Services::resetSingle('response');
        parent::tearDown();
    }

    public function testPermintaanKe31Ditolak429(): void
    {
        for ($i = 1; $i <= ThrottlePublikFilter::KAPASITAS; $i++) {
            $this->assertNull($this->panggil('203.0.113.5'), "permintaan ke-{$i}");
        }

        $respons = $this->panggil('203.0.113.5');
        $this->assertInstanceOf(ResponseInterface::class, $respons);
        $this->assertSame(429, $respons->getStatusCode());
        $this->assertSame(['status' => 'error', 'message' => ThrottlePublikFilter::PESAN], json_decode((string) $respons->getBody(), true));
        $this->assertGreaterThanOrEqual(1, (int) $respons->getHeaderLine('Retry-After'));
        $this->assertSame('noindex, nofollow', $respons->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $respons->getHeaderLine('Cache-Control'));

        $this->assertNull($this->panggil('203.0.113.6'), 'IP lain punya bucket sendiri');
    }

    public function testTokenTerisiLagiSetelahWaktuBerjalan(): void
    {
        for ($i = 0; $i <= ThrottlePublikFilter::KAPASITAS; $i++) {
            $this->panggil('203.0.113.7');
        }

        $this->assertNotNull($this->panggil('203.0.113.7'));

        $this->throttler->setTestTime(1_790_000_000 + 3); // 30/60 detik → 1 token per 2 detik
        $this->assertNull($this->panggil('203.0.113.7'));
    }

    private function panggil(string $ip): ?ResponseInterface
    {
        Services::resetSingle('response');
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('server', ['REMOTE_ADDR' => $ip]);
        $this->setPrivateProperty($request, 'ipAddress', '');

        /** @var IncomingRequest $request */
        $hasil = (new ThrottlePublikFilter())->before($request);

        return $hasil instanceof ResponseInterface ? $hasil : null;
    }
}
