<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\AkunSistemIntegrasi;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-027 — test skema permanen (DBV-027 §9; dasar test verifikasi DB Validator `SkemaIntegrasiKeluarDbvTest` di branch
 * `ci/dbv027-mariadb` digabung dengan test draf pelaksana): migration `2026-11-03-100000_CreateIntegrasiKeluarFase6`
 * di matriks CI MySQL 8.0 & MariaDB 10.4 — struktur/collation, CHECK (3819 MySQL / 4025 MariaDB), UNIQUE (1062), FK
 * (1452/1451), `firebase_token` per pemilik per kanal (T2 opsi a), `id_da` NOT NULL (T4), tanpa seed akun (Z-1 opsi c),
 * down() → skema lama lalu up() (T1 `DROP CONSTRAINT`), pra-cek sebelum DDL, pembatalan gagal-di-tengah, kueri audit.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaIntegrasiKeluarTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    private const VERSION = '2026-11-03-100000';

    private const TABEL_BARU = [
        'integrasi_log', 'siasn_status_pegawai', 'siasn_upload_arsip', 'double_riwayat_kp', 'siasn_ref_unor', 'siasn_referensi',
    ];

    private const CHECK = [3819, 4025];

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

    public function testConstraintTabelBaru(): void
    {
        $nip = $this->buatPegawai();

        $this->db->table('siasn_status_pegawai')->insert(['nip' => $nip, 'du' => 2, 'rw_gol' => 1]);
        $this->assertDbError(self::CHECK, fn () => $this->db->table('siasn_status_pegawai')->where('nip', $nip)->update(['rw_ak' => 3]));
        $this->assertDbError([1452], fn () => $this->db->table('siasn_status_pegawai')->insert(['nip' => '199912312099121001']));

        $arsip = ['nip' => $nip, 'rw_name' => 'Rw Jabatan', 'id_rw' => 7, 'id_da' => 9, 'status' => 0];
        $this->db->table('siasn_upload_arsip')->insert($arsip);
        $this->assertDbError([1062], fn () => $this->db->table('siasn_upload_arsip')->insert($arsip));
        $this->assertDbError(self::CHECK, fn () => $this->db->table('siasn_upload_arsip')->insert(array_merge($arsip, ['id_da' => 10, 'status' => 4])));
        $this->assertDbError([1048], fn () => $this->db->table('siasn_upload_arsip')->insert(array_merge($arsip, ['id_rw' => 8, 'id_da' => null])));

        $this->db->table('double_riwayat_kp')->insert(['nip' => $nip]);
        $this->assertDbError([1062], fn () => $this->db->table('double_riwayat_kp')->insert(['nip' => $nip]));

        $this->db->table('siasn_referensi')->insert(['jenis' => 'golongan', 'id_siasn' => '31', 'nama' => 'III/a', 'id_lokal' => '9']);
        $this->assertDbError(self::CHECK, fn () => $this->db->table('siasn_referensi')->insert(['jenis' => 'lain', 'id_siasn' => '1']));
        $this->assertDbError([1062], fn () => $this->db->table('siasn_referensi')->insert(['jenis' => 'golongan', 'id_siasn' => '31']));

        $this->db->table('siasn_ref_unor')->insert(['id' => 'unor-uji', 'id_unit' => 1, 'id_satker' => 2, 'lvl_uker' => 2]);

        $log = ['arah' => 1, 'layanan' => 'siasn', 'operasi' => 'uji', 'status' => 1, 'created_at' => '2026-11-03 00:00:00'];
        $this->db->table('integrasi_log')->insert($log);
        $this->assertDbError(self::CHECK, fn () => $this->db->table('integrasi_log')->insert(array_merge($log, ['arah' => 3])));
        $this->assertDbError(self::CHECK, fn () => $this->db->table('integrasi_log')->insert(array_merge($log, ['status' => 0])));

        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());
    }

    public function testFirebaseTokenPerPemilik(): void
    {
        $nip  = $this->buatPegawai();
        $nip2 = $this->buatPegawai();
        $akun = $this->idAkunSistem();

        $token = fn (array $baris): array => array_merge($baris, ['fcm_token' => $baris['fcm_token'], 'token_hash' => hash('sha256', $baris['fcm_token'])]);

        $this->db->table('firebase_token')->insert($token(['platform' => 2, 'nip' => $nip, 'fcm_token' => 'tok-sama']));
        $this->db->table('firebase_token')->insert($token(['platform' => 2, 'nip' => $nip2, 'fcm_token' => 'tok-sama']));
        $this->db->table('firebase_token')->insert($token(['platform' => 1, 'id_pengguna' => $akun, 'fcm_token' => 'tok-sama']));

        $this->assertDbError([1062], fn () => $this->db->table('firebase_token')->insert($token(['platform' => 2, 'nip' => $nip, 'fcm_token' => 'tok-sama'])));
        $this->assertDbError([1062], fn () => $this->db->table('firebase_token')->insert($token(['platform' => 1, 'id_pengguna' => $akun, 'fcm_token' => 'tok-sama'])));
        $this->assertDbError(self::CHECK, fn () => $this->db->table('firebase_token')->insert($token(['platform' => 2, 'fcm_token' => 'tok-a'])));
        $this->assertDbError(self::CHECK, fn () => $this->db->table('firebase_token')->insert($token(['platform' => 1, 'nip' => $nip, 'fcm_token' => 'tok-b'])));
        // T2 opsi (a): token web dimiliki akun saja — `nip` wajib NULL walau akunnya ber-NIP.
        $this->assertDbError(self::CHECK, fn () => $this->db->table('firebase_token')->insert($token(['platform' => 1, 'id_pengguna' => $akun, 'nip' => $nip, 'fcm_token' => 'tok-e'])));
        $this->assertDbError([1364], fn () => $this->db->table('firebase_token')->insert(['nip' => $nip, 'fcm_token' => 'tok-f', 'token_hash' => hash('sha256', 'tok-f')]));
        $this->assertDbError(self::CHECK, fn () => $this->db->table('firebase_token')->insert($token(['platform' => 3, 'nip' => $nip, 'fcm_token' => 'tok-c'])));
        $this->assertDbError([1452], fn () => $this->db->table('firebase_token')->insert($token(['platform' => 1, 'id_pengguna' => 999999, 'fcm_token' => 'tok-d'])));
    }

    public function testUniqueSkpPeriodik(): void
    {
        $nip = $this->buatPegawai();
        $skp = ['nip' => $nip, 'id_pns' => 'pns-uji', 'nama' => 'Uji', 'periode_id' => 'p1', 'skp_id' => 's1'];
        $this->db->table('riwayat_skp_periodik')->insert($skp);
        $this->assertDbError([1062], fn () => $this->db->table('riwayat_skp_periodik')->insert($skp));

        $this->db->table('riwayat_skp_periodik')->insert(array_merge($skp, ['periode_id' => null]));
        $this->db->table('riwayat_skp_periodik')->insert(array_merge($skp, ['periode_id' => null]));
        $this->assertSame(2, $this->db->table('riwayat_skp_periodik')->where('periode_id', null)->countAllResults());
    }

    public function testMigrationTanpaSeedAkunDibuatCommandService(): void
    {
        $this->assertSame(0, $this->db->table('pengguna')->where('username', 'sistem.integrasi')->countAllResults(), 'Z-1: migration murni skema');

        $this->assertSame(AkunSistemIntegrasi::HASIL_DIBUAT, (new AkunSistemIntegrasi($this->db))->siapkan());
        $this->assertSame(AkunSistemIntegrasi::HASIL_SUDAH_ADA, (new AkunSistemIntegrasi($this->db))->siapkan());
        $this->assertSame(1, $this->db->table('pengguna')->where('username', 'sistem.integrasi')->countAllResults());

        $row = $this->db->table('pengguna')->where('username', 'sistem.integrasi')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(['1', '0', null, null, null], [
            (string) $row['user_level'], (string) $row['status'], $row['password'], $row['password_legacy'], $row['nip'],
        ]);
    }

    public function testDownLaluUpKembaliSama(): void
    {
        $this->lepasSejak(self::VERSION);

        foreach (self::TABEL_BARU as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), "down() menghapus {$tabel}");
        }

        $this->assertSame(
            ['id_firebase_token' => 'bigint', 'nip' => 'varchar(30)', 'fcm_token' => 'text null', 'created_at' => 'datetime null', 'updated_at' => 'datetime null'],
            $this->skemaColumns('firebase_token'),
        );
        $this->assertSame([], $this->skemaChecks(['firebase_token']), 'CHECK firebase_token dilepas');
        [, $index] = $this->skemaIndexes('riwayat_skp_periodik');
        $this->assertArrayNotHasKey('uq_rsp_pns_periode_skp', $index);
        $this->pasangUlang();
        $this->assertSkema();
    }

    public function testPraCekMenghentikanSebelumDdl(): void
    {
        $this->lepasSejak(self::VERSION);
        $migration = $this->skemaMigration(self::VERSION);

        $nip = $this->buatPegawai();
        $skp = ['nip' => $nip, 'id_pns' => 'pns-uji', 'nama' => 'Uji', 'periode_id' => 'p1', 'skp_id' => 's1'];
        $this->db->table('riwayat_skp_periodik')->insertBatch([$skp, $skp]);
        $this->assertPraCekGagal($migration, '1 kunci');
        $this->db->table('riwayat_skp_periodik')->where('nip', $nip)->delete();

        $this->db->table('firebase_token')->insertBatch([['nip' => $nip, 'fcm_token' => 'tok-x'], ['nip' => $nip, 'fcm_token' => 'tok-x']]);
        $this->assertPraCekGagal($migration, '1 token');
        $this->db->table('firebase_token')->where('nip', $nip)->delete();
    }

    public function testPraCekTokenTanpaNipMenghentikanSebelumDdl(): void
    {
        $this->lepasSejak(self::VERSION);
        $migration = $this->skemaMigration(self::VERSION);
        $fbt       = $this->db->escapeIdentifiers($this->db->prefixTable('firebase_token'));

        // Skema DBV-022 `nip` NOT NULL, jadi normalnya 0 (audit A6b); dilonggarkan sementara untuk menguji pra-ceknya.
        $this->db->query("ALTER TABLE {$fbt} MODIFY `nip` VARCHAR(30) NULL");

        try {
            $this->db->table('firebase_token')->insert(['nip' => null, 'fcm_token' => 'tok-yatim']);
            $this->assertPraCekGagal($migration, '1 baris firebase_token tanpa nip');
        } finally {
            $this->db->query("DELETE FROM {$fbt} WHERE `nip` IS NULL");
            $this->db->query("ALTER TABLE {$fbt} MODIFY `nip` VARCHAR(30) NOT NULL");
        }
    }

    public function testGagalDiTengahMembatalkanLangkahSebelumnya(): void
    {
        $this->lepasSejak(self::VERSION);
        $migration = $this->skemaMigration(self::VERSION);
        $sebelum   = $this->skemaColumns('firebase_token');
        $tabel     = $this->db->escapeIdentifiers($this->db->prefixTable('siasn_referensi'));
        $this->db->query("CREATE TABLE {$tabel} (`x` INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $migration->up();
            $this->fail('up() harus gagal saat siasn_referensi sudah ada');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('siasn_referensi', $e->getMessage());
        } finally {
            $this->db->query("DROP TABLE IF EXISTS {$tabel}");
        }

        foreach (array_diff(self::TABEL_BARU, ['siasn_referensi']) as $t) {
            $this->assertFalse($this->skemaTableExists($t), "dibatalkan: {$t}");
        }

        $this->assertSame($sebelum, $this->skemaColumns('firebase_token'));
    }

    public function testKueriAuditBerjalan(): void
    {
        $sql   = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-027-audit-integrasi-keluar.sql'));
        $tabel = ['riwayat_skp_periodik', 'firebase_token', 'pengguna', 'pegawai', 'siasn_upload_arsip', 'siasn_status_pegawai', 'integrasi_log', 'siasn_referensi', 'siasn_ref_unor'];
        $n     = 0;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach ($tabel as $t) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $t . '\b/', '$1 ' . $this->db->prefixTable($t), $q);
            }

            $this->db->query($q)->getResultArray();
            $n++;
        }

        $this->assertSame(19, $n, 'jumlah kueri audit (A7 dihapus Z-1)');
    }

    private function assertPraCekGagal(object $migration, string $pesan): void
    {
        try {
            $migration->up();
            $this->fail('up() seharusnya berhenti di pra-cek');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($pesan, $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/\d{18}/', $e->getMessage(), 'pesan tanpa NIP');
        }

        foreach (self::TABEL_BARU as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), "pra-cek sebelum DDL: {$tabel}");
        }

        $this->assertArrayNotHasKey('platform', $this->skemaColumns('firebase_token'));
    }

    private function idAkunSistem(): int
    {
        (new AkunSistemIntegrasi($this->db))->siapkan();

        return (new AkunSistemIntegrasi($this->db))->id();
    }

    private function assertSkema(): void
    {
        foreach (self::TABEL_BARU as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), $tabel);
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        $kolom = $this->skemaColumns('firebase_token');
        $this->assertSame('varchar(30) null', $kolom['nip'] ?? null);
        $this->assertSame('int unsigned null', $kolom['id_pengguna'] ?? null);
        $this->assertSame('tinyint', $kolom['platform'] ?? null);
        $this->assertSame('char(64) null', $kolom['token_hash'] ?? null);
        $this->assertSame('datetime null', $kolom['refresh_date'] ?? null);

        [, $index, $unique] = $this->skemaIndexes('firebase_token');
        $this->assertSame(['id_pengguna', 'token_hash'], $index['uq_firebase_token_pengguna_hash'] ?? null);
        $this->assertSame(['nip', 'token_hash'], $index['uq_firebase_token_nip_hash'] ?? null);
        $this->assertContains('uq_firebase_token_nip_hash', $unique);

        [, $index] = $this->skemaIndexes('riwayat_skp_periodik');
        $this->assertSame(['id_pns', 'periode_id', 'skp_id'], $index['uq_rsp_pns_periode_skp'] ?? null);

        $this->assertSame(
            ['chk_firebase_token_pemilik', 'chk_firebase_token_platform'],
            array_keys($this->skemaChecks(['firebase_token'])),
        );
        $this->assertSame(
            ['chk_integrasi_log_arah', 'chk_integrasi_log_status', 'chk_siasn_referensi_jenis', 'chk_siasn_status_pegawai_flag', 'chk_siasn_upload_arsip_status'],
            array_keys($this->skemaChecks(['integrasi_log', 'siasn_status_pegawai', 'siasn_upload_arsip', 'siasn_referensi'])),
        );

        [$fk, , $bukanRestrict] = $this->skemaForeignKeys(['siasn_status_pegawai', 'siasn_upload_arsip', 'double_riwayat_kp', 'firebase_token'], []);
        foreach (['fk_nip_ssp_to_pegawai', 'fk_nip_sua_to_pegawai', 'fk_nip_drkp_to_pegawai', 'fk_id_pengguna_fbt_to_pengguna'] as $nama) {
            $this->assertArrayHasKey($nama, $fk);
        }
        $this->assertSame([], $bukanRestrict, 'FK RESTRICT/RESTRICT');
    }
}
