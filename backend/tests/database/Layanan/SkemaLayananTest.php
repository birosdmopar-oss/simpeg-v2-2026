<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Layanan\SkemaLayanan;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-022 M2 — skema Modul C Layanan Fase 4 (backend/docs/db-review/DBV-022-layanan-fase4-schema.md, disetujui DB Validator
 * 27-10-2026): 9 migration `2026-10-26-100000..100800`.
 *   - 20 tabel InnoDB `utf8mb4_unicode_ci` (termasuk semua kolom teks);
 *   - 31 FK, seluruhnya RESTRICT/RESTRICT (K1); 5 FK NIP approver/petugas DITAHAN (`AddFkNipLayananFase4`, tidak ada di
 *     skema — diuji MigrasiLayananSiklusTest);
 *   - 39 CHECK (nama & klausa domain utama);
 *   - kolom kunci (NIP VARCHAR(30), `ketua_tim` TEXT, `lama_tahun` DECIMAL(10,2)), UNIQUE `(nip, tahun)` saldo;
 *   - seed `jenis_cuti` 1..6 (Tukin meng-hard-code id), `jenis_layanan` 1..5, `jenis_rwy` 43..50;
 *   - constraint ditegakkan: 1452 (NIP/induk yatim), 1451 (hapus induk), 1062 (UNIQUE), CHECK 3819/4025.
 * Jumlah CHECK MariaDB = MySQL + 3 (CHECK `json_valid` implisit kolom JSON, catatan DBV C-1): test ini hanya memeriksa
 * CHECK bernama DBV-022, jadi sama di kedua engine.
 *
 * @internal
 */
