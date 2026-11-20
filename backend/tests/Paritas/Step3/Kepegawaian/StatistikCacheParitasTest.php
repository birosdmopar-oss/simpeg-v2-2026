<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Libraries\Dashboard\Statistik\StatistikCache;
use App\Libraries\Dashboard\Statistik\StatistikService;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Models\Kepegawaian\PegawaiModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\StatistikUjiTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Paritas\Kepegawaian\CacheTtlSpy;

/**
 * CR-164 pagar paritas step 3 (no. 7) — test karakterisasi `StatistikService` + `StatistikCache` + `CacheService`,
 * mengunci perilaku SEKARANG: `cache.hit`, TTL 900 yang dipakai saat menyimpan, segmen scope Admin Satker (role 3) di
 * kunci cache, dan invalidasi pola `dashboard_stat_*` dari hook `PegawaiModel` serta event riwayat.
 *
 * Cache uji = {@see CacheTtlSpy} (MockCache pencatat), prefix kosong. Data sintetis.
 *
 * @internal
 */
final class StatistikCacheParitasTest extends DatabaseTestCase
{
    // StatistikUjiTrait memakai helper AuthTestTrait + FeatureTestTrait.
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use StatistikUjiTrait;

    private CacheTtlSpy $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy      = CacheTtlSpy::baru();
        $this->cacheUji = new CacheService($this->spy, '');
        Services::injectMock('cacheService', $this->cacheUji);
        Services::injectMock('pegawaiScope', new PegawaiScope());
        $this->siapkanMasterStatistik();

        $this->pegawaiStatistik('A1', ['jenis' => 1]);
        $this->pegawaiStatistik('A2', ['jenis' => 1]);
        $this->pegawaiStatistik('B1', ['jenis' => 1]);
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    public function testHitKeduaDanTtl900SaatMenyimpan(): void
    {
        $super   = $this->auth(Role::SUPER_ADMIN);
        $pertama = $this->svc()->statistik($super, StatistikService::UNIT_KERJA, []);
        $kedua   = $this->svc()->statistik($super, StatistikService::UNIT_KERJA, []);

        $this->assertSame(['hit' => false, 'ttl' => 900], $pertama['cache']);
        $this->assertSame(['hit' => true, 'ttl' => 900], $kedua['cache']);
        $this->assertSame([['key' => 'dashboard_stat_unit-kerja_kementerian_0_all', 'ttl' => 900]], $this->simpanStatistik(), 'satu kali simpan, TTL 900');

        // perilaku sekarang (dikunci CR-164): isi cache TIDAK memuat kunci `cache` — ditempel sesudah remember()
        // (StatistikService.php:163), jadi nilai tersimpan = hasil hitung murni.
        $this->assertArrayNotHasKey('cache', (array) $this->cacheUji->get('dashboard_stat_unit-kerja_kementerian_0_all'));
        $this->assertSame(3600, CacheService::DEFAULT_TTL, 'TTL bawaan CacheService::set tetap 3600 (statistik memakai 900)');
    }

