<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Cli;

use App\Libraries\Cli\OpsiCli;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-080 — `OpsiCli::urai` menerima `--k=v` dan `--k v` dari `$params` hasil parser CI4 (yang tidak memecah `=`), dan
 * menolak opsi tak dikenal, nilai kosong, bendera bernilai, opsi ganda, serta argumen yang tertelan sebagai nilai.
 *
 * @internal
 */
final class OpsiCliTest extends CIUnitTestCase
{
    private const DIKENAL = ['csv' => true, 'toleransi' => true, 'nip-penuh' => false];

    public function testKeduaBentukOpsiBernilaiDanBendera(): void
    {
        // Bentuk persis yang dihasilkan parser CI4 untuk `a.xlsx b.xlsx --csv=t.csv --toleransi 0.05 --nip-penuh`.
        $hasil = OpsiCli::urai([0 => 'a.xlsx', 1 => 'b.xlsx', 'csv=t.csv' => null, 'toleransi' => '0.05', 'nip-penuh' => null], self::DIKENAL);

        $this->assertSame(['a.xlsx', 'b.xlsx'], $hasil['argumen']);
        $this->assertSame(['csv' => 't.csv', 'toleransi' => '0.05', 'nip-penuh' => true], $hasil['opsi']);
    }

    public function testNilaiMemuatSamaDengan(): void
    {
        $this->assertSame(['csv' => 'a=b.csv'], OpsiCli::urai(['csv=a=b.csv' => null], self::DIKENAL)['opsi']);
    }

    public function testTanpaOpsi(): void
    {
        $this->assertSame(['argumen' => ['x'], 'opsi' => []], OpsiCli::urai(['x'], self::DIKENAL));
    }

    /**
     * @param array<int|string, string|null> $params
     */
    #[DataProvider('masukanSalah')]
    public function testMasukanSalahDitolak(array $params, string $pesan): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($pesan);

        OpsiCli::urai($params, self::DIKENAL);
    }

    /**
     * @return array<string, array{array<int|string, string|null>, string}>
     */
    public static function masukanSalah(): array
    {
        return [
            'tak dikenal bentuk ='     => [['tolerance=0.05' => null], 'Opsi --tolerance tidak dikenal.'],
            'tak dikenal bentuk spasi' => [['out' => 'x.csv'], 'Opsi --out tidak dikenal.'],
            'bernilai tanpa nilai'     => [['csv' => null], 'Opsi --csv wajib diberi nilai'],
            'bernilai nilai kosong'    => [['csv=' => null], 'Opsi --csv wajib diberi nilai'],
            'bendera diberi nilai ='   => [['nip-penuh=1' => null], 'Opsi --nip-penuh tidak menerima nilai'],
            'bendera menelan argumen'  => [['nip-penuh' => 'a.xlsx'], 'letakkan sebelum opsi'],
            'k=v menelan argumen'      => [['csv=t.csv' => 'a.xlsx'], "Argumen 'a.xlsx' terbaca sebagai nilai opsi --csv=t.csv"],
            'opsi ganda'               => [['csv=a.csv' => null, 'csv' => 'b.csv'], 'Opsi --csv diberikan lebih dari sekali.'],
        ];
    }
}
