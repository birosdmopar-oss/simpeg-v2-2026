<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Simapi\PenyajiPegawaiLama;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-076 (Q6-41) — API SIMAPI lama `simapi/a_pegawai/{token,detail,pegawai_update}` lewat route sungguhan [K `A_pegawai.php`]:
 * allowlist WAJIB berisi + IP cocok untuk semua klien (tanpa cek type), amplop "01"/"02" data [] dan pesan persis legacy,
 * detail 11 kunci (password = hash berlaku [V2]), pegawai_update ack-on-read per klien (≤100, ASC, WIB, kunci baris klien),
 * token baru tiap handshake tanpa mencabut token aktif lain.
 *
 * @internal
 */
final class SimapiAPegawaiTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SimapiUjiTrait;

    private const IP = '203.0.113.7';

    /** @var array{id: int, username: string, rahasia: string} */
    private array $klien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
        Services::superglobals()->setServer('REMOTE_ADDR', self::IP);
        $this->klien = $this->klienLama('simlama', 1);
    }

    protected function tearDown(): void
    {
        Services::superglobals()->setServer('REMOTE_ADDR', '127.0.0.1');
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    /**
     * @return array{id: int, username: string, rahasia: string}
     */
    private function klienLama(string $username, int $jenis, ?string $allowlist = self::IP): array
    {
        $k = $this->klienUji($username, $jenis, ['simapi']);
        $this->db->table('klien_layanan')->where('id_klien', $k['id'])->update(['ip_allowlist' => $allowlist]);

        return $k;
    }

    /**
     * @param array{username: string, rahasia: string} $klien
     *
     * @return array<string, mixed>
     */
    private function handshake(array $klien): array
    {
        return $this->jsonUji($this->withHeaders(['Authorization' => $this->basicUji($klien)])->call('post', 'simapi/a_pegawai/token'));
    }

    /**
     * @param array{username: string, rahasia: string} $klien
     */
    private function tokenLama(array $klien): string
    {
        $b = $this->handshake($klien);
        $this->assertSame(['01', 'Success'], [$b['status'], $b['msg']]);
        $this->assertSame(['token'], array_keys($b['data']));

        return (string) $b['data']['token'];
    }

    private function su(string $nip, string $aksi = 'UPDATE', string $utc = '2026-11-05 17:30:00'): int
    {
        $this->db->table('simapi_update')->insert(['nip' => $nip, 'last_action' => $aksi, 'last_update' => $utc, 'nama_field' => 'a|b', 'nilai_lama' => '1|2', 'nilai_baru' => '3|4', 'created_at' => $utc]);

        return (int) $this->db->insertID();
    }

    public function testTokenAllowlistKosongAtauIpSalahGagalUntukSemuaJenis(): void
    {
        $gagal = ['status' => '02', 'msg' => 'Authentication failed', 'data' => []];

        foreach ([[null, 1], ['', 2], ['198.51.100.1', 2]] as $i => [$allow, $jenis]) {
            $k = $this->klienLama('klien' . $i, $jenis, $allow);
            $this->assertSame($gagal, $this->handshake($k), (string) $allow);
        }

        $this->assertSame($gagal, $this->handshake(['username' => $this->klien['username'], 'rahasia' => 'salah']));
        $this->assertSame('01', $this->handshake($this->klienLama('jenis2', 2))['status'], 'tanpa cek type');
    }

    public function testTokenBaruTiapHandshakeTanpaMencabutTokenLain(): void
    {
        $a = $this->tokenLama($this->klien);
        $b = $this->tokenLama($this->klien);
        $this->assertNotSame($a, $b);

        $nip = $this->buatPegawai();

        foreach ([$a, $b, $this->tokenUji($this->klien)] as $t) {
            $this->assertSame('01', $this->jsonUji($this->simapiUji('simapi/a_pegawai/detail/' . $nip, $t))['status'], 'token v1 & a_pegawai saling berlaku');
        }
    }

    public function testTokenTidakSahAtauIpBerubah(): void
    {
        $gagal = ['status' => '02', 'msg' => 'Token is not valid', 'data' => []];
        $token = $this->tokenLama($this->klien);

        $this->assertSame($gagal, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', str_repeat('A', strlen($token)))));
        $this->assertSame($gagal, $this->jsonUji($this->tanpaAuthUji('simapi/a_pegawai/detail/1')));

        Services::superglobals()->setServer('REMOTE_ADDR', '198.51.100.9');
        $this->assertSame($gagal, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token)), 'IP tak cocok');

        Services::superglobals()->setServer('REMOTE_ADDR', self::IP);
        $this->db->table('klien_layanan')->where('id_klien', $this->klien['id'])->update(['ip_allowlist' => null]);
        $this->assertSame($gagal, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token)), 'allowlist dikosongkan sesudah handshake');

        // API baru tetap: allowlist NULL = semua IP (ADR-036).
        $this->assertSame('01', $this->jsonUji($this->simapiUji('simapi/v1/a_employee/data_umum/' . $this->buatPegawai(), $token))['status']);

        $this->db->table('simapi_token')->update(['expired_at' => '2000-01-01 00:00:00']);
        $this->db->table('klien_layanan')->where('id_klien', $this->klien['id'])->update(['ip_allowlist' => self::IP]);
        $this->assertSame($gagal, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token)), 'kedaluwarsa');
    }

    public function testDetailKontrak(): void
    {
        $token = $this->tokenLama($this->klien);
        $nip   = $this->buatPegawai(['glr_awal' => 'Dr.', 'nama' => 'Budi', 'glr_akhir' => 'M.Si'], ['jabatan' => 'Analis', 'unit' => 'Unit A', 'satker' => 'Satker A']);
        $this->db->table('pengguna')->insert(['nip' => $nip, 'username' => $nip, 'password_legacy' => md5('x'), 'email' => 'b@contoh.test', 'user_level' => 2, 'status' => '1', 'name' => 'Budi']);

        $b = $this->jsonUji($this->simapiUji('simapi/a_pegawai/detail/' . $nip, $token, 'post'));
        $this->assertSame(['01', 'Success'], [$b['status'], $b['msg']]);
        $this->assertSame(['nip', 'nama_lengkap', 'foto', 'username', 'password', 'email', 'jabatan', 'unit_kerja', 'satuan_kerja', 'jenis_pegawai', 'status'], array_keys($b['data']));
        $this->assertSame([$nip, 'Dr. Budi, M.Si', '', $nip, md5('x'), 'b@contoh.test', 'Analis', 'Unit A', 'Satker A', null, 'Aktif'], array_values($b['data']), 'password = MD5 legacy sebelum login ulang');

        $this->db->table('pengguna')->where('nip', $nip)->update(['password' => 'argonhash']);
        $this->assertSame('argonhash', $this->jsonUji($this->simapiUji('simapi/a_pegawai/detail/' . $nip, $token))['data']['password'], 'Argon2id bila ada');

        $this->assertSame(['status' => '02', 'msg' => 'NIP not valid', 'data' => []], $this->jsonUji($this->simapiUji('simapi/a_pegawai/detail/999', $token)));
        $this->assertSame(['status' => '02', 'msg' => 'Invalid paramater', 'data' => []], $this->jsonUji($this->simapiUji('simapi/a_pegawai/detail', $token)));

        $tanpaAkun = $this->buatPegawai(['status' => 2]);
        $d         = $this->jsonUji($this->simapiUji('simapi/a_pegawai/detail/' . $tanpaAkun, $token))['data'];
        $this->assertSame([null, null, null, 'Tidak Aktif'], [$d['username'], $d['password'], $d['email'], $d['status']]);
    }

    public function testPegawaiUpdateAckPerKlienBatasDanWib(): void
    {
        $token = $this->tokenLama($this->klien);
        $a     = $this->su('000000000000000001');
        $this->su('000000000000000002', 'DELETE');

        $b = $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token));
        $this->assertSame(['01', 'Success'], [$b['status'], $b['msg']]);
        $this->assertSame(['id', 'nip', 'last_action', 'last_update', 'nama_field', 'nilai_lama', 'nilai_baru'], array_keys($b['data'][0]));
        $this->assertSame([(string) $a, 'UPDATE', '2026-11-06 00:30:00'], [$b['data'][0]['id'], $b['data'][0]['last_action'], $b['data'][0]['last_update']], 'UTC → WIB');
        $this->assertSame(['status' => '01', 'msg' => 'Success', 'data' => []], $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token)), 'ack-on-read');

        $lain = $this->klienLama('simlama2', 2);
        $this->assertCount(2, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $this->tokenLama($lain)))['data'], 'klien lain tetap menerima');

        for ($i = 0; $i < PenyajiPegawaiLama::BATAS + 3; $i++) {
            $this->su('000000000000000003');
        }

        $this->assertCount(100, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token))['data']);
        $this->assertCount(3, $this->jsonUji($this->simapiUji('simapi/a_pegawai/pegawai_update', $token))['data']);
        $this->assertSame(105, $this->db->table('simapi_update_hist')->where('id_klien', $this->klien['id'])->countAllResults());
    }

    /**
     * Baca + tandai mengunci BARIS KLIEN (bukan baris data): klien yang sama menunggu, klien lain tidak tertahan.
     */
    #[Group('db-isolasi-penuh')]
    public function testDiserialkanLewatKunciBarisKlien(): void
    {
        $this->su('000000000000000001');
        $lain2 = $this->klienLama('simlama3', 2);
        $lain  = Database::connect(config(Database::class)->tests, false);
        $lain->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $lain->transBegin();
        $lain->query('SELECT id_klien FROM ' . $lain->prefixTable('klien_layanan') . ' WHERE id_klien = ? FOR UPDATE', [$this->klien['id']]);

        try {
            $this->assertCount(1, (new PenyajiPegawaiLama($this->db))->pegawaiUpdate($lain2['id']), 'klien lain tidak menunggu');

            try {
                (new PenyajiPegawaiLama($this->db))->pegawaiUpdate($this->klien['id']);
                $this->fail('klien yang sama seharusnya menunggu kunci baris klien');
            } catch (DatabaseException|RuntimeException $e) {
                $this->assertSame(1205, (int) $e->getCode(), $e->getMessage());
            }
        } finally {
            $lain->transRollback();
            $lain->close();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame(0, $this->db->table('simapi_update_hist')->where('id_klien', $this->klien['id'])->countAllResults());
        $this->assertCount(1, (new PenyajiPegawaiLama($this->db))->pegawaiUpdate($this->klien['id']));
    }
}
