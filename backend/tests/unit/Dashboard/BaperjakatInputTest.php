<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Dokumen\BaperjakatInput;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7-21 input Baperjakat (MAKE-037): `nip[]` 1..2000 unik, `judul` wajib ≤ 150, `subjudul` ≤ 150, `teks` ≤ 1000;
 * pesan NIP bermasalah maks 20 disebut.
 *
 * @internal
 */
final class BaperjakatInputTest extends CIUnitTestCase
{
    public function testValidDanDedup(): void
    {
        $hasil = BaperjakatInput::dari(['nip' => ['1', '2', ' 1 ', 3], 'judul' => ' Daftar Baperjakat ', 'subjudul' => 'Sidang I', 'teks' => "Baris 1\nBaris 2"]);

        $this->assertSame(['1', '2', '3'], $hasil['nip']);
        $this->assertSame('Daftar Baperjakat', $hasil['judul']);
        $this->assertSame('Sidang I', $hasil['subjudul']);
        $this->assertSame("Baris 1\nBaris 2", $hasil['teks']);
        $this->assertSame(['nip' => ['1'], 'judul' => 'J', 'subjudul' => '', 'teks' => ''], BaperjakatInput::dari(['nip' => ['1'], 'judul' => 'J']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function tidakValid(): iterable
    {
        yield 'nip kosong' => [['nip' => [], 'judul' => 'J'], ['nip']];

        yield 'nip bukan array' => [['nip' => '1', 'judul' => 'J'], ['nip']];

        yield 'nip bersarang' => [['nip' => [['1']], 'judul' => 'J'], ['nip']];

        yield 'nip terlalu panjang' => [['nip' => [str_repeat('1', 31)], 'judul' => 'J'], ['nip']];

        yield 'nip > 2000' => [['nip' => array_map('strval', range(1, 2001)), 'judul' => 'J'], ['nip']];

        yield 'judul kosong' => [['nip' => ['1'], 'judul' => '  '], ['judul']];

        yield 'judul hilang' => [['nip' => ['1']], ['judul']];

        yield 'judul > 150' => [['nip' => ['1'], 'judul' => str_repeat('a', 151)], ['judul']];

        yield 'judul bukan teks' => [['nip' => ['1'], 'judul' => ['x']], ['judul']];

        yield 'subjudul > 150, teks > 1000' => [['nip' => ['1'], 'judul' => 'J', 'subjudul' => str_repeat('b', 151), 'teks' => str_repeat('c', 1001)], ['subjudul', 'teks']];

        yield 'semua sekaligus' => [['nip' => [], 'judul' => ''], ['judul', 'nip']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tidakValid')]
    public function testTidakValid422PerField(array $body, array $fields): void
    {
        try {
            BaperjakatInput::dari($body);
            $this->fail('harus 422');
        } catch (ValidationException $e) {
            $errors = (array) $e->getErrors();
            ksort($errors);
            $this->assertSame($fields, array_keys($errors));
        }
    }

    public function testGalatNipMenyebutMaksDuaPuluh(): void
    {
        $e     = BaperjakatInput::galatNip(array_map('strval', range(1, 25)));
        $pesan = ((array) $e->getErrors())['nip'][0];

        $this->assertStringContainsString('1, 2, 3', $pesan);
        $this->assertStringContainsString('20', $pesan);
        $this->assertStringNotContainsString('21', $pesan);
        $this->assertStringContainsString('(+5 lagi)', $pesan);
        $this->assertStringNotContainsString('lagi', ((array) BaperjakatInput::galatNip(['9'])->getErrors())['nip'][0]);
    }
}
