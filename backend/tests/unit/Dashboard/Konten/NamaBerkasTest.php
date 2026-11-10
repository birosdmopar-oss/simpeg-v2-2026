<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Konten;

use App\Libraries\Dashboard\Konten\NamaBerkas;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Nama berkas unduhan konten Fase 7 aman untuk header `Content-Disposition`: ekstensi hanya `[a-z0-9]{1,5}`, selain itu `bin`.
 *
 * @internal
 */
final class NamaBerkasTest extends CIUnitTestCase
{
    /**
     * @dataProvider provKunci
     */
    public function testEkstensi(string $kunci, string $diharapkan): void
    {
        $this->assertSame($diharapkan, NamaBerkas::ekstensi($kunci));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provKunci(): iterable
    {
        yield 'normal' => ['dibo/0123456789abcdef0123456789abcdef.pdf', 'pdf'];
        yield 'huruf besar' => ['dibo/x.PDF', 'pdf'];
        yield 'xlsx' => ['dibo/x.xlsx', 'xlsx'];
        yield 'tanpa ekstensi' => ['dibo/x', 'bin'];
        yield 'terlalu panjang' => ['dibo/x.abcdefgh', 'bin'];
        yield 'CRLF' => ["dibo/x.pdf\r\nSet-Cookie: a=b", 'bin'];
        yield 'CR saja' => ["dibo/x.p\rdf", 'bin'];
        yield 'kutip' => ['dibo/x.p"df', 'bin'];
        yield 'titik koma' => ['dibo/x.pdf;x', 'bin'];
        yield 'spasi' => ['dibo/x.p df', 'bin'];
        yield 'garis miring' => ['dibo/x.a/b', 'bin'];
        yield 'nol byte' => ["dibo/x.pd\0f", 'bin'];
        yield 'kosong' => ['', 'bin'];
    }
}
