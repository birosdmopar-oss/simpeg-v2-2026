<?php

declare(strict_types=1);

namespace Tests\Support\Libraries;

/**
 * Normalisasi COLUMN_TYPE information_schema untuk schema test yang harus lolos di MySQL 8 dan MariaDB/MySQL lama.
 * MariaDB menulis lebar tampilan integer (`int(11)`, `tinyint(4)`, `int(10) unsigned`) dan `year(4)`; MySQL 8 tidak.
 * Lebar itu dibuang, kecuali `tinyint(1)` (MySQL 8 tetap menuliskannya). Tipe lain (`varchar(50)`, `decimal(10,7)`,
 * `double`) dan atribut `unsigned`/`zerofill` tidak diubah, sehingga perbandingan tetap ketat. Pola sama dengan helper
 * schema test DBV-012/DBV-013 (`SkemaKepegawaianTestTrait::skemaColumns`).
 */
final class TipeKolomSkema
{
    public static function normalisasi(string $columnType): string
    {
        $type = strtolower(trim($columnType));
        $type = (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\((?!1\))\d+\)/', '$1', $type);

        return $type === 'year(4)' ? 'year' : $type;
    }
}
