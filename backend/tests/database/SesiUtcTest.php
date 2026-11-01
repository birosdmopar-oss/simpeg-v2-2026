<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Constants\Role;
use App\Database\MySQLi\Connection;
use Config\Database as KonfigDatabase;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * CR-086 / DBV-025 (penerapan DBV-011 §3.3): setiap koneksi aplikasi memakai zona sesi UTC (`+00:00`) sehingga
 * `DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP` kolom audit berisi UTC. Server uji sengaja berzona SYSTEM
 * (WIB di mesin lokal); `+00:00` valid di MySQL 8 dan MariaDB 10.4 (batas MariaDB −12:59..+13:00 tidak dilampaui).
 *
 * @internal
 */
final class SesiUtcTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testGrupDefaultMemakaiDriverSesiUtcDanKoneksiUjiBerzonaUtc(): void
    {
        $konfig = new KonfigDatabase();

        $this->assertSame('App\Database\MySQLi', $konfig->default['DBDriver']);
        $this->assertTrue(is_subclass_of($konfig->tests['DBDriver'] . '\Connection', Connection::class), 'driver grup tests (override phpunit.dist.xml) turunan driver aplikasi');
        $this->assertInstanceOf(Connection::class, $this->db, 'driver uji turunan driver aplikasi');
        $this->assertSame('+00:00', $this->db->query('SELECT @@session.time_zone AS z')->getRowArray()['z']);
        $this->assertSame('MySQLi', $this->db->getPlatform(), 'platform tetap MySQLi (Forge/Utils/queue)');

        $selisih = $this->db->query('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP()) AS s')->getRowArray()['s'];
        $this->assertSame(0, (int) $selisih, 'NOW() = UTC_TIMESTAMP()');
    }

    public function testDefaultDanOnUpdateKolomAuditBerisiUtcDanUpdateTanpaPerubahanTidakMenggeser(): void
    {
        [$nip] = $this->aktorStudi(Role::PEGAWAI);

        $awal = time();
        $this->db->table('petugas_layanan')->insert(['id_jenis_layanan' => 1, 'nip' => $nip, 'status' => 1]);
        $id  = (int) $this->db->insertID();
        $row = fn (): array => $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->get()->getRowArray();

        $this->assertEqualsWithDelta($awal, strtotime($row()['created_at'] . ' UTC'), 30, 'DEFAULT CURRENT_TIMESTAMP = UTC');

        // Kunci updated_at ke nilai lampau, lalu update TANPA perubahan nilai: ON UPDATE tidak boleh menggeser.
        $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->update(['updated_at' => '2020-01-02 03:04:05']);
        $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->update(['status' => 1]);
        $this->assertSame('2020-01-02 03:04:05', $row()['updated_at'], 'update tanpa perubahan tidak menggeser updated_at');

        // Update yang mengubah nilai: ON UPDATE CURRENT_TIMESTAMP = UTC.
        $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->update(['status' => 0]);
        $this->assertEqualsWithDelta($awal, strtotime($row()['updated_at'] . ' UTC'), 30, 'ON UPDATE CURRENT_TIMESTAMP = UTC');
    }

    public function testKoneksiBaruSetelahReconnectTetapUtc(): void
    {
        $baru = new Connection(array_merge((array) (new KonfigDatabase())->tests, [
            'hostname' => $this->db->hostname, 'port' => $this->db->port, 'database' => $this->db->database,
            'username' => $this->db->username, 'password' => $this->db->password, 'DBDriver' => 'App\Database\MySQLi',
        ]));
        $baru->initialize();

        $this->assertSame('+00:00', $baru->query('SELECT @@session.time_zone AS z')->getRowArray()['z']);
        $baru->reconnect();
        $this->assertSame('+00:00', $baru->query('SELECT @@session.time_zone AS z')->getRowArray()['z'], 'reconnect memasang ulang zona sesi');
        $baru->close();
    }
}
