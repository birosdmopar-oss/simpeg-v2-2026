<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Layanan;

use App\Libraries\Integrasi\Layanan\ResponsLayanan;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-061 (kerangka `services/*`) — amplop bisnis PRD Fase 6 §6.3: sukses `{"status":1,"msg":"","data":…}` dan gagal
 * `{"status":2,…}` HTTP 200; `tidakDitemukan()` 404 dengan amplop yang sama. Dua bentuk `status` [K]: bilangan bulat
 * (`s_stat`, `s_dm`) dan string `"1"`/`"2"` (`s_esign`, parameter `statusString`). `data` kosong = `{}` untuk gagal dan tetap
 * `[]` bila daftar kosong.
 *
 * @internal
 */
final class ResponsLayananTest extends CIUnitTestCase
{
    public function testSuksesDaftarKosongTetapArrayStatusInt(): void
    {
        $res = ResponsLayanan::sukses([]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('{"status":1,"msg":"","data":[]}', self::padat($res->getBody()));
        $this->assertSame('{"status":1,"msg":"ok","data":{"a":1}}', self::padat(ResponsLayanan::sukses(['a' => 1], 'ok')->getBody()));
    }

    public function testGagalBisnisHttp200DenganDataObjekKosong(): void
    {
        $res = ResponsLayanan::gagal('Invalid parameter.');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('{"status":2,"msg":"Invalid parameter.","data":{}}', self::padat($res->getBody()));
        $this->assertSame('{"status":2,"msg":"x","data":[1]}', self::padat(ResponsLayanan::gagal('x', [1])->getBody()));
    }

    public function testTidakDitemukan404AmplopSama(): void
    {
        $res = ResponsLayanan::tidakDitemukan();

        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('{"status":2,"msg":"Berkas tidak ditemukan.","data":{}}', self::padat($res->getBody()));
    }

    public function testModeStatusStringUntukSEsign(): void
    {
        $this->assertSame('{"status":"1","msg":"","data":{"id":5}}', self::padat(ResponsLayanan::sukses(['id' => 5], '', true)->getBody()));
        $this->assertSame('{"status":"2","msg":"NIK tidak terdaftar","data":{}}', self::padat(ResponsLayanan::gagal('NIK tidak terdaftar', null, true)->getBody()));

        $res = ResponsLayanan::tidakDitemukan('Tidak ada', true);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('{"status":"2","msg":"Tidak ada","data":{}}', self::padat($res->getBody()));
    }

    private static function padat(string $json): string
    {
        return (string) preg_replace('/\s+/', '', $json);
    }
}
