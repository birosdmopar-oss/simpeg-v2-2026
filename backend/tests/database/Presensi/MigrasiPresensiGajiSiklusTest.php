<?php

declare(strict_types=1);

namespace Tests\Database\Presensi;

use App\Database\Migrations\AlterAuditLogsEventPresensi;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\SkemaPresensiGaji;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-023 M2 — siklus migration Fase 5A (dokumen Bagian 10/11, AC-D01.4): down() kedua migration `2026-10-27-100000..100100`
 * melepas 3 tabel dan mengembalikan ENUM `audit_logs.event` ke 5 nilai, up() memasangnya lagi identik; down() ENUM
 * fail-closed — bila ada baris `warning`/`import` rollback DITOLAK tanpa menghapus baris audit dan tanpa rollback
 * separuh jalan (keputusan reviewer CR 20-10-2026, DBV-Q16). Migration draf tidak tersisa di `MigrationsDitahan/`.
 * DDL → di luar bingkai transaksi uji (`db-isolasi-penuh`).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class MigrasiPresensiGajiSiklusTest extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            $this->db->table('audit_logs')->whereIn('event', AlterAuditLogsEventPresensi::NILAI_BARU)->delete();
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testDownLaluUpKembaliIdentik(): void
    {
        foreach (['CreatePresensiGaji', 'AlterAuditLogsEventPresensi'] as $draf) {
            $this->assertFileDoesNotExist(APPPATH . "Database/MigrationsDitahan/{$draf}.php", 'draf DBV-023 sudah dipindah ke Migrations');
        }

        $sebelum = $this->sidikSkema();
        $this->assertSame(SkemaPresensiGaji::ENUM_EVENT, $this->nilaiEnum());

        $this->lepasSejak(SkemaPresensiGaji::VERSI_PERTAMA);

        foreach (SkemaPresensiGaji::TABEL as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), "{$tabel} terhapus oleh down()");
        }

        $this->assertSame(AlterAuditLogsEventPresensi::NILAI_LAMA, $this->nilaiEnum(), 'ENUM kembali 5 nilai');

        $this->pasangUlang();

        $this->assertSame($sebelum, $this->sidikSkema(), 'struktur identik setelah migrate ulang');
        $this->assertSame(SkemaPresensiGaji::ENUM_EVENT, $this->nilaiEnum());
    }

    public function testDownEnumFailClosedBilaAdaBarisBaru(): void
    {
        $this->db->table('audit_logs')->insert(['entity' => 'gaji_pegawai', 'entity_id' => 'impor', 'event' => 'import', 'created_at' => '2026-10-27 00:00:00']);
        $sebelum = $this->sidikSkema();

        try {
            $this->lepasSejak(SkemaPresensiGaji::VERSI_ENUM);
            $this->fail('down() ENUM harus ditolak selama ada baris import/warning');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rollback ENUM audit_logs.event ditolak', $e->getMessage());
            $this->assertStringContainsString('1 baris', $e->getMessage());
        }

        $this->assertSame(SkemaPresensiGaji::ENUM_EVENT, $this->nilaiEnum(), 'ENUM tetap 7 nilai');
        $this->assertSame(1, $this->db->table('audit_logs')->where('event', 'import')->countAllResults(), 'baris audit tidak dihapus');
        $this->assertSame($sebelum, $this->sidikSkema(), 'tabel DBV-023 utuh, tanpa rollback separuh jalan');
    }

    /**
     * @phpstan-impure
     *
     * @return list<string>
     */
    private function nilaiEnum(): array
    {
        $tipe = (string) $this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('audit_logs'), 'event'],
        )->getRowArray()['COLUMN_TYPE'];

        preg_match_all("/'([^']*)'/", $tipe, $m);

        return $m[1];
    }

    /**
     * Sidik struktur 3 tabel: kolom, default, index, FK, CHECK.
     *
     * @phpstan-impure
     *
     * @return array<string, mixed>
     */
    private function sidikSkema(): array
    {
        $sidik = [];

        foreach (SkemaPresensiGaji::TABEL as $tabel) {
            $sidik[$tabel] = [$this->skemaColumns($tabel), $this->skemaDefaults($tabel), $this->skemaIndexes($tabel), $this->skemaTableInfo($tabel)];
        }

        $sidik['_fk']    = $this->skemaForeignKeys(SkemaPresensiGaji::TABEL, [])[0];
        $sidik['_check'] = $this->skemaChecks(SkemaPresensiGaji::TABEL);

        return $sidik;
    }
}
