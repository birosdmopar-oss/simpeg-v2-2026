<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Libraries\Dashboard\Statistik\LingkupStatistik;
use App\Libraries\Dashboard\Statistik\StatistikCache;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;

/**
 * F7-12 key cache statistik (MAKE-036): memuat jenis, lingkup, id, dan segmen scope akun — key Admin Satker satker A ≠
 * satker B, TTL 900, invalidasi memakai pola `dashboard_stat_*`.
 *
 * @internal
 */
final class StatistikCacheKeyTest extends CIUnitTestCase
{
    public function testKeyMemuatJenisLingkupIdDanScope(): void
    {
        $kemen  = new LingkupStatistik(LingkupStatistik::KEMENTERIAN, null, 'Kementerian');
        $satker = new LingkupStatistik(LingkupStatistik::SATKER, 37, 'Satker 37');

        $this->assertSame('dashboard_stat_unit-kerja_kementerian_0_all', StatistikCache::key('unit-kerja', $kemen, $this->auth(Role::SUPER_ADMIN)));
        $this->assertSame('dashboard_stat_usia_satker_37_all', StatistikCache::key('usia', $satker, $this->auth(Role::PIMPINAN)));
        $this->assertSame('dashboard_stat_usia_satker_37_s37', StatistikCache::key('usia', $satker, $this->auth(Role::ADMIN_SATKER, ['id_satker' => '37', 'id_unit' => '7'])));
    }

    public function testSegmenScopeAdminSatkerBerbedaPerSatkerDanUnit(): void
    {
        $this->assertSame('s37', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '37', 'id_unit' => '7'])));
        $this->assertSame('s38', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '38', 'id_unit' => '7'])));
        $this->assertSame('u21', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => null, 'id_unit' => '21'])));
        $this->assertSame('u21', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '', 'id_unit' => '21'])));
        // Satker ada tetapi bukan angka kanonik → fail-closed (sama dengan PegawaiScope).
        $this->assertSame('none', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => '012', 'id_unit' => '21'])));
        $this->assertSame('none', StatistikCache::segmenScope($this->auth(Role::ADMIN_SATKER, ['id_satker' => null, 'id_unit' => null])));
        $this->assertSame('none', StatistikCache::segmenScope($this->auth(Role::PEGAWAI)));
        $this->assertSame('none', StatistikCache::segmenScope(new AuthContext()));
    }

    public function testRoleLihatSemuaBerbagiSegmenAll(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->assertSame('all', StatistikCache::segmenScope($this->auth($role, ['id_satker' => '37'])), "role {$role}");
        }
    }

    public function testInvalidasiMenghapusSemuaKeyStatistikSaja(): void
    {
        $cache = new CacheService(new MockCache(), '');
        $cache->set('dashboard_stat_usia_satker_37_s37', ['a']);
        $cache->set('dashboard_stat_golongan_kementerian_0_all', ['b']);
        $cache->set('master_opt_agama_x', ['c']);

        StatistikCache::invalidasi($cache);

        $this->assertNull($cache->get('dashboard_stat_usia_satker_37_s37'));
        $this->assertNull($cache->get('dashboard_stat_golongan_kementerian_0_all'));
        $this->assertSame(['c'], $cache->get('master_opt_agama_x'));
        $this->assertSame(900, StatistikCache::TTL);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function auth(int $role, array $claims = []): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims($claims + ['sub' => '1', 'role' => $role, 'nip' => null, 'id_satker' => null, 'id_unit' => null]);

        return $auth;
    }
}