final class SkemaLayananTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    public function testTabelFkDanCheckSesuaiDokumen(): void
    {
        $this->assertCount(20, SkemaLayanan::TABEL);
        $this->assertCount(31, SkemaLayanan::FK);
        $this->assertCount(39, SkemaLayanan::CHECK);

        foreach (SkemaLayanan::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), "tabel {$tabel}");
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        [$fk, $ditahan, $bukanRestrict] = $this->skemaForeignKeys(SkemaLayanan::TABEL, SkemaLayanan::FK_DITAHAN);
        $harapan                        = SkemaLayanan::FK;
        ksort($harapan, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($harapan, $fk, 'FK DBV-022 (nama [K]/[V2], kolom, induk)');
        $this->assertSame([], $ditahan, 'FK NIP approver/petugas masih ditahan (AddFkNipLayananFase4)');
        $this->assertSame([], $bukanRestrict, 'semua FK Modul C RESTRICT/RESTRICT (K1)');

        $check        = array_map(static fn (array $c): string => $c[0], $this->skemaChecks(SkemaLayanan::TABEL));
        $harapanCheck = SkemaLayanan::CHECK;
        ksort($harapanCheck, SORT_STRING);
        // MariaDB menambah CHECK json_valid implisit (nama = kolom JSON) — hanya CHECK bernama chk_* yang dibandingkan.
        $this->assertSame($harapanCheck, array_filter($check, static fn (string $nama): bool => str_starts_with($nama, 'chk_'), ARRAY_FILTER_USE_KEY));

        $klausa = $this->skemaChecks(['riwayat_cuti', 'pegawai_sisa_cuti', 'riwayat_tb', 'riwayat_pmk_req']);
        $this->assertSame('statusin0,1,2,3,4', $klausa['chk_rwycuti_status'][1]);
        $this->assertSame('statusin0,1,2,3', $klausa['chk_rwytb_status'][1]);
        $this->assertSame('statusin0,1,2,3', $klausa['chk_rwpmkreq_status'][1]);
        $this->assertStringContainsString('sisa_cuti>=0', $klausa['chk_psc_saldo_nonnegatif'][1]);
        $this->assertStringContainsString('utang_cuti>=0', $klausa['chk_psc_saldo_nonnegatif'][1]);
    }

    /**
     * Kolom NIP Modul C VARCHAR(30) (V4, = `pegawai.nip`), `ketua_tim` TEXT multi-NIP (DQ-15), `lama_tahun` DECIMAL(10,2),
     * UNIQUE `(nip, tahun)` saldo (DQ-10), index hot path approval (DQ-16).
     */
    public function testKolomKunciDanIndex(): void
    {
        $cuti = $this->skemaColumns('riwayat_cuti');
        $this->assertSame('varchar(30)', $cuti['nip']);
        $this->assertSame('varchar(30) null', $cuti['nip_atasan_langsung']);
        $this->assertSame('varchar(30) null', $cuti['nip_yang_menyetujui']);
        $this->assertSame('text null', $cuti['ketua_tim']);

        foreach ([
            'pegawai_sisa_cuti' => 'nip', 'riwayat_cuti_notif_kt' => 'nip_kt', 'riwayat_tb' => 'nip', 'riwayat_ib' => 'nip',
            'riwayat_pmk'       => 'nip', 'riwayat_pmk_req' => 'nip', 'inbox' => 'nip_penerima', 'disiplin_ticket' => 'nip',
            'petugas_layanan'   => 'nip', 'firebase_token' => 'nip',
        ] as $tabel => $kolom) {
            $this->assertSame('varchar(30)', $this->skemaColumns($tabel)[$kolom], "{$tabel}.{$kolom}");
        }

        $this->assertSame('varchar(30) null', $this->skemaColumns('pa_layanan')['nip']);
        $this->assertSame('varchar(30) null', $this->skemaColumns('esign_hist')['nip']);
        $this->assertSame('decimal(10,2)', $this->skemaColumns('riwayat_tb')['lama_tahun']);
        $this->assertSame('decimal(10,2)', $this->skemaColumns('riwayat_ib')['lama_tahun']);

        [, , $unique] = $this->skemaIndexes('pegawai_sisa_cuti');
        $this->assertContains('nip_tahun', $unique);
        $this->assertSame(['nip', 'tahun'], $this->skemaIndexes('pegawai_sisa_cuti')[1]['nip_tahun'] ?? null);

        $indexCuti = $this->skemaIndexes('riwayat_cuti')[1];
        $this->assertArrayHasKey('nip_atasan_langsung_status', $indexCuti);
        $this->assertArrayHasKey('nip_yang_menyetujui_status', $indexCuti);
        $this->assertSame(['id_riwayat_cuti', 'nip_kt'], $this->skemaIndexes('riwayat_cuti_notif_kt')[0], 'PK komposit notif_kt');
    }

    public function testSeedMaster(): void
    {
        $id = fn (string $tabel, string $pk): array => array_map('intval', array_column($this->db->table($tabel)->select($pk)->orderBy($pk)->get()->getResultArray(), $pk));

        $this->assertSame([1, 2, 3, 4, 5, 6], $id('jenis_cuti', 'id_jenis_cuti'), 'id tetap = StatusHarian::JENIS_CUTI (Tukin)');
        $this->assertSame([1, 2, 3, 4, 5], $id('jenis_layanan', 'id_jenis_layanan'));

        $rwy = $this->db->table('jenis_rwy')->select('id_jenis_rwy, kode')->where('id_jenis_rwy >=', 43)->orderBy('id_jenis_rwy')->get()->getResultArray();
        $this->assertSame(SkemaLayanan::JENIS_RWY, array_map('intval', array_column($rwy, 'id_jenis_rwy')));
        $this->assertSame('cuti', $rwy[0]['kode']);
        $this->assertSame('surat_ib', $rwy[7]['kode']);
        $this->assertSame(8, $this->db->table('jenis_rwy')->where('id_jenis_rwy >=', 43)->countAllResults(), 'tidak ada kode > 50');
    }

    /**
     * Lapis DB: FK menolak NIP/induk yatim (1452) dan hapus induk terpakai (1451), UNIQUE saldo (1062), CHECK status cuti
     * 0..4 dan saldo ≥ 0 (3819 MySQL / 4025 MariaDB).
     */
    public function testConstraintDitegakkan(): void
    {
        $nip = $this->buatPegawai();

        $this->assertDbError([1452], fn () => $this->isi('riwayat_cuti', ['nip' => '199912312099121001', 'id_jenis_cuti' => 1]));
        $this->assertDbError([1452], fn () => $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 99]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 1, 'status' => 5]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 1, 'status' => 10]));

        $idCuti = $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 1, 'status' => 4, 'ketua_tim' => "{$nip}***{$nip}"]);
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $idCuti, 'status' => 4, 'nip_atasan_langsung' => null]);
        $this->assertDbError([1451], fn () => $this->db->table('jenis_cuti')->where('id_jenis_cuti', 1)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());

        $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 9]);
        $this->assertDbError([1062], fn () => $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2026]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2027, 'sisa_cuti' => -1]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2028, 'utang_cuti' => -1]));
        // Batas atas D8 (N-1 ≤ 6) tidak di-CHECK: 303 baris legacy 2026 N-1 > 6 diimpor apa adanya (PRD v1.3 DQ-23/DQ-10).
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2026, 'sisa_cuti_n1' => 9]);

        $this->assertDbError([1452], fn () => $this->isi('inbox', ['nip_penerima' => '199912312099121001', 'modul' => 'cuti']));
        $this->assertDbError([3819, 4025], fn () => $this->isi('inbox', ['nip_penerima' => $nip, 'modul' => 'tiket']));
        $this->assertDbError([1452], fn () => $this->isi('riwayat_tb', ['nip' => $nip, 'id_jenjang_pendidikan' => 99_999]));
    }

    /**
     * Baris uji dengan kolom wajib diisi otomatis (pola NipCascadeTest::sisip); $isi menimpa.
     *
     * @param array<string, mixed> $isi
     */
    private function isi(string $tabel, array $isi): int
    {
        $kolom = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($tabel)],
        )->getResultArray();
        $baris = [];

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $isi) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                                                                            => '2026-01-05',
                'datetime', 'timestamp'                                                           => '2026-01-05 00:00:00',
                'year'                                                                            => 2026,
                'int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'double', 'float' => 1,
                default                                                                           => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        $this->db->table($tabel)->insert(array_merge($baris, $isi));

        return (int) $this->db->insertID();
    }
}
