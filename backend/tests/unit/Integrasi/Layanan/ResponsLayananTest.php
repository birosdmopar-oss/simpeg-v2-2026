<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Layanan;

use App\Libraries\Integrasi\Layanan\ResponsLayanan;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

/**
 * MAKE-061 (kerangka `services/*`) — amplop bisnis PRD Fase 6 §6.3: sukses `{"status":1,"msg":"","data":…}` dan gagal
 * `{"status":2,…}` HTTP 200; `tidakDitemukan()` 404 dengan amplop yang sama. Dua bentuk `status` [K]: bilangan bulat
 * (`s_stat`, `s_dm`) dan string `"1"`/`"2"` (`s_esign`, parameter `statusString`). `data` kosong = `{}` (objek) untuk gagal
 * dan tetap `[]` (array) bila daftar kosong.
 *
 * @internal
 */
final class ResponsLayananTest extends CIUnitTestCase
{
    public function testSuksesDaftarKosongTetapArrayStatusInt(): void
    {
        $res = ResponsLayanan::sukses([]);
        $b   = self::badan($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame(1, $b->status);
        $this->assertSame('', $b->msg);
        $this->assertSame([], $b->data, 'daftar kosong tetap array JSON []');

        $b = self::badan(ResponsLayanan::sukses(['a' => 1], 'ok'));
        $this->assertSame([1, 'ok', 1], [$b->status, $b->msg, $b->data->a]);
    }

    public function testGagalBisnisHttp200DenganDataObjekKosong(): void
    {
        $res = ResponsLayanan::gagal('Invalid parameter.');
        $b   = self::badan($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([2, 'Invalid parameter.'], [$b->status, $b->msg]);
        $this->assertInstanceOf(stdClass::class, $b->data, 'data kosong = objek {} seperti legacy');

        $this->assertSame([1], self::badan(ResponsLayanan::gagal('x', [1]))->data);
    }

    public function testTidakDitemukan404AmplopSama(): void
    {
        $res = ResponsLayanan::tidakDitemukan();
        $b   = self::badan($res);

        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame([2, 'Berkas tidak ditemukan.'], [$b->status, $b->msg]);
        $this->assertInstanceOf(stdClass::class, $b->data);
    }

    public function testModeStatusStringUntukSEsign(): void
    {
        $b = self::badan(ResponsLayanan::sukses(['id' => 5], '', true));
        $this->assertSame('1', $b->status, 'status STRING "1" [K S_esign.php:12]');
        $this->assertSame(5, $b->data->id);

        $b = self::badan(ResponsLayanan::gagal('NIK tidak terdaftar', null, true));
        $this->assertSame(['2', 'NIK tidak terdaftar'], [$b->status, $b->msg]);
        $this->assertInstanceOf(stdClass::class, $b->data);

        $res = ResponsLayanan::tidakDitemukan('Tidak ada', true);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('2', self::badan($res)->status);

        // Mode bawaan tetap INT.
        $this->assertSame(1, self::badan(ResponsLayanan::sukses([]))->status);
    }

    private static function badan(ResponseInterface $res): stdClass
    {
        $b = json_decode($res->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $b);

        return $b;
    }
}
