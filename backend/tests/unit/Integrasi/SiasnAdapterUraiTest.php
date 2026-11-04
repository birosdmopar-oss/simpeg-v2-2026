<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `SiasnAdapter::urai()` (MAKE-067, review B6 M1 P-7): hanya HTTP 2xx + JSON `code == 1` + `data` array yang sukses;
 * selain itu kode gagal tanpa data — pesan tanpa isi badan/host.
 *
 * @internal
 */
final class SiasnAdapterUraiTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{Respons, string}>
     */
    public static function jawaban(): iterable
    {
        yield 'sukses daftar' => [new Respons(200, '{"code":1,"data":[{"id":"X"}]}'), '200'];
        yield 'sukses code string' => [new Respons(200, '{"code":"1","data":{"id":"X"}}'), '200'];
        yield 'sukses data kosong' => [new Respons(200, '{"code":1,"data":[]}'), '200'];
        yield 'http 404' => [new Respons(404, 'tidak ada'), '404'];
        yield 'http 500' => [new Respons(500, '{"code":1,"data":[]}'), '500'];
        yield 'json rusak' => [new Respons(200, '<html>'), 'respons'];
        yield 'code 0' => [new Respons(200, '{"code":0,"data":[]}'), 'respons'];
        yield 'tanpa code' => [new Respons(200, '{"data":[]}'), 'respons'];
        yield 'data teks' => [new Respons(200, '{"code":1,"data":"x"}'), 'respons'];
        yield 'data null' => [new Respons(200, '{"code":1,"data":null}'), 'respons'];
    }

    #[DataProvider('jawaban')]
    public function testUrai(Respons $respons, string $kode): void
    {
        $hasil = SiasnAdapter::urai($respons);

        $this->assertSame($kode, $hasil['status_code']);

        if ($kode !== SiasnAdapter::SUKSES) {
            $this->assertNull($hasil['data']);
            $this->assertStringNotContainsString('html', $hasil['message']);
        }
    }
}
