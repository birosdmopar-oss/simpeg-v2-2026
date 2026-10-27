<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use App\Database\Migrations\AddFkNipLayananFase4;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Layanan\SkemaLayanan;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-022 M2 — siklus migration Modul C (dokumen Bagian 8, AC-01.4): down() kesembilan migration `2026-10-26-100000..100800`
 * melepas 20 tabel dan seed `jenis_rwy` 43..50, up() memasangnya lagi identik; migration FK NIP yang DITAHAN
 * (`app/Database/MigrationsDitahan/AddFkNipLayananFase4.php`, dokumen Bagian 9) tidak ada di folder Migrations, menolak
 * jalan (fail-closed, sebelum ALTER apa pun) bila ada NIP approver/petugas yatim, lalu memasang 5 FK RESTRICT setelah data
 * dibereskan; up()/down() aman diulang. DDL → di luar bingkai transaksi uji (`db-isolasi-penuh`).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class MigrasiLayananSiklusTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    private const VERSI_PERTAMA = SkemaLayanan::VERSI_PERTAMA;

    private const NIP_YATIM = '199912312099121001';

    private ?AddFkNipLayananFase4 $fkDitahan = null;

    protected function tearDown(): void
    {
        if ($this->fkDitahan !== null) {
            $this->fkDitahan->down();
            $this->fkDitahan = null;
        }

        if ($this->adaYangDilepas()) {
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testDownLaluUpKembaliIdentik(): void
    {
        $sebelum = $this->sidikSkema();
        $this->assertSame(8, $this->db->table('jenis_rwy')->where('id_jenis_rwy >=', 43)->countAllResults());

        $this->lepasSejak(self::VERSI_PERTAMA);

        foreach (SkemaLayanan::TABEL as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), "{$tabel} terhapus oleh down()");
        }

        $this->assertSame(0, $this->db->table('jenis_rwy')->where('id_jenis_rwy >=', 43)->countAllResults(), 'seed 43..50 dilepas');

        $this->pasangUlang();

        $this->assertSame($sebelum, $this->sidikSkema(), 'struktur identik setelah migrate ulang');
        $this->assertSame(8, $this->db->table('jenis_rwy')->where('id_jenis_rwy >=', 43)->countAllResults());
    }

    public function testFkNipDitahanFailClosedLaluTerpasang(): void
    {
        $this->assertSame([], glob(APPPATH . 'Database/Migrations/*_AddFkNipLayananFase4.php') ?: [], 'migration FK ditahan tidak boleh ada di folder Migrations');
        $this->assertSame([], $this->fkDitahan(), 'FK ditahan belum terpasang');

        $pemohon = $this->buatPegawai();
        $this->db->table('riwayat_cuti')->insert($this->barisCuti($pemohon, ['nip_atasan_langsung' => self::NIP_YATIM]));
        $idCuti = (int) $this->db->insertID();

        $this->fkDitahan = $this->fkDitahanInstance();

        try {
            $this->fkDitahan->up();
            $this->fail('up() harus ditolak karena NIP atasan langsung yatim');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('riwayat_cuti.nip_atasan_langsung', $e->getMessage());
            $this->assertStringContainsString('DBV-022-audit-layanan.sql', $e->getMessage());
        }

        $this->assertSame([], $this->fkDitahan(), 'tidak ada FK terpasang sebagian');

        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $idCuti)->update(['nip_atasan_langsung' => null]);
        $this->fkDitahan->up();
        $this->fkDitahan->up();

        $fk = $this->fkDitahan();
        $this->assertEqualsCanonicalizing(SkemaLayanan::FK_DITAHAN, array_keys($fk), '5 FK NIP approver/petugas');

        foreach ($fk as $nama => [$update, $delete]) {
            $this->assertSame(['RESTRICT', 'RESTRICT'], [$update, $delete], $nama);
        }

        $this->assertDbError([1452], fn () => $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $idCuti)->update(['nip_yang_menyetujui' => self::NIP_YATIM]));

        $this->fkDitahan->down();
        $this->fkDitahan->down();
        $this->assertSame([], $this->fkDitahan(), 'down() melepas kelima FK');

        // Data uji dipulihkan DatabaseTestCase (db-isolasi-penuh); FK sudah dilepas.
        $this->fkDitahan = null;
    }

    /**
     * Kolom wajib `riwayat_cuti` (dokumen Bagian 2.1) untuk satu baris uji.
     *
     * @param array<string, mixed> $timpa
     *
     * @return array<string, mixed>
     */
    private function barisCuti(string $nip, array $timpa): array
    {
        $kolom = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('riwayat_cuti')],
        )->getResultArray();
        $baris = ['nip' => $nip, 'id_jenis_cuti' => 1];

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $baris) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                  => '2026-01-05',
                'datetime', 'timestamp' => '2026-01-05 00:00:00',
                'int', 'tinyint'        => 1,
                default                 => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        return array_merge($baris, $timpa);
    }

    /**
     * FK ditahan yang terpasang: nama => [UPDATE_RULE, DELETE_RULE].
     *
     * @phpstan-impure
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function fkDitahan(): array
    {
        $rows = $this->db->query(
            'SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME IN ? ORDER BY CONSTRAINT_NAME',
            [$this->db->getDatabase(), SkemaLayanan::FK_DITAHAN],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['CONSTRAINT_NAME']] = [(string) $row['UPDATE_RULE'], (string) $row['DELETE_RULE']];
        }

        return $out;
    }

    /**
     * Sidik struktur 20 tabel: kolom, default, index, FK, CHECK (untuk membandingkan sebelum/sesudah siklus).
     *
     * @return array<string, mixed>
     */
    private function sidikSkema(): array
    {
        $sidik = [];

        foreach (SkemaLayanan::TABEL as $tabel) {
            $sidik[$tabel] = [$this->skemaColumns($tabel), $this->skemaDefaults($tabel), $this->skemaIndexes($tabel)];
        }

        $sidik['_fk']    = $this->skemaForeignKeys(SkemaLayanan::TABEL, SkemaLayanan::FK_DITAHAN)[0];
        $sidik['_check'] = $this->skemaChecks(SkemaLayanan::TABEL);

        return $sidik;
    }

    private function fkDitahanInstance(): AddFkNipLayananFase4
    {
        $file = APPPATH . 'Database/MigrationsDitahan/AddFkNipLayananFase4.php';

        if (! is_file($file)) {
            throw new RuntimeException("File migration ditahan {$file} tidak ditemukan.");
        }

        require_once $file;

        return new AddFkNipLayananFase4();
    }
}
