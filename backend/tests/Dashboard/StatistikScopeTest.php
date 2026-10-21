<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\StatistikUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-03 scope (MAKE-036, PRD §7.1.8 StatistikScopeTest, F7-3/F7-22): Admin Satker bersatker hanya satkernya (default +
 * lingkup lain 403), admin unit = unit + satker di bawahnya, role 2/6/7 → 403, dan cache satker A tidak tampil untuk
 * satker B.
 *
 * @internal
 */
final class StatistikScopeTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use StatistikUjiTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/statistik';

    private string $nipA1;

    private string $nipA2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->siapkanMasterStatistik();

        $lahir       = date('Y-m-d', strtotime(TanggalBisnis::hariIni() . ' -40 years -100 days'));
        $this->nipA1 = $this->pegawaiStatistik('A1', ['jenis' => 1, 'lahir' => $lahir]);
        $this->pegawaiStatistik('A1', ['jenis' => 1, 'lahir' => $lahir]);
        $this->nipA2 = $this->pegawaiStatistik('A2', ['jenis' => 6, 'lahir' => $lahir]);
        $this->pegawaiStatistik('B1', ['jenis' => 1, 'lahir' => $lahir]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testAdminSatkerDefaultSatkernyaDanLingkupLain403(): void
    {
        $bearer = $this->bearerAkun($this->buatAkunUntuk($this->nipA1, Role::ADMIN_SATKER));

        $data = $this->dataStatistik($this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja'));
        $this->assertSame(['tipe' => 'satker', 'id' => $this->satker['A1'], 'nama' => 'Satker A1'], $data['lingkup']);
        $this->assertSame(['Satker A1'], array_column($data['kategori'], 'label'));
        $this->assertSame(['pns' => 2, 'pppk' => 0], $this->nilaiBaris($data, 'Satker A1'));
        $this->assertSame(2, $data['total']);

        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja', ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']])->assertStatus(200);

        foreach ([
            ['lingkup' => 'kementerian'],
            ['lingkup' => 'unit', 'id' => (string) $this->unit['A']],
            ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']],
            ['lingkup' => 'satker', 'id' => (string) $this->satker['B1']],
        ] as $q) {
            $result = $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/usia', $q);
            $result->assertStatus(403);
            $this->assertSame('Lingkup di luar hak akses.', $this->json($result)['message'], json_encode($q));
        }

        // Daftar lingkup: hanya unit A dengan satker A1.
        $lingkup = $this->json($this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/lingkup'))['data'];
        $this->assertNull($lingkup['kementerian']);
        $this->assertSame([$this->unit['A']], array_column($lingkup['unit'], 'id'));
        $this->assertSame(['Satker A1'], array_column($lingkup['unit'][0]['satker'], 'nama'));
    }

    public function testAdminUnitTanpaSatkerMelihatUnitDanSatkerDiBawahnya(): void
    {
        $bearer = $this->bearerAkun($this->buatAkunUntuk($this->nipA1, Role::ADMIN_SATKER, ['id_satker' => null, 'id_unit' => (string) $this->unit['A']]));

        $data = $this->dataStatistik($this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja'));
        $this->assertSame(['tipe' => 'unit', 'id' => $this->unit['A'], 'nama' => 'Unit A'], $data['lingkup']);
        $this->assertSame(['Satker A1', 'Satker A2'], array_column($data['kategori'], 'label'));
        $this->assertSame(3, $data['total']);

        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja', ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']])->assertStatus(200);
        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja', ['lingkup' => 'unit', 'id' => (string) $this->unit['A']])->assertStatus(200);
        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja', ['lingkup' => 'satker', 'id' => (string) $this->satker['B1']])->assertStatus(403);
        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja', ['lingkup' => 'unit', 'id' => (string) $this->unit['B']])->assertStatus(403);
        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja', ['lingkup' => 'kementerian'])->assertStatus(403);

        $lingkup = $this->json($this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/lingkup'))['data'];
        $this->assertNull($lingkup['kementerian']);
        $this->assertSame([$this->unit['A']], array_column($lingkup['unit'], 'id'));
        $this->assertSame(['Satker A1', 'Satker A2'], array_column($lingkup['unit'][0]['satker'], 'nama'));
    }

    public function testAdminSatkerTanpaLingkupValidFailClosed(): void
    {
        $bearer = $this->bearerAkun($this->buatAkunUntuk($this->nipA1, Role::ADMIN_SATKER, ['id_satker' => null, 'id_unit' => null]));

        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/unit-kerja')->assertStatus(403);
        $this->withHeaders(['Authorization' => $bearer])->get(self::BASE . '/lingkup')->assertStatus(403);
    }

    public function testRolePegawaiDitolak403(): void
    {
        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $this->asRole($role)->get(self::BASE . '/unit-kerja')->assertStatus(403);
            $this->asRole($role)->get(self::BASE . '/lingkup')->assertStatus(403);
            $this->asRole($role)->get(self::BASE . '/unit-kerja/xlsx')->assertStatus(403);
        }

        // Tanpa token (header Authorization dari pemanggilan sebelumnya dibersihkan) → 401.
        $this->clearAuthState();
        $this->withHeaders([])->get(self::BASE . '/unit-kerja')->assertStatus(401);
    }

    public function testCacheSatkerATidakTampilUntukSatkerB(): void
    {
        $bearerA1 = $this->bearerAkun($this->buatAkunUntuk($this->nipA1, Role::ADMIN_SATKER));
        $bearerA2 = $this->bearerAkun($this->buatAkunUntuk($this->nipA2, Role::ADMIN_SATKER));

        $a1 = $this->dataStatistik($this->withHeaders(['Authorization' => $bearerA1])->get(self::BASE . '/unit-kerja'));
        $this->assertSame(2, $a1['total']);
        $this->assertFalse($a1['cache']['hit']);

        // Akun satker A2, jenis dan parameter sama: bukan hasil cache A1.
        $a2 = $this->dataStatistik($this->withHeaders(['Authorization' => $bearerA2])->get(self::BASE . '/unit-kerja'));
        $this->assertSame(['tipe' => 'satker', 'id' => $this->satker['A2'], 'nama' => 'Satker A2'], $a2['lingkup']);
        $this->assertSame(['pns' => 0, 'pppk' => 1], $this->nilaiBaris($a2, 'Satker A2'));
        $this->assertSame(1, $a2['total']);
        $this->assertFalse($a2['cache']['hit']);

        // Permintaan kedua masing-masing kena cache miliknya sendiri.
        $this->assertTrue($this->dataStatistik($this->withHeaders(['Authorization' => $bearerA1])->get(self::BASE . '/unit-kerja'))['cache']['hit']);
        $this->assertSame(2, $this->dataStatistik($this->withHeaders(['Authorization' => $bearerA1])->get(self::BASE . '/unit-kerja'))['total']);
        $this->assertTrue($this->dataStatistik($this->withHeaders(['Authorization' => $bearerA2])->get(self::BASE . '/unit-kerja'))['cache']['hit']);
        $this->assertSame(1, $this->dataStatistik($this->withHeaders(['Authorization' => $bearerA2])->get(self::BASE . '/unit-kerja'))['total']);

        // Super admin lingkup satker A1 eksplisit: key berbeda (segmen all), hasil sama dengan data A1.
        $sa = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']]));
        $this->assertFalse($sa['cache']['hit']);
        $this->assertSame(2, $sa['total']);
    }
}
