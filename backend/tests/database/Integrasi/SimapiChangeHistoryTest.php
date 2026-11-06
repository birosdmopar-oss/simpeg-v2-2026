<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Simapi\PenyajiChangeHistory;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;

/**
 * MAKE-060 (F6-06, AC-06.3) — `simapi/v1/a_employee/change_history` lewat route sungguhan: hanya type 2, filter
 * `simapi_auth_jd`, ack-on-read (panggilan kedua tidak mengulang), LIMIT 100 ASC, field & pemetaan `action` [K AE:84-98],
 * `action_at` UTC → WIB. Data `simapi_ch` sintetis (penulis = MAKE-060 tahap berikut, menunggu spesifikasi trigger D1).
 *
 * @internal
 */
final class SimapiChangeHistoryTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SimapiUjiTrait;

    /** @var array{id: int, username: string, rahasia: string} */
    private array $klien;

    private string $token = '';

    /** @var array<string, int> */
    private array $jd = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
        $this->klien = $this->klienUji('simanta', 2, ['simapi']);
        $this->token = $this->tokenUji($this->klien);

        foreach (['pegawai', 'riwayat_kp'] as $nama) {
            $this->db->table('simapi_jd')->insert(['jenis_data' => $nama]);
            $this->jd[$nama] = (int) $this->db->insertID();
        }
    }

    protected function tearDown(): void
    {
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    private function ch(string $jenis, int $action = 1, string $actionAt = '2026-11-05 01:30:00', ?string $nip = '199001012015011001'): int
    {
        $this->db->table('simapi_ch')->insert([
            'id_simapi_jd' => $this->jd[$jenis], 'id_rwy' => '7', 'nip' => $nip, 'action' => $action,
            'action_at'    => $actionAt, 'field_name' => 'a|b', 'field_value' => '1|2',
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function panggil(): array
    {
        return $this->jsonUji($this->simapiUji('simapi/v1/a_employee/change_history', $this->token));
    }

    public function testKontrakFieldPemetaanDanWib(): void
    {
        $kontrak = $this->fixtureKontrak('a_employee_change_history');
        $this->ch('pegawai', 1, '2026-11-05 17:30:00');
        $this->ch('riwayat_kp', 2);

        $badan = $this->panggil();

        $this->assertSame(['01', 'Success'], [$badan['status'], $badan['msg']]);
        $this->assertSame($kontrak['kunci'], array_keys($badan['data'][0]), $kontrak['_rujukan']);
        $this->assertSame(['pegawai', 'INSERT / UPDATE', '2026-11-06 00:30:00'], [$badan['data'][0]['jenis_data'], $badan['data'][0]['action'], $badan['data'][0]['action_at']], 'UTC 17:30 → WIB 00:30 hari berikut');
        $this->assertSame('DELETE', $badan['data'][1]['action']);
    }

    public function testAckOnReadTidakMengulangDanHanyaBaruSesudahnya(): void
    {
        $a = $this->ch('pegawai');
        $this->ch('pegawai');

        $this->assertCount(2, $this->panggil()['data']);
        $this->assertSame(['status' => '01', 'msg' => 'Success', 'data' => []], $this->panggil(), 'AC-06.3: panggilan kedua kosong');

        $c = $this->ch('riwayat_kp');
        $this->assertCount(1, $this->panggil()['data']);
        $this->assertSame(3, $this->db->table('simapi_auth_ch')->where('id_klien', $this->klien['id'])->countAllResults());
        $this->assertGreaterThan($a, $c);
    }

    public function testFilterJenisDataKlienDanKosongBerartiSemua(): void
    {
        $this->ch('pegawai');
        $this->ch('riwayat_kp');
        $this->db->table('simapi_auth_jd')->insert(['id_klien' => $this->klien['id'], 'id_simapi_jd' => $this->jd['riwayat_kp']]);

        $this->assertSame(['riwayat_kp'], array_column($this->panggil()['data'], 'jenis_data'), 'hanya jenis data klien');

        $lain  = $this->klienUji('simanta2', 2, ['simapi']);
        $token = $this->tokenUji($lain);
        $this->assertCount(2, $this->jsonUji($this->simapiUji('simapi/v1/a_employee/change_history', $token))['data'], 'tanpa simapi_auth_jd → semua [K]');
    }

    public function testBatas100UrutAsc(): void
    {
        for ($i = 0; $i < PenyajiChangeHistory::BATAS + 5; $i++) {
            $this->ch('pegawai');
        }

        $this->assertCount(100, $this->panggil()['data']);
        $this->assertCount(5, $this->panggil()['data']);
    }

    public function testAckDiAtasMaxDianggapTerkirim(): void
    {
        $id = $this->ch('pegawai');
        $this->db->table('simapi_auth_ch')->insert(['id_klien' => $this->klien['id'], 'id_simapi_ch' => $id, 'created_at' => '2026-11-05 00:00:00']);

        $this->assertSame([], $this->panggil()['data'], 'id <= MAX ack klien tidak dikirim ulang [K AE:73-86]');
        $this->ch('pegawai');
        $this->assertCount(1, $this->panggil()['data']);
    }

    /**
     * DBV-026 §13.2 A5: baca + tandai mengunci baris klien. Selama koneksi lain memegang kunci baris klien yang sama,
     * panggilan kedua menunggu (di sini: lock wait timeout 1205) alih-alih membaca MAX yang sama lalu bertabrakan 1062.
     */
    #[Group('db-isolasi-penuh')]
    public function testBacaTandaiDiserialkanLewatKunciBarisKlien(): void
    {
        $this->ch('pegawai');
        $lain = Database::connect(config(Database::class)->tests, false);
        $lain->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $lain->transBegin();
        $lain->query('SELECT id_klien FROM ' . $lain->prefixTable('klien_layanan') . ' WHERE id_klien = ? FOR UPDATE', [$this->klien['id']]);

        try {
            (new PenyajiChangeHistory($this->db))->ambilDanTandai($this->klien['id']);
            $this->fail('panggilan kedua seharusnya menunggu kunci baris klien');
        } catch (DatabaseException|RuntimeException $e) {
            $this->assertSame(1205, (int) $e->getCode(), $e->getMessage());
        } finally {
            $lain->transRollback();
            $lain->close();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame(0, $this->db->table('simapi_auth_ch')->countAllResults(), 'tidak ada penanda dari panggilan yang tertahan');
        $this->assertCount(1, (new PenyajiChangeHistory($this->db))->ambilDanTandai($this->klien['id']), 'setelah kunci dilepas berjalan normal');
    }

    public function testHanyaType2DanButuhToken(): void
    {
        $this->ch('pegawai');
        $token1 = $this->tokenUji($this->klienUji('pusdatin', 1, ['simapi']));

        $this->assertSame(['status' => '01', 'msg' => '', 'data' => []], $this->jsonUji($this->simapiUji('simapi/v1/a_employee/change_history', $token1)), 'type 1 → data kosong [K AE:48-50]');
        $this->assertSame(0, $this->db->table('simapi_auth_ch')->countAllResults(), 'type 1 tidak menandai');
        $this->assertSame(['status' => '02', 'msg' => 'Token is not valid', 'data' => []], $this->jsonUji($this->tanpaAuthUji('simapi/v1/a_employee/change_history')));
    }
}
