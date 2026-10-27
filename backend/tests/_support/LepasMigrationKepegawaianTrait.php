<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\Migration;
use RuntimeException;
use Tests\Support\Layanan\SkemaLayanan;

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
 * DBV-019: FK snapshot/riwayat → master G-02, `jabatan_koordinasi`, `rumpun_jabatan` (migration 2026-10-07-100000 dan
 * 2026-10-07-100100) juga menahan down() migration master G-02 (DBV-008/DBV-018). Test skema G-02 cukup melepas kedua
 * migration FK itu lewat lepasFkG02() (tabel B-01/B-02 tetap), lalu pasangUlangMigrationKepegawaian().
 *
 * DBV-020: `dm_ak_jf` (migration 2026-10-13-100100) merujuk `jabatan` dan `pangkat` dengan FK RESTRICT, jadi ikut dilepas
 * lepasFkG02() (tabel dibuang lalu dibuat ulang kosong) dan lepasMigrationKepegawaian() (versi >= 120000).
 *
 * DBV-022: tabel Modul C Layanan (migration >= 2026-10-26-100000, mis. `pa_layanan` -> `unit`/`satker`/`jabatan_koordinasi`)
 * merujuk master G-02 dengan FK RESTRICT, jadi ikut dilepas lepasFkG02() (tabel dibuang lalu dibuat ulang kosong, seed
 * master layanan dipasang ulang) dan lepasMigrationKepegawaian() (versi >= 120000).
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
     * Migration yang memasang FK ke tabel master G-02 (urut versi menaik): DBV-019 (snapshot/riwayat) dan DBV-020
     * (`dm_ak_jf` → `jabatan`).
     *
     * @var list<string>
     */
    private static array $versiFkG02 = ['2026-10-07-100000', '2026-10-07-100100', '2026-10-13-100100'];

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
     * Lepas (down) hanya migration yang ber-FK ke master G-02 (DBV-019, DBV-020 `dm_ak_jf`, DBV-022 Modul C) yang sudah jalan, urut versi
     * menurun. down() migration itu aman diulang (FK/tabel yang sudah lepas dilewati).
     */
    protected function lepasFkG02(): void
    {
        $rows = $this->db->table('migrations')->select('version')->where('namespace', 'App')
            ->groupStart()->whereIn('version', self::$versiFkG02)->orWhere('version >=', SkemaLayanan::VERSI_PERTAMA)->groupEnd()
            ->orderBy('version', 'DESC')->get()->getResultArray();

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
