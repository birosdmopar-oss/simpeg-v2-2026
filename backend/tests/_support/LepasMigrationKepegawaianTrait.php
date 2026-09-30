<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Lepas/pasang ulang migration Kepegawaian B-01/B-02 (DBV-012/DBV-013, versi App >= 2026-09-30-120000) untuk test skema
 * yang memanggil down()/up() migration master di tengah jalan sementara migration sesudahnya tetap terpasang.
 *
 * Tabel `pegawai`, snapshot, dan riwayat merujuk master wilayah/agama/jenis pegawai/jenis status (DBV-001), pangkat &
 * pendidikan (G-04/G-05), serta diklat/hukdis/tanda jasa (G-06) dengan FK RESTRICT. Selama FK itu terpasang, down()
 * migration master ditolak MySQL (3730 saat DROP TABLE induk, 1832/1833 saat MODIFY kolom yang dirujuk). Test master
 * melepas migration B-01/B-02 di setUp() (urut versi menurun) dan memasangnya ulang di tearDown() (urut menaik), pola
 * yang sama dengan `WILAYAH_DEPENDENTS` di Batch1LegacySchemaTest; assertion test master tidak berubah.
 *
 * Dipakai bersama DatabaseTestTrait (`$this->db`, tabel `migrations` ber-prefix).
 */
trait LepasMigrationKepegawaianTrait
{
    /**
     * Migration B-01/B-02 yang sedang dilepas, urut lepas (versi menurun).
     *
     * @var list<Migration>
     */
    private array $migrationKepegawaianDilepas = [];

    /**
     * Lepas (down) semua migration App B-01/B-02 yang sudah jalan, urut versi menurun. Tanpa efek bila belum ada.
     */
    protected function lepasMigrationKepegawaian(): void
    {
        $rows = $this->db->table('migrations')->select('version')->where('namespace', 'App')
            ->where('version >=', '2026-09-30-120000')->orderBy('version', 'DESC')->get()->getResultArray();

        foreach ($rows as $row) {
            $migration = $this->migrationKepegawaian((string) $row['version']);
            $migration->down();
            $this->migrationKepegawaianDilepas[] = $migration;
        }
    }

    /**
     * Pasang ulang (up) migration yang dilepas, urut versi menaik, agar skema kembali sesuai tabel migrations.
     */
    protected function pasangUlangMigrationKepegawaian(): void
    {
        foreach (array_reverse($this->migrationKepegawaianDilepas) as $migration) {
            $migration->up();
        }

        $this->migrationKepegawaianDilepas = [];
    }

    private function migrationKepegawaian(string $version): Migration
    {
        $files = glob(APPPATH . 'Database/Migrations/' . $version . '_*.php') ?: [];

        if (count($files) !== 1) {
            throw new RuntimeException("File migration {$version} tidak ditemukan (atau lebih dari satu).");
        }

        require_once $files[0];
        $class = 'App\\Database\\Migrations\\' . substr(basename($files[0], '.php'), strlen($version) + 1);

        /** @var Migration $migration */
        $migration = new $class();

        return $migration;
    }
}
