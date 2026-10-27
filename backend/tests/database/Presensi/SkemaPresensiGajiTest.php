<?php

declare(strict_types=1);

namespace Tests\Database\Presensi;

use App\Database\Migrations\CreatePresensiGaji;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SkemaPresensiGaji;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-023 M2 — skema Fase 5A Presensi & Slip Gaji (backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md,
 * disetujui DB Validator 20-10-2026): migration `2026-10-27-100000_CreatePresensiGaji` dan
 * `2026-10-27-100100_AlterAuditLogsEventPresensi`. AC-D01.1–D01.9 (PRD 5A §7.1.5):
 *   - 3 tabel InnoDB `utf8mb4_unicode_ci` (semua kolom teks);
 *   - 4 FK, seluruhnya RESTRICT/RESTRICT; `gaji_pegawai.nip` TANPA FK [K];
 *   - 5 CHECK bernama (`chk_*`); MariaDB menambah CHECK `json_valid` implisit di tabel lain (catatan DBV C-1), tidak di
 *     tabel ini;
 *   - kolom & index kunci: PK `(nip, tgl)`, DECIMAL(10,7)/(10,2), `sumber`, 28 kolom nominal = `KolomGaji`, 15 kolom
 *     generik D1 tidak ada, UNIQUE `(nip, bulan, tahun)` dan `uk_qr_token`, `qr_token_enkripsi` VARBINARY(255);
 *   - ENUM `audit_logs.event` 7 nilai;
 *   - constraint ditegakkan: 1062, 1452, 1451, CHECK 3819/4025; `sumber` NULL (impor legacy) dan banyak `qr_token` NULL sah.
 *
 * @internal
 */