    public function testKunciMemuatSegmenScopeAdminSatker(): void
    {
        $a1 = $this->auth(Role::ADMIN_SATKER, ['id_satker' => (string) $this->satker['A1'], 'id_unit' => (string) $this->unit['A']]);
        $b1 = $this->auth(Role::ADMIN_SATKER, ['id_satker' => (string) $this->satker['B1']]);
        $uA = $this->auth(Role::ADMIN_SATKER, ['id_unit' => (string) $this->unit['A']]);

        $this->assertFalse($this->svc()->statistik($a1, StatistikService::UNIT_KERJA, [])['cache']['hit']);
        $this->assertFalse($this->svc()->statistik($b1, StatistikService::UNIT_KERJA, [])['cache']['hit'], 'satker lain tidak berbagi cache');
        $this->assertFalse($this->svc()->statistik($uA, StatistikService::UNIT_KERJA, [])['cache']['hit']);
        $this->assertFalse(
            $this->svc()->statistik($uA, StatistikService::UNIT_KERJA, ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']])['cache']['hit'],
            'lingkup sama (satker A1) tetapi segmen scope beda (u vs s) → kunci beda',
        );
        $this->assertTrue($this->svc()->statistik($a1, StatistikService::UNIT_KERJA, [])['cache']['hit']);

        $sA1 = $this->satker['A1'];
        $this->assertSame([
            "dashboard_stat_unit-kerja_satker_{$sA1}_s{$sA1}",
            'dashboard_stat_unit-kerja_satker_' . $this->satker['B1'] . '_s' . $this->satker['B1'],
            'dashboard_stat_unit-kerja_unit_' . $this->unit['A'] . '_u' . $this->unit['A'],
            "dashboard_stat_unit-kerja_satker_{$sA1}_u" . $this->unit['A'],
        ], array_column($this->simpanStatistik(), 'key'));
        $this->assertSame([900, 900, 900, 900], array_column($this->simpanStatistik(), 'ttl'));
    }

    public function testSegmenScopeAturanFailClosed(): void
    {
        $this->assertSame('all', StatistikCache::segmenScope($this->auth(Role::SUPER_ADMIN)));
        $this->assertSame('all', StatistikCache::segmenScope($this->auth(Role::MENTERI)));
        $this->assertSame('all', StatistikCache::segmenScope($this->auth(Role::PIMPINAN)));
        $this->assertSame('all', StatistikCache::segmenScope($this->auth(Role::ADMIN_VIEW_ESELON1)));
        $this->assertSame('s7', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '7', 'id_unit' => '3'])), 'satker menang atas unit');
        $this->assertSame('u3', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_unit' => '3'])));
        $this->assertSame('u3', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '', 'id_unit' => '3'])), 'satker string kosong dianggap tidak ada');
        $this->assertSame('none', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '07', 'id_unit' => '3'])), 'satker tidak kanonik → fail-closed');
        $this->assertSame('none', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER)));
        $this->assertSame('none', StatistikCache::segmenScope($this->auth(Role::PEGAWAI, ['id_satker' => '7'])));
        $this->assertSame('none', StatistikCache::segmenScope(new AuthContext()));
        // perilaku sekarang (dikunci CR-164): satker '0' dianggap angka kanonik → segmen 's0' (StatistikCache.php:96-97).
        $this->assertSame('s0', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '0'])));
    }

    public function testInvalidasiDariPegawaiModelMenghapusSemuaKunciStatistikSaja(): void
    {
        $super = $this->auth(Role::SUPER_ADMIN);
        $a1    = $this->auth(Role::ADMIN_SATKER, ['id_satker' => (string) $this->satker['A1']]);
        $this->svc()->statistik($super, StatistikService::UNIT_KERJA, []);
        $this->svc()->statistik($a1, StatistikService::JENIS_KELAMIN, []);
        $this->cacheUji->set('dashboard_lain', ['x' => 1]);
        $this->cacheUji->set('master_unit', ['y' => 1]);

        $model                = new PegawaiModel();
        $aktor                = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satker['A1']), Role::SUPER_ADMIN));
        $this->spy->polaHapus = [];
        $nip                  = $this->nipSintetis();
        $model->withActor($aktor, static fn () => $model->insert(['nip' => $nip, 'nama' => 'Pegawai Baru', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'status' => 1]));

        $this->assertSame(['dashboard_stat_*'], $this->spy->polaHapus, 'afterInsert → satu kali invalidateMatching(dashboard_stat_*)');
        $this->assertNull($this->cacheUji->get('dashboard_stat_unit-kerja_kementerian_0_all'));
        $this->assertNull($this->cacheUji->get('dashboard_stat_jenis-kelamin_satker_' . $this->satker['A1'] . '_s' . $this->satker['A1']), 'segmen Admin Satker ikut terhapus');
        $this->assertSame(['x' => 1], $this->cacheUji->get('dashboard_lain'), 'kunci di luar pola tetap');
        $this->assertSame(['y' => 1], $this->cacheUji->get('master_unit'));

        $this->spy->polaHapus = [];
        $model->withActor($aktor, static fn () => $model->update($nip, ['nama' => 'Pegawai Ubah']));
        $model->withActor($aktor, static fn () => $model->delete($nip));
        $this->assertSame(['dashboard_stat_*', 'dashboard_stat_*'], $this->spy->polaHapus, 'afterUpdate dan afterDelete');

        // perilaku sekarang (dikunci CR-164): tulis tabel `pegawai` lewat query builder (bukan model) TIDAK invalidasi.
        $this->svc()->statistik($super, StatistikService::UNIT_KERJA, []);
        $this->spy->polaHapus = [];
        $this->db->table('pegawai')->where('nip', $nip)->update(['nama' => 'Lewat Builder']);
        $this->assertSame([], $this->spy->polaHapus);
        $this->assertTrue($this->svc()->statistik($super, StatistikService::UNIT_KERJA, [])['cache']['hit']);
    }

    public function testInvalidasiDariEventRiwayatSemuaPayload(): void
    {
        $ada = array_filter(Events::listeners(RiwayatEngine::EVENT_NOTIFIKASI), static fn ($l): bool => $l === [StatistikCache::class, 'invalidasiDariEvent']);

        if ($ada === []) {
            Events::on(RiwayatEngine::EVENT_NOTIFIKASI, [StatistikCache::class, 'invalidasiDariEvent']);
        }

        $super = $this->auth(Role::SUPER_ADMIN);
        $this->svc()->statistik($super, StatistikService::USIA, []);
        $this->assertTrue($this->svc()->statistik($super, StatistikService::USIA, [])['cache']['hit']);

        $this->spy->polaHapus = [];
        Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, []);
        $this->assertSame(['dashboard_stat_*'], $this->spy->polaHapus, 'payload kosong tetap memicu');
        $this->assertFalse($this->svc()->statistik($super, StatistikService::USIA, [])['cache']['hit']);

        // Pemanggilan langsung listener (tanpa argumen) juga menghapus.
        $this->spy->polaHapus = [];
        StatistikCache::invalidasiDariEvent();
        $this->assertSame(['dashboard_stat_*'], $this->spy->polaHapus);
        $this->assertFalse($this->svc()->statistik($super, StatistikService::USIA, [])['cache']['hit']);
    }

    /**
     * @param array<string, string> $klaim
     */
    private function auth(int $role, array $klaim = []): AuthContext
    {
        $a = new AuthContext();
        $a->setClaims(['sub' => '900001', 'role' => $role] + $klaim);

        return $a;
    }

    /**
     * Catatan simpan khusus kunci statistik (cache lain, mis. `web_config_values`, diabaikan).
     *
     * @return list<array{key: string, ttl: int}>
     */
    private function simpanStatistik(): array
    {
        return array_values(array_filter($this->spy->simpan, static fn (array $s): bool => str_starts_with($s['key'], StatistikCache::PREFIKS)));
    }

    private function svc(): StatistikService
    {
        return new StatistikService($this->db, new PegawaiScope(), $this->cacheUji);
    }
}
