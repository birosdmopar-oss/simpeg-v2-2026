<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Konten;

use App\Libraries\Dashboard\Konten\Penargetan\SpesifikasiTarget;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * Penargetan F-04/F-05 (PRD Fase 7 v2.0 §5.3, Q7-15): spesifikasi target murni — normalisasi, format token, kolom JSON.
 *
 * @internal
 */
final class SpesifikasiTargetTest extends CIUnitTestCase
{
    public function testNormalisasiMembuangKosongDanDuplikat(): void
    {
        $t = SpesifikasiTarget::dariArray(['unit_satker' => ['3', ' 3 ', '', 'sat_9', 7], 'pegawai' => ['198001', '198001'], 'kecuali' => []]);

        $this->assertSame(['3', 'sat_9', '7'], $t->unitSatker);
        $this->assertSame(['198001'], $t->pegawai);
        $this->assertSame([3, 7], $t->idUnit());
        $this->assertSame([9], $t->idSatker());
        $this->assertFalse($t->seluruhKementerian());
        $this->assertFalse($t->kosong());
    }

    public function testSeluruhKementerianTidakDihitungSebagaiIdUnit(): void
    {
        $t = SpesifikasiTarget::dariArray(['unit_satker' => ['0']]);

        $this->assertTrue($t->seluruhKementerian());
        $this->assertSame([], $t->idUnit());
    }

    public function testKosongBilaTanpaUnitDanPegawai(): void
    {
        $this->assertTrue(SpesifikasiTarget::dariArray([])->kosong());
        $this->assertTrue(SpesifikasiTarget::dariArray(['kecuali' => ['1']])->kosong());
    }

    /**
     * @dataProvider provTidakValid
     *
     * @param array<string, mixed> $data
     */
    public function testFormatTidakValidDitolak(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        SpesifikasiTarget::dariArray($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provTidakValid(): iterable
    {
        yield 'token unit huruf' => [['unit_satker' => ['abc']]];
        yield 'token nol di depan' => [['unit_satker' => ['01']]];
        yield 'sat tanpa angka' => [['unit_satker' => ['sat_']]];
        yield 'bukan daftar' => [['pegawai' => '198001']];
        yield 'item array' => [['pegawai' => [['x']]]];
        yield 'nip berisi spasi' => [['pegawai' => ['1980 01']]];
        yield 'nip terlalu panjang' => [['pegawai' => [str_repeat('1', 31)]]];
    }

    public function testKolomBolakBalikDanKosongMenjadiNull(): void
    {
        $t = SpesifikasiTarget::dariArray(['unit_satker' => ['0'], 'pegawai' => [], 'kecuali' => ['198002']]);
        $k = $t->keKolom();

        $this->assertSame('["0"]', $k['target_uns']);
        $this->assertNull($k['target_peg']);
        $this->assertSame('["198002"]', $k['excl_peg']);
        $this->assertSame($t->keArray(), SpesifikasiTarget::dariKolom($k['target_uns'], $k['target_peg'], $k['excl_peg'])->keArray());
    }

    public function testDariKolomToleranJsonRusak(): void
    {
        $this->assertTrue(SpesifikasiTarget::dariKolom('bukan json', null, '')->kosong());
    }
}