final class SkemaPresensiGajiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    /** 15 kolom generik `gaji_pegawai` D1:1242-1273 yang dibuang (F5-2, dokumen Bagian 3.1). */
    private const KOLOM_GENERIK_D1 = [
        'gaji_pokok', 'tunj_istri_suami', 'tunj_anak', 'tunj_umum', 'tunj_struktural', 'tunj_fungsional', 'tunj_lainlain',
        'pembulatan', 'tunj_beras', 'tunj_khusus_pajak', 'pot_iuran_wajib', 'pot_bpjs_kesehatan', 'pot_pph', 'pot_taperum',
        'tunj_kinerja',
    ];

    public function testTabelFkDanCheckSesuaiDokumen(): void
    {
        foreach (SkemaPresensiGaji::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), "tabel {$tabel}");
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        [$fk, , $bukanRestrict] = $this->skemaForeignKeys(SkemaPresensiGaji::TABEL, []);
        $this->assertSame(SkemaPresensiGaji::FK, $fk, 'FK DBV-023 (nama, kolom, induk)');
        $this->assertSame([], $bukanRestrict, 'semua FK DBV-023 RESTRICT/RESTRICT');

        $check = $this->skemaChecks(SkemaPresensiGaji::TABEL);
        $this->assertSame(SkemaPresensiGaji::CHECK, array_map(static fn (array $c): string => $c[0], $check));
        $this->assertSame('sumberin1,2', $check['chk_dh_online_sumber'][1]);
        $this->assertSame('jam_masukisnotnullorjam_pulangisnotnull', $check['chk_dh_online_jam'][1]);
        $this->assertSame('bulanbetween1and12', $check['chk_gaji_pegawai_bulan'][1]);
        $this->assertSame('tahunbetween1900and2100', $check['chk_gaji_pegawai_tahun'][1]);

        foreach ([...array_keys(CreatePresensiGaji::KOLOM_NOMINAL), 'uang_makan'] as $kolom) {
            $this->assertStringContainsString("{$kolom}>=0", $check['chk_gaji_pegawai_nominal'][1], "CHECK nominal {$kolom}");
        }
    }

    public function testKolomDanIndexDhOnline(): void
    {
        $kolom = $this->skemaColumns('dh_online');
        $this->assertSame([
            'nip'              => 'varchar(30)',
            'tgl'              => 'date',
            'jam_masuk'        => 'time null',
            'jam_pulang'       => 'time null',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime null',
            'updated_by'       => 'int null',
            'lat_masuk'        => 'decimal(10,7) null',
            'lng_masuk'        => 'decimal(10,7) null',
            'akurasi_masuk'    => 'decimal(10,2) null',
            'id_lokasi_masuk'  => 'int null',
            'lat_pulang'       => 'decimal(10,7) null',
            'lng_pulang'       => 'decimal(10,7) null',
            'akurasi_pulang'   => 'decimal(10,2) null',
            'id_lokasi_pulang' => 'int null',
            'sumber'           => 'tinyint null',
            'created_by'       => 'int null',
        ], $kolom);

        [$pk, $index, $unique] = $this->skemaIndexes('dh_online');
        $this->assertSame(['nip', 'tgl'], $pk, 'PK komposit [K] (DBV-Q1)');
        $this->assertSame(['tgl'], $index['tgl'] ?? null);
        $this->assertSame([], $unique);
    }

    public function testKolomDanIndexGajiPegawai(): void
    {
        $kolom = $this->skemaColumns('gaji_pegawai');
        $this->assertSame('bigint unsigned', $kolom['id']);
        $this->assertSame('varchar(30)', $kolom['nip']);
        $this->assertSame('tinyint unsigned', $kolom['bulan']);
        $this->assertSame('smallint unsigned', $kolom['tahun']);
        $this->assertSame('varchar(64) null', $kolom['qr_token']);
        $this->assertSame('varbinary(255) null', $kolom['qr_token_enkripsi'], 'DBV-Q17');
        $this->assertSame('decimal(14,2)', $kolom['uang_makan']);
        $this->assertSame('smallint unsigned null', $kolom['uang_makan_hari']);
        $this->assertSame('varchar(7) null', $kolom['uang_makan_periode']);

        $nominal = array_keys(CreatePresensiGaji::KOLOM_NOMINAL);
        $this->assertEqualsCanonicalizing(KolomGaji::semuaField(), $nominal, '28 kolom = KolomGaji');

        foreach ($nominal as $field) {
            $this->assertSame('decimal(14,2)', $kolom[$field] ?? null, "gaji_pegawai.{$field}");
        }

        foreach (self::KOLOM_GENERIK_D1 as $generik) {
            $this->assertArrayNotHasKey($generik, $kolom, "kolom generik D1 {$generik} dibuang (F5-2)");
        }

        [$pk, $index, $unique] = $this->skemaIndexes('gaji_pegawai');
        $this->assertSame(['id'], $pk);
        $this->assertSame(['nip', 'bulan', 'tahun'], $index['uk_nip_bulan_tahun'] ?? null);
        $this->assertSame(['qr_token'], $index['uk_qr_token'] ?? null);
        $this->assertSame(['uk_nip_bulan_tahun', 'uk_qr_token'], $unique);
        $this->assertArrayHasKey('idx_nip', $index);

        $riwayat = $this->skemaColumns('gaji_pegawai_qr_history');
        $this->assertSame('bigint unsigned', $riwayat['id_gaji_pegawai']);
        $this->assertSame('varchar(64)', $riwayat['qr_token']);
        $this->assertSame('varchar(255) null', $riwayat['snapshot_satker']);
        $this->assertSame('varchar(255) null', $riwayat['snapshot_nama']);
        $this->assertArrayNotHasKey('nip', $riwayat, 'tanpa kolom NIP → tidak masuk registry NIP');
        $this->assertSame(['uk_gpqh_qr_token'], $this->skemaIndexes('gaji_pegawai_qr_history')[2]);
    }

    public function testEnumAuditEvent(): void
    {
        $tipe = (string) $this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('audit_logs'), 'event'],
        )->getRowArray()['COLUMN_TYPE'];

        $this->assertSame("enum('" . implode("','", SkemaPresensiGaji::ENUM_EVENT) . "')", strtolower($tipe));

        $this->db->table('audit_logs')->insert(['entity' => 'gaji_pegawai', 'entity_id' => 'impor', 'event' => 'import', 'created_at' => '2026-10-27 00:00:00']);
        $this->db->table('audit_logs')->insert(['entity' => 'gaji_pegawai', 'entity_id' => 'pratinjau', 'event' => 'warning', 'created_at' => '2026-10-27 00:00:00']);
        $this->seeInDatabase('audit_logs', ['event' => 'import', 'entity_id' => 'impor']);
        $this->assertDbError([1265], fn () => $this->db->table('audit_logs')->insert(['entity' => 'x', 'entity_id' => 'x', 'event' => 'lain', 'created_at' => '2026-10-27 00:00:00']));
    }

    /**
     * Lapis DB (DBV-023 Bagian 10.2 / komentar DB Validator 1.1): PK ganda 1062, NIP/lokasi yatim 1452, hapus induk
     * terpakai 1451, CHECK 3819 (MySQL) / 4025 (MariaDB).
     */
    public function testConstraintDhOnline(): void
    {
        $nip    = $this->buatPegawai();
        $lokasi = $this->isi('lokasi_presensi', []);

        $this->isi('dh_online', ['nip' => $nip, 'tgl' => '2026-10-01', 'jam_masuk' => '07:30:00', 'sumber' => 1, 'lat_masuk' => -6.1753924, 'lng_masuk' => 106.8271528, 'akurasi_masuk' => 12.5, 'id_lokasi_masuk' => $lokasi]);
        $this->isi('dh_online', ['nip' => $nip, 'tgl' => '2026-10-02', 'jam_pulang' => '16:00:00', 'sumber' => null]);
        $this->seeInDatabase('dh_online', ['nip' => $nip, 'tgl' => '2026-10-02', 'sumber' => null]);
        $this->seeInDatabase('dh_online', ['nip' => $nip, 'tgl' => '2026-10-01', 'lat_masuk' => '-6.1753924']);

        $this->assertDbError([1062], fn () => $this->isi('dh_online', ['nip' => $nip, 'tgl' => '2026-10-01', 'jam_masuk' => '08:00:00']));
        $this->assertDbError([1452], fn () => $this->isi('dh_online', ['nip' => '199912312099121001', 'tgl' => '2026-10-03', 'jam_masuk' => '08:00:00']));
        $this->assertDbError([1452], fn () => $this->isi('dh_online', ['nip' => $nip, 'tgl' => '2026-10-03', 'jam_masuk' => '08:00:00', 'id_lokasi_pulang' => 99_999]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('dh_online', ['nip' => $nip, 'tgl' => '2026-10-04', 'jam_masuk' => '08:00:00', 'sumber' => 3]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('dh_online', ['nip' => $nip, 'tgl' => '2026-10-05']));

        $this->assertDbError([1451], fn () => $this->db->table('lokasi_presensi')->where('id_lokasi_presensi', $lokasi)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());
    }

    public function testConstraintGajiPegawai(): void
    {
        $slip = $this->isi('gaji_pegawai', ['nip' => '199001012020121001', 'bulan' => 9, 'tahun' => 2026, 'gjpokok' => 4_500_000.01]);
        $this->seeInDatabase('gaji_pegawai', ['id' => $slip, 'gjpokok' => '4500000.01', 'uang_makan' => '0.00']);
        $this->isi('gaji_pegawai', ['nip' => '199001012020121001', 'bulan' => 10, 'tahun' => 2026]);
        $this->isi('gaji_pegawai', ['nip' => '199001012020121002', 'bulan' => 10, 'tahun' => 2026]);
        $this->assertSame(3, $this->db->table('gaji_pegawai')->where('qr_token', null)->countAllResults(), 'banyak qr_token NULL sah');

        $this->assertDbError([1062], fn () => $this->isi('gaji_pegawai', ['nip' => '199001012020121001', 'bulan' => 9, 'tahun' => 2026]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('gaji_pegawai', ['nip' => '199001012020121003', 'bulan' => 13, 'tahun' => 2026]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('gaji_pegawai', ['nip' => '199001012020121003', 'bulan' => 1, 'tahun' => 1800]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('gaji_pegawai', ['nip' => '199001012020121003', 'bulan' => 1, 'tahun' => 2026, 'potlain' => -1]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('gaji_pegawai', ['nip' => '199001012020121003', 'bulan' => 2, 'tahun' => 2026, 'uang_makan' => -0.01]));

        $hash = str_repeat('a', 64);
        $this->db->table('gaji_pegawai')->where('id', $slip)->update(['qr_token' => $hash, 'qr_token_enkripsi' => random_bytes(144)]);
        $this->assertDbError([1062], fn () => $this->db->table('gaji_pegawai')->where('id !=', $slip)->limit(1)->update(['qr_token' => $hash]));

        $this->isi('gaji_pegawai_qr_history', ['id_gaji_pegawai' => $slip, 'qr_token' => $hash]);
        $this->assertDbError([1062], fn () => $this->isi('gaji_pegawai_qr_history', ['id_gaji_pegawai' => $slip, 'qr_token' => $hash]));
        $this->assertDbError([1452], fn () => $this->isi('gaji_pegawai_qr_history', ['id_gaji_pegawai' => 999_999_999, 'qr_token' => str_repeat('c', 64)]));
        $this->assertDbError([1451], fn () => $this->db->table('gaji_pegawai')->where('id', $slip)->delete());
    }

    /**
     * Baris uji dengan kolom wajib diisi otomatis (pola SkemaLayananTest::isi); $isi menimpa.
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
                'time'                                                                            => '07:30:00',
                'year'                                                                            => 2026,
                'int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'double', 'float' => 1,
                default                                                                           => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        $this->db->table($tabel)->insert(array_merge($baris, $isi));

        return (int) $this->db->insertID();
    }
}
