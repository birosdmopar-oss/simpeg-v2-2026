<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-031 — test skema koreksi D1: tipe `simapi_ch` (P8/P9/P12), tabel `simapi_update`/`simapi_update_hist`, seed
 * `simapi_jd` 14 baris id legacy, constraint (CHECK last_action, FK RESTRICT, PK ganda), pra-cek ALTER gagal jelas tanpa
 * mengubah data, dan siklus down()/up(). Dokumen: backend/docs/db-review/DBV-031-simapi-d1.md.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaSimapiD1Test extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    private const VERSI_ALTER = '2026-11-09-100000';

    private const VERSI_SEED = '2026-11-09-100200';

    private const CHECK = [3819, 4025];

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testTipeKolomSimapiChIkutD1(): void
    {
        $c = $this->skemaColumns('simapi_ch');

        $this->assertSame(['int null', 'varchar(30)', 'mediumtext null', 'longtext null'], [$c['id_rwy'], $c['nip'], $c['field_name'], $c['field_value']]);
    }

    public function testTabelSimapiUpdateDanCollation(): void
    {
        foreach (['simapi_update', 'simapi_update_hist'] as $tabel) {
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        $u = $this->skemaColumns('simapi_update');
        $this->assertSame(['varchar(30)', 'varchar(30)', 'datetime', 'mediumtext null'], [$u['nip'], $u['last_action'], $u['last_update'], $u['nilai_baru']]);
    }

    public function testSeedSimapiJdIdLegacy(): void
    {
        $isi = array_column($this->db->table('simapi_jd')->orderBy('id_simapi_jd')->get()->getResultArray(), 'jenis_data', 'id_simapi_jd');

        $this->assertSame([1 => 'Data Umum', 2 => 'Alamat', 3 => 'Keluarga', 4 => 'Pendidikan', 5 => 'Pelatihan', 6 => 'Kursus Seminar', 7 => 'Organisasi', 8 => 'SKP', 9 => 'Pangkat', 10 => 'Jabatan', 11 => 'KGB', 12 => 'Tanda Jasa', 13 => 'Hukuman Disiplin', 14 => 'Anak'], $isi);
    }

    public function testConstraintSimapiUpdate(): void
    {
        $upd = fn (array $lebih = []) => $this->db->table('simapi_update')->insert($lebih + ['nip' => '000000000000000001', 'last_action' => 'UPDATE', 'last_update' => '2026-11-09 00:00:00', 'nama_field' => 'a|b', 'nilai_lama' => '1|2', 'nilai_baru' => '1|3']);

        $upd();
        $id = (int) $this->db->insertID();
        $this->assertDbError(self::CHECK, fn () => $upd(['last_action' => 'UPSERT']));
        $this->assertDbError([1048], fn () => $upd(['nip' => null]));

        $this->db->table('klien_layanan')->insert(['nama' => 'Uji', 'username' => 'pusdatin', 'rahasia_hash' => password_hash('x', PASSWORD_DEFAULT), 'jenis' => 1, 'hak' => 'simapi']);
        $klien = (int) $this->db->insertID();
        $hist  = fn (int $k, int $u) => $this->db->table('simapi_update_hist')->insert(['id_klien' => $k, 'id_simapi_update' => $u]);

        $hist($klien, $id);
        $this->assertDbError([1062], fn () => $hist($klien, $id));
        $this->assertDbError([1452], fn () => $hist($klien, 999999));
        $this->assertDbError([1452], fn () => $hist(999999, $id));
        $this->assertDbError([1451], fn () => $this->db->table('simapi_update')->where('id_simapi_update', $id)->delete());
        $this->assertDbError([1048], fn () => $this->db->table('simapi_ch')->insert(['id_simapi_jd' => 1, 'nip' => null, 'action' => 1, 'action_at' => '2026-11-09 00:00:00']));
    }

    public function testPraCekAlterGagalJelasTanpaMengubahData(): void
    {
        $this->lepasSejak(self::VERSI_ALTER);
        $t = $this->db->prefixTable('simapi_jd');
        $this->db->query("INSERT INTO {$t} (jenis_data) VALUES ('uji')");
        $jd = (int) $this->db->insertID();
        $this->db->table('simapi_ch')->insert(['id_simapi_jd' => $jd, 'id_rwy' => 'bukan-angka', 'nip' => null, 'action' => 1, 'action_at' => '2026-11-09 00:00:00']);

        try {
            $this->pasangUlang();
            $this->fail('pra-cek seharusnya gagal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pra-cek simapi_ch gagal (nip NULL: 1, id_rwy bukan angka: 1)', $e->getMessage());
        }

        $this->assertSame('varchar(50) null', $this->skemaColumns('simapi_ch')['id_rwy'], 'tipe belum diubah');
        $this->assertSame(1, $this->db->table('simapi_ch')->where('id_rwy', 'bukan-angka')->countAllResults(), 'data tidak diubah');

        $this->db->table('simapi_ch')->where('id_simapi_jd', $jd)->delete();
        $this->db->table('simapi_jd')->where('id_simapi_jd', $jd)->delete();
    }

    public function testDownLaluUpKembali(): void
    {
        $this->lepasSejak(self::VERSI_ALTER);

        $this->assertFalse($this->skemaTableExists('simapi_update'));
        $this->assertFalse($this->skemaTableExists('simapi_update_hist'));
        $this->assertSame('text null', $this->skemaColumns('simapi_ch')['field_value']);
        $this->assertSame(0, $this->db->table('simapi_jd')->countAllResults(), 'seed dihapus');

        $this->pasangUlang();

        $this->assertTrue($this->skemaTableExists('simapi_update_hist'));
        $this->assertSame('longtext null', $this->skemaColumns('simapi_ch')['field_value']);
        $this->assertSame(14, $this->db->table('simapi_jd')->countAllResults());
    }

    public function testSeedMenolakIsiBerbeda(): void
    {
        $this->lepasSejak(self::VERSI_SEED);
        $this->db->table('simapi_jd')->insert(['id_simapi_jd' => 3, 'jenis_data' => 'Lain']);

        try {
            $this->pasangUlang();
            $this->fail('seed seharusnya menolak isi berbeda');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('id 3', $e->getMessage());
        }

        $this->assertSame(1, $this->db->table('simapi_jd')->countAllResults(), 'data tidak diubah');
        $this->db->table('simapi_jd')->where('id_simapi_jd', 3)->delete();
    }

    public function testKueriAuditBerjalan(): void
    {
        $sql   = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-031-audit-simapi-d1.sql'));
        $hasil = [];

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach (['simapi_update_hist', 'simapi_update', 'simapi_jd', 'simapi_ch'] as $tabel) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $tabel . '\b/', '$1 ' . $this->db->prefixTable($tabel), $q);
            }

            $hasil[] = $this->db->query($q)->getResultArray();
        }

        $this->assertCount(6, $hasil);
        $this->assertSame(0, (int) $hasil[0][0]['simapi_jd_hilang'], 'C1 seed lengkap');
    }
}
