<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Exceptions\ValidationException;
use App\Libraries\Bkn\Ekinerja\AntreTarikEkinerja;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-064 — validasi masukan tombol admin tarik ulang e-Kinerja (`POST api/v1/integrasi/bkn/tarik`).
 *
 * @internal
 */
final class AntreTarikEkinerjaTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{array<string, mixed>, array{jenis: string, tahun: int|null, bulan: int|null}}>
     */
    public static function sah(): array
    {
        return [
            'periode'               => [['jenis' => 'periode', 'tahun' => 'diabaikan'], ['jenis' => 'periode', 'tahun' => null, 'bulan' => null]],
            'skp tanpa tahun/bulan' => [['jenis' => 'skp_periodik'], ['jenis' => 'skp_periodik', 'tahun' => null, 'bulan' => null]],
            'skp string kosong'     => [['jenis' => 'skp_periodik', 'tahun' => '', 'bulan' => ''], ['jenis' => 'skp_periodik', 'tahun' => null, 'bulan' => null]],
            'skp angka'             => [['jenis' => 'skp_periodik', 'tahun' => 2026, 'bulan' => 10], ['jenis' => 'skp_periodik', 'tahun' => 2026, 'bulan' => 10]],
            'skp teks angka'        => [['jenis' => 'skp_periodik', 'tahun' => '2026', 'bulan' => '1'], ['jenis' => 'skp_periodik', 'tahun' => 2026, 'bulan' => 1]],
        ];
    }

    /**
     * @param array<string, mixed>                                    $masukan
     * @param array{jenis: string, tahun: int|null, bulan: int|null} $harap
     */
    #[DataProvider('sah')]
    public function testMasukanSah(array $masukan, array $harap): void
    {
        $this->assertSame($harap, (new AntreTarikEkinerja())->validasi($masukan));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function salah(): array
    {
        return [
            'tanpa jenis'         => [[], 'jenis'],
            'jenis lain'          => [['jenis' => 'siasn'], 'jenis'],
            'tahun tanpa bulan'   => [['jenis' => 'skp_periodik', 'tahun' => 2026], 'bulan'],
            'bulan tanpa tahun'   => [['jenis' => 'skp_periodik', 'bulan' => 3], 'tahun'],
            'bulan 13'            => [['jenis' => 'skp_periodik', 'tahun' => 2026, 'bulan' => 13], 'bulan'],
            'tahun pecahan'       => [['jenis' => 'skp_periodik', 'tahun' => 2026.5, 'bulan' => 3], 'tahun'],
            'tahun di luar batas' => [['jenis' => 'skp_periodik', 'tahun' => 1999, 'bulan' => 3], 'tahun'],
        ];
    }

    /**
     * @param array<string, mixed> $masukan
     */
    #[DataProvider('salah')]
    public function testMasukanSalah(array $masukan, string $field): void
    {
        try {
            (new AntreTarikEkinerja())->validasi($masukan);
            $this->fail('harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->getErrors());
        }
    }
}
