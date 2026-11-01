<?php

declare(strict_types=1);

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Connection as BawaanConnection;
use mysqli;

/**
 * Koneksi MySQLi yang memaksa zona SESI database = UTC (CR-086, DBV-025; penerapan DBV-011 §3.3).
 *
 * Kolom audit DATETIME proyek berisi UTC. Tanpa ini, `DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP` / `NOW()`
 * mengikuti zona sesi server (lokal/dev = WIB) sehingga satu kolom berisi dua zona. `+00:00` (offset, bukan nama zona)
 * dipilih karena tidak bergantung pada tabel zona waktu server dan dikenal MySQL 8 maupun MariaDB 10.4.
 *
 * Dipasang lewat `DBDriver = App\Database\MySQLi` (Config\Database) untuk grup default dan tests; kelas lain di namespace ini
 * (Builder, Forge, PreparedQuery, Result, Utils) hanya turunan kosong karena CI4 menurunkan nama kelasnya dari kelas koneksi.
 */
class Connection extends BawaanConnection
{
    public const ZONA_SESI = '+00:00';

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params)
    {
        parent::__construct($params);

        // Forge/Utils bawaan MySQLi dan pemeriksaan platform (`getPlatform() === 'MySQLi'`, queue) tetap sama seperti tanpa driver kustom.
        $this->DBDriver = 'MySQLi';
    }

    /**
     * @return false|mysqli
     */
    public function connect(bool $persistent = false)
    {
        $koneksi = parent::connect($persistent);

        if ($koneksi instanceof mysqli) {
            $koneksi->query("SET time_zone = '" . self::ZONA_SESI . "'");
        }

        return $koneksi;
    }
}
