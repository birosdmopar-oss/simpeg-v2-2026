<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\AkunSistem;
use App\Database\Migrations\CreateIntegrasiKeluarFase6;
use App\Exceptions\AuthException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateIntegrasiKeluarFase6.php';

/**
 * DBV-027 — test skema draf migration (DBV-027 §8.2/§9): tabel baru (engine/collation), CHECK/UNIQUE/FK, `firebase_token`
 * per pemilik per kanal (T2 opsi a: web `nip` NULL), `siasn_upload_arsip.id_da` NOT NULL (T4), akun sistem tidak bisa
 * login, kueri audit, down() → skema lama lalu up() ulang (T1: `DROP CONSTRAINT`, jalan di MySQL 8 & MariaDB 10.4),
 * pra-cek sebelum DDL, dan pembatalan gagal-di-tengah. Masih memuat draf di `MigrationsDitahan/`; saat draf dipindah ke
 * `Migrations/` (setelah approve DBV) `require_once` dihapus dan test ini menjadi test skema permanen §9.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaIntegrasiKeluarTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    private ?CreateIntegrasiKeluarFase6 $m = null;

    private bool $naik = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateIntegrasiKeluarFase6();
    }

    protected function tearDown(): void
    {
        if ($this->naik) {
            $this->m?->down();
        }

        parent::tearDown();
    }

    public function testUpMembuatSkemaDanConstraintDitegakkan(): void
    {
        $nip = $this->buatPegawai();
        $this->up();

        foreach (CreateIntegrasiKeluarFase6::TABEL_BARU as $t) {
            $this->assertTrue($this->skemaTableExists($t), $t);
            $info = $this->skemaTableInfo($t);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $t);

            foreach ($this->skemaStringCollations($t) as $k => $c) {
                $this->assertSame('utf8mb4_unicode_ci', $c, "{$t}.{$k}");
            }
        }


        // akun sistem
        $akun = $this->db->table('pengguna')->where('username', CreateIntegrasiKeluarFase6::AKUN_SISTEM)->get()->getRowArray();
        $this->assertNotNull($akun);
        $this->assertSame(AkunSistem::USERNAME, CreateIntegrasiKeluarFase6::AKUN_SISTEM, 'penjaga T3 memakai username seed yang sama');
        $this->assertSame(['0', null, null, null, '1'], [$akun['status'], $akun['password'], $akun['password_legacy'], $akun['nip'], (string) $akun['user_level']]);
        $idAkun = (int) $akun['id_pengguna'];

        // firebase_token
        $fb = fn (array $r) => $this->db->table('firebase_token')->insert($r + ['created_at' => '2026-11-02 00:00:00']);
        $fb(['nip' => null, 'id_pengguna' => $idAkun, 'platform' => 1, 'fcm_token' => 'tok-web', 'token_hash' => hash('sha256', 'tok-web')]);
        $fb(['nip' => $nip, 'platform' => 2, 'fcm_token' => 'tok-and', 'token_hash' => hash('sha256', 'tok-and')]);
        $this->assertDbError([3819, 4025], fn () => $fb(['nip' => null, 'platform' => 2, 'fcm_token' => 'x1', 'token_hash' => hash('sha256', 'x1')]));
        $this->assertDbError([3819, 4025], fn () => $fb(['nip' => $nip, 'platform' => 1, 'fcm_token' => 'x2', 'token_hash' => hash('sha256', 'x2')]));
        // T2 opsi (a): token web dimiliki akun saja — `nip` wajib NULL walau akunnya pegawai.
        $this->assertDbError([3819, 4025], fn () => $fb(['nip' => $nip, 'id_pengguna' => $idAkun, 'platform' => 1, 'fcm_token' => 'x6', 'token_hash' => hash('sha256', 'x6')]));
        $this->assertDbError([3819, 4025], fn () => $fb(['nip' => $nip, 'id_pengguna' => $idAkun, 'platform' => 3, 'fcm_token' => 'x3', 'token_hash' => hash('sha256', 'x3')]));
        $this->assertDbError([1062], fn () => $fb(['nip' => $nip, 'platform' => 2, 'fcm_token' => 'tok-and', 'token_hash' => hash('sha256', 'tok-and')]));
        $this->assertDbError([1062], fn () => $fb(['nip' => null, 'id_pengguna' => $idAkun, 'platform' => 1, 'fcm_token' => 'tok-web', 'token_hash' => hash('sha256', 'tok-web')]));
        // DBV-Q8 ikut legacy: unik PER PEMILIK — token sama untuk pemilik lain diterima.
        $nip2 = $this->buatPegawai();
        $fb(['nip' => $nip2, 'platform' => 2, 'fcm_token' => 'tok-and', 'token_hash' => hash('sha256', 'tok-and')]);
        $this->db->table('pengguna')->insert(['username' => 'uji.lain', 'user_level' => 3, 'status' => '1'], true);
        $fb(['nip' => null, 'id_pengguna' => $this->db->insertID(), 'platform' => 1, 'fcm_token' => 'tok-web', 'token_hash' => hash('sha256', 'tok-web')]);
        $this->assertDbError([1452], fn () => $fb(['id_pengguna' => 999999, 'platform' => 1, 'fcm_token' => 'x4', 'token_hash' => hash('sha256', 'x4')]));
        $this->assertDbError([1364], fn () => $this->db->table('firebase_token')->insert(['nip' => $nip, 'fcm_token' => 'x5', 'token_hash' => hash('sha256', 'x5')]));

        // riwayat_skp_periodik UNIQUE
        $skp = fn (?string $per, ?string $skpId) => $this->db->table('riwayat_skp_periodik')->insert(['id_pns' => 'PNS-1', 'periode_id' => $per, 'skp_id' => $skpId, 'nip' => $nip, 'nama' => 'Uji']);
        $skp('P1', 'S1');
        $skp('P1', 'S2');
        $this->assertDbError([1062], fn () => $skp('P1', 'S1'));
        $skp(null, 'S1');
        $skp(null, 'S1');

        // tabel SIASN
        $this->db->table('siasn_status_pegawai')->insert(['nip' => $nip]);
        $this->assertDbError([3819, 4025], fn () => $this->db->table('siasn_status_pegawai')->where('nip', $nip)->update(['rw_gol' => 3]));
        $this->assertDbError([1452], fn () => $this->db->table('siasn_status_pegawai')->insert(['nip' => '199912312099121001']));
        $ua = fn (int $idDa) => $this->db->table('siasn_upload_arsip')->insert(['nip' => $nip, 'rw_name' => 'Rw Jabatan', 'id_rw' => 1, 'id_da' => $idDa]);
        $ua(10);
        $this->assertDbError([1062], fn () => $ua(10));
        // T4: id_da wajib (legacy selalu mengisi dari document_attachment) supaya UNIQUE (rw_name, id_rw, id_da) menjaga.
        $this->assertDbError([1048], fn () => $this->db->table('siasn_upload_arsip')->insert(['nip' => $nip, 'rw_name' => 'Rw Jabatan', 'id_rw' => 2, 'id_da' => null]));
        $this->assertDbError([3819, 4025], fn () => $this->db->table('siasn_upload_arsip')->update(['status' => 4]));
        $this->db->table('double_riwayat_kp')->insert(['nip' => $nip]);
        $this->assertDbError([1062], fn () => $this->db->table('double_riwayat_kp')->insert(['nip' => $nip]));
        $this->db->table('siasn_referensi')->insert(['jenis' => 'golongan', 'id_siasn' => '31', 'id_lokal' => '9']);
        $this->assertDbError([3819, 4025], fn () => $this->db->table('siasn_referensi')->insert(['jenis' => 'lain', 'id_siasn' => '1']));
        $this->db->table('siasn_ref_unor')->insert(['id' => 'U-1', 'nama_unor' => 'Unor Uji']);
        $il = fn (int $arah, int $status) => $this->db->table('integrasi_log')->insert(['arah' => $arah, 'layanan' => 'bkn', 'operasi' => 'periode', 'status' => $status, 'created_at' => '2026-11-02 00:00:00']);
        $il(1, 1);
        $this->assertDbError([3819, 4025], fn () => $il(3, 1));
        $this->assertDbError([3819, 4025], fn () => $il(1, 0));

        // hapus pegawai dengan baris SIASN ditolak
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());
    }

    public function testAkunSistemTidakBisaLogin(): void
    {
        $this->up();
        $auth = service('authService', false);

        foreach (['', 'apa-saja'] as $password) {
            try {
                $auth->login(CreateIntegrasiKeluarFase6::AKUN_SISTEM, $password, 'captcha-uji', '127.0.0.1');
                $this->fail('login harus ditolak');
            } catch (AuthException) {
                $this->addToAssertionCount(1);
            }
        }

        // Diaktifkan pun (status '1') tetap tidak bisa: tanpa password maupun hash legacy.
        $this->db->table('pengguna')->where('username', CreateIntegrasiKeluarFase6::AKUN_SISTEM)->update(['status' => '1']);
        $this->expectException(AuthException::class);
        $auth->login(CreateIntegrasiKeluarFase6::AKUN_SISTEM, '', 'captcha-uji-2', '127.0.0.1');
    }

    public function testKueriAuditBerjalan(): void
    {
        $sql   = (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-027-audit-integrasi-keluar.sql');
        $sql   = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $tabel = ['riwayat_skp_periodik', 'firebase_token', 'pengguna', 'pegawai', 'siasn_upload_arsip', 'siasn_status_pegawai', 'integrasi_log', 'siasn_referensi', 'siasn_ref_unor'];
        $jalan = function () use ($sql, $tabel): int {
            $n = 0;
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
                foreach ($tabel as $t) {
                    $q = (string) preg_replace('/(FROM|JOIN)\s+' . $t . '/', '$1 ' . $this->db->prefixTable($t), $q);
                }
                try {
                    $this->db->query($q)->getResultArray();
                } catch (\Throwable $e) {
                    throw new RuntimeException($e->getMessage() . "\n" . $q);
                }
                $n++;
            }

            return $n;
        };
        $this->up();
        $this->assertSame(20, $jalan());
    }

    public function testDownMengembalikanSkemaLamaLaluUpUlang(): void
    {
        $sebelum    = $this->skemaColumns('firebase_token');
        $idxSebelum = $this->skemaIndexes('riwayat_skp_periodik');
        $this->up();
        $this->m->down();
        $this->naik = false;

        foreach (CreateIntegrasiKeluarFase6::TABEL_BARU as $t) {
            $this->assertFalse($this->skemaTableExists($t), $t);
        }

        $this->assertSame($sebelum, $this->skemaColumns('firebase_token'));
        $this->assertSame($idxSebelum, $this->skemaIndexes('riwayat_skp_periodik'));
        $this->assertSame(0, $this->db->table('pengguna')->where('username', CreateIntegrasiKeluarFase6::AKUN_SISTEM)->countAllResults());

        $this->up();
        $this->assertTrue($this->skemaTableExists('integrasi_log'));
    }

    public function testPraCekDuplikatSkpMenolakSebelumDdl(): void
    {
        $nip = $this->buatPegawai();

        foreach ([1, 2] as $_) {
            $this->db->table('riwayat_skp_periodik')->insert(['id_pns' => 'PNS-1', 'periode_id' => 'P1', 'skp_id' => 'S1', 'nip' => $nip, 'nama' => 'Uji']);
        }

        try {
            $this->m->up();
            $this->fail('up() harus menolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 kunci', $e->getMessage());
            $this->assertStringNotContainsString($nip, $e->getMessage());
        }

        $this->assertFalse($this->skemaTableExists('integrasi_log'));
        $this->db->table('riwayat_skp_periodik')->where('nip', $nip)->delete();
    }

    public function testPraCekTokenGandaDanTokenTanpaNipMenolakSebelumDdl(): void
    {
        $nip = $this->buatPegawai();
        $fbt = $this->db->escapeIdentifiers($this->db->prefixTable('firebase_token'));

        foreach ([1, 2] as $_) {
            $this->db->table('firebase_token')->insert(['nip' => $nip, 'fcm_token' => 'tok-sama']);
        }

        try {
            $this->m->up();
            $this->fail('up() harus menolak token ganda');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 token FCM ganda per pemilik', $e->getMessage());
        }

        $this->db->table('firebase_token')->where('nip', $nip)->delete();
        $this->assertFalse($this->skemaTableExists('integrasi_log'));

        $this->db->query("ALTER TABLE {$fbt} MODIFY `nip` VARCHAR(30) NULL");

        try {
            $this->db->table('firebase_token')->insert(['nip' => null, 'fcm_token' => 'tok-yatim']);
            $this->m->up();
            $this->fail('up() harus menolak baris tanpa nip');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 baris firebase_token tanpa nip', $e->getMessage());
        } finally {
            $this->db->query("DELETE FROM {$fbt} WHERE `nip` IS NULL");
            $this->db->query("ALTER TABLE {$fbt} MODIFY `nip` VARCHAR(30) NOT NULL");
        }

        $this->assertFalse($this->skemaTableExists('integrasi_log'));
    }

    public function testGagalDiTengahMembatalkanLangkahSebelumnya(): void
    {
        $sebelum = $this->skemaColumns('firebase_token');
        $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('siasn_referensi')) . ' (`x` INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        try {
            $this->m->up();
            $this->fail('up() harus gagal');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/siasn_referensi/', $e->getMessage());
        } finally {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable('siasn_referensi')));
        }

        foreach (['integrasi_log', 'siasn_status_pegawai', 'siasn_upload_arsip', 'double_riwayat_kp', 'siasn_ref_unor'] as $t) {
            $this->assertFalse($this->skemaTableExists($t), $t);
        }

        $this->assertSame($sebelum, $this->skemaColumns('firebase_token'));
    }

    private function up(): void
    {
        $this->m->up();
        $this->naik = true;
    }
}
