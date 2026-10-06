<?php

declare(strict_types=1);

namespace Tests\MasterData;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Libraries\TipeKolomSkema;

/**
 * CR-031 (T-1 review DBV-007) — normalisasi COLUMN_TYPE schema test: keluaran MariaDB (`int(11)`, `tinyint(4)`) dan
 * MySQL 8 (`int`, `tinyint`) menjadi sama, tanpa melonggarkan tipe lain.
 *
 * @internal
 */
final class TipeKolomSkemaTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideTipe(): iterable
    {
        return [
            'int MariaDB'          => ['int(11)', 'int'],
            'tinyint MariaDB'      => ['tinyint(4)', 'tinyint'],
            'int MySQL 8'          => ['int', 'int'],
            'tinyint MySQL 8'      => ['tinyint', 'tinyint'],
            'varchar tetap'        => ['varchar(50)', 'varchar(50)'],
            'decimal tetap'        => ['decimal(10,7)', 'decimal(10,7)'],
            'int unsigned MariaDB' => ['int(10) unsigned', 'int unsigned'],
            'int unsigned MySQL 8' => ['int unsigned', 'int unsigned'],
            'smallint MariaDB'     => ['smallint(6)', 'smallint'],
            'bigint MariaDB'       => ['bigint(20)', 'bigint'],
            'tinyint(1) tetap'     => ['tinyint(1)', 'tinyint(1)'],
            'year MariaDB'         => ['year(4)', 'year'],
            'huruf besar'          => ['INT(11)', 'int'],
            'double tetap'         => ['double', 'double'],
            'mediumtext tetap'     => ['mediumtext', 'mediumtext'],
        ];
    }

    #[DataProvider('provideTipe')]
    public function testNormalisasiMembuangLebarTampilanSaja(string $input, string $expected): void
    {
        $this->assertSame($expected, TipeKolomSkema::normalisasi($input));
    }

    public function testTipeBerbedaTetapBerbeda(): void
    {
        $this->assertNotSame(TipeKolomSkema::normalisasi('int(11)'), TipeKolomSkema::normalisasi('tinyint(4)'));
        $this->assertNotSame(TipeKolomSkema::normalisasi('int(10) unsigned'), TipeKolomSkema::normalisasi('int(11)'));
        $this->assertNotSame(TipeKolomSkema::normalisasi('varchar(50)'), TipeKolomSkema::normalisasi('varchar(255)'));
    }
}
