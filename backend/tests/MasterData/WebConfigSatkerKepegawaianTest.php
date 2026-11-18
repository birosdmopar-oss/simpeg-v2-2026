<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Libraries\MasterData\WebConfigService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-139 — `WebConfigService::satkerKepegawaian()`: pembacaan `id_satker_kepegawaian` bersama untuk
 * `PetugasLayananService` dan `HaloSimpeg\PetugasService` (tanpa DB; `value()` di-stub).
 *
 * @internal
 */
final class WebConfigSatkerKepegawaianTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function nilaiProvider(): iterable
    {
        yield 'kosong (belum diatur)' => [null, null];
        yield 'string kosong' => ['', null];
        yield 'bukan angka' => ['abc', null];
        yield 'angka bercampur huruf' => ['12a', null];
        yield 'negatif' => [-5, null];
        yield 'desimal' => [1.5, null];
        yield 'daftar' => [['101'], null];
        yield 'angka int' => [101, 101];
        yield 'angka string' => ['101', 101];
        yield 'nol' => ['0', 0];
    }

    #[DataProvider('nilaiProvider')]
    public function testSatkerKepegawaian(mixed $nilai, ?int $harapan): void
    {
        $service = $this->createPartialMock(WebConfigService::class, ['value']);
        $service->expects($this->once())->method('value')->with('id_satker_kepegawaian')->willReturn($nilai);

        $this->assertSame($harapan, $service->satkerKepegawaian());
    }
}
