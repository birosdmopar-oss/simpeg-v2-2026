<?php

declare(strict_types=1);

namespace Tests\Database\Presensi;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-024 M3 — skema Fase 5B `penyesuaian_tukin` (backend/docs/db-review/DBV-024-penyesuaian-tukin-schema.md, disetujui
 * DB Validator 28-10-2026): migration `2026-10-28-100000_CreatePenyesuaianTukin`. Diperiksa lewat information_schema
 * (kolom, tipe, NULL, default, PK, index, FK + aksi, CHECK, collation, engine), constraint ditegakkan (1452, 1451,
 * CHECK 3819 MySQL / 4025 MariaDB, termasuk nilai batas) dan siklus down()/up().
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaPenyesuaianTukinTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    private const VERSION = '2026-10-28-100000';

    private const TABEL = 'penyesuaian_tukin';

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testSkemaSesuaiDokumen(): void
    {
        $this->assertSkema();
    }

    public function testConstraintDitegakkanSampaiNilaiBatas(): void
    {
        $nip = $this->buatPegawai();

        $id = $this->isi($nip, '2026-01-01', '2026-01-01', -100.00);
        $this->isi($nip, '2026-02-01', '2026-12-01', 100.00);
        $this->isi($nip, '2027-01-01', '2027-03-01', -0.01);
        $this->seeInDatabase(self::TABEL, ['id_penyesuaian_tukin' => $id, 'persen' => '-100.00', 'status' => 1]);

        foreach ([10, 2] as $status) {
            $this->db->table(self::TABEL)->where('id_penyesuaian_tukin', $id)->update(['status' => $status]);
        }

        $this->assertDbError([1452], fn () => $this->isi('199912312099121001', '2026-01-01', '2026-01-01', -10.00));
        $this->assertDbError([3819, 4025], fn () => $this->isi($nip, '2026-01-02', '2026-02-01', -10.00));
        $this->assertDbError([3819, 4025], fn () => $this->isi($nip, '2026-01-01', '2026-02-15', -10.00));
        $this->assertDbError([3819, 4025], fn () => $this->isi($nip, '2026-03-01', '2026-02-01', -10.00));
        $this->assertDbError([3819, 4025], fn () => $this->isi($nip, '2026-01-01', '2026-01-01', 0.00));
        $this->assertDbError([3819, 4025], fn () => $this->isi($nip, '2026-01-01', '2026-01-01', -100.01));
        $this->assertDbError([3819, 4025], fn () => $this->isi($nip, '2026-01-01', '2026-01-01', 100.01));
        $this->assertDbError([3819, 4025], fn () => $this->db->table(self::TABEL)->where('id_penyesuaian_tukin', $id)->update(['status' => 3]));

        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());
    }

    public function testDownLaluUpKembaliSama(): void
    {
        $this->lepasSejak(self::VERSION);
        $this->assertFalse($this->skemaTableExists(self::TABEL), 'down() menghapus tabel');

        $this->pasangUlang();
        $this->assertSkema();
    }

    private function assertSkema(): void
    {
        $this->assertTrue($this->skemaTableExists(self::TABEL));
        $info = $this->skemaTableInfo(self::TABEL);
        $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null]);

        foreach ($this->skemaStringCollations(self::TABEL) as $kolom => $collation) {
            $this->assertSame('utf8mb4_unicode_ci', $collation, $kolom);
        }

        $this->assertSame([
            'id_penyesuaian_tukin' => 'int unsigned',
            'nip'                  => 'varchar(30)',
            'bulan_mulai'          => 'date',
            'bulan_akhir'          => 'date',
            'persen'               => 'decimal(5,2)',
            'alasan'               => 'varchar(255)',
            'status'               => 'tinyint',
            'created_at'           => 'datetime null',
            'updated_at'           => 'datetime null',
            'created_by'           => 'int null',
            'updated_by'           => 'int null',
        ], $this->skemaColumns(self::TABEL));

        [$pk, $index, $unique] = $this->skemaIndexes(self::TABEL);
        $this->assertSame(['id_penyesuaian_tukin'], $pk);
        $this->assertSame(['nip', 'status', 'bulan_mulai'], $index['idx_ptk_nip_status_bulan'] ?? null, 'DBV-Q4');
        $this->assertSame(['status', 'bulan_mulai', 'bulan_akhir'], $index['idx_ptk_status_bulan'] ?? null, 'DBV-Q4');
        $this->assertSame([], $unique, 'tumpang tindih dijaga aplikasi (C-1), bukan UNIQUE');

        [$fk, , $bukanRestrict] = $this->skemaForeignKeys([self::TABEL], []);
        $this->assertSame(['fk_nip_ptk_to_pegawai' => [self::TABEL, 'nip', 'pegawai', 'nip']], $fk);
        $this->assertSame([], $bukanRestrict, 'FK RESTRICT/RESTRICT');

        $check = $this->skemaChecks([self::TABEL]);
        $this->assertSame(
            ['chk_penyesuaian_tukin_bulan', 'chk_penyesuaian_tukin_persen', 'chk_penyesuaian_tukin_status'],
            array_keys($check),
        );
    }

    private function isi(string $nip, string $mulai, string $akhir, float $persen): int
    {
        $this->db->table(self::TABEL)->insert([
            'nip' => $nip, 'bulan_mulai' => $mulai, 'bulan_akhir' => $akhir, 'persen' => $persen, 'alasan' => 'Uji penyesuaian',
        ]);

        return (int) $this->db->insertID();
    }
}
