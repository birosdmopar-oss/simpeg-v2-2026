<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\DokumenUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Kepegawaian\Riwayat\UjiKp;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * F-09 match snapshot aktif (MAKE-037, PRD §7.2.8 DokumenSnapshotTest): setelah riwayat KP disetujui lewat
 * RiwayatEngine (jalur Fase 3), DUK (JSON dan XLSX) memuat pangkat baru dan urutan berubah — tanpa cache.
 *
 * @internal
 */
final class DokumenSnapshotTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DokumenUjiTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use RiwayatUjiTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/dokumen';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->pasangThrottlerUji();
        $this->siapkanMasterDokumen();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testDukMemuatPangkatBaruSetelahRiwayatKpDisetujui(): void
    {
        $this->pasangEngineNyata([new UjiKp()]);

        foreach ([1 => 'CPNS', 2 => 'PNS', 3 => 'Reguler', 6 => 'Lainnya'] as $id => $nama) {
            $this->buatJenisKp($id, $nama);
        }

        $nipA  = $this->pegawaiDokumen('A1', ['nama' => 'Andi', 'jenis' => 1, 'lahir' => '1985-01-01', 'pangkat' => 'III/a', 'tmt_pangkat' => '2016-04-01']);
        $nipB  = $this->pegawaiDokumen('A1', ['nama' => 'Beni', 'jenis' => 1, 'lahir' => '1987-01-01', 'pangkat' => 'III/a', 'tmt_pangkat' => '2018-04-01']);
        $admin = $this->admin();

        $sebelum = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']]));
        $this->assertSame(['Andi', 'Beni'], array_slice($this->namaItems($sebelum), 0, 2), 'sama III/a → TMT lebih lama (Andi) di atas');

        // Beni naik ke IV/a lewat engine (Menunggu → Disetujui): snapshot pegawai_kp disinkronkan.
        $this->idPangkatUji = $this->pangkat['IV/a'];
        $id                 = $this->sisipRiwayat(new UjiKp(), $nipB, array_merge($this->barisKp(3, '2026-04-01'), ['status' => 0, 'gol_ruang' => 'IV/a', 'gol' => 'IV', 'pangkat' => 'Pembina']));
        service('riwayatService')->proses($admin, $nipB, 'kp', $id, 'setujui', null);

        $sesudah = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']]));
        $items   = $sesudah['items'];
        $this->assertSame('Beni', $items[0]['nama'], 'IV/a kini di atas III/a');
        $this->assertSame(['IV/a', '01 Apr 2026'], [$items[0]['gol_ruang'], $items[0]['tmtsk_pangkat']]);
        $this->assertSame('Andi', $items[1]['nama']);
        $this->assertSame($nipA, $items[1]['nip']);

        $xlsx = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/xlsx', ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']]);
        $xlsx->assertStatus(200);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $xlsx->response()->getBody());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('IV/a', $sheet, 'XLSX juga dari snapshot terkini');

        $this->lepasEngine();
    }
}
