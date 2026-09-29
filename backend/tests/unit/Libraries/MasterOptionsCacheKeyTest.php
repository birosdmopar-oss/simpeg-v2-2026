<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Exceptions\ValidationException;
use App\Libraries\MasterData\MasterRegistry;
use App\Libraries\MasterData\MasterService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockConnection;
use Config\MasterData as MasterDataConfig;
use Tests\Support\Libraries\RecordingCacheService;

/**
 * CR-009 — kunci cache dropdown (MasterService::options) per master + induk + filter tidak boleh bentrok antar
 * kombinasi berbeda. Dulu `p_<induk>` + `_f<md5 filter>`: induk berkode string (boleh memuat `_`, tetap kanonik)
 * `A_f<md5 filter>` tanpa filter berbagi kunci dengan induk `A` + filter itu, sehingga siapa pun yang bisa memanggil
 * options (UL_ALL) bisa meracuni dropdown terfilter pengguna lain. Cache di-spy (kunci dicatat, query tidak dijalankan).
 *
 * @internal
 */
final class MasterOptionsCacheKeyTest extends CIUnitTestCase
{
    private const BASE = [
        'controller'    => 'UmumController',
        'nameLabel'     => 'Nama',
        'nameMaxLength' => 50,
    ];

    private RecordingCacheService $cache;
    private MasterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $config           = new MasterDataConfig();
        $config->entities = [
            // Induk berkode string bebas (pola bawaan: huruf/angka/titik/strip/garis bawah).
            'uji-rumpun' => [...self::BASE, 'label' => 'Rumpun Uji', 'table' => 'uji_rumpun', 'primaryKey' => 'kode_rumpun', 'nameField' => 'rumpun'],
            'uji-cabang' => [
                ...self::BASE,
                'label'         => 'Cabang Uji',
                'table'         => 'uji_cabang',
                'primaryKey'    => 'id_cabang',
                'autoIncrement' => true,
                'nameField'     => 'cabang',
                'parent'        => ['field' => 'kode_rumpun', 'entity' => 'uji-rumpun'],
                'fields'        => ['flag' => ['label' => 'Flag', 'type' => 'boolean']],
                'filters'       => ['flag'],
            ],
            'uji-bidang'  => [...self::BASE, 'label' => 'Bidang Uji', 'table' => 'uji_bidang', 'primaryKey' => 'id_bidang', 'autoIncrement' => true, 'nameField' => 'bidang'],
            'uji-jurusan' => [
                ...self::BASE,
                'label'         => 'Jurusan Uji',
                'table'         => 'uji_jurusan',
                'primaryKey'    => 'id_jurusan',
                'autoIncrement' => true,
                'nameField'     => 'jurusan',
                'parent'        => ['field' => 'id_bidang', 'entity' => 'uji-bidang'],
            ],
        ];

        $this->cache   = new RecordingCacheService();
        $this->service = new MasterService(new MasterRegistry($config), $this->cache, new MockConnection([]));
    }

    public function testDifferentParentAndFilterCombinationsNeverShareACacheKey(): void
    {
        $def     = $this->service->registry()->get('uji-cabang');
        $crafted = 'A_f' . md5((string) json_encode(['flag' => '1']));

        $combinations = [
            [null, []],
            [null, ['flag' => '1']],
            ['A', []],
            ['A', ['flag' => '1']],
            ['A', ['flag' => '0']],
            [$crafted, []],
            ['A_f', []],
            ['all', []],
            ['B', ['flag' => '1']],
        ];

        $keys = [];

        foreach ($combinations as [$parent, $query]) {
            $this->service->options($def, $parent, $query);
            $keys[] = (string) $this->cache->lastKey();
        }

        $this->assertCount(count($combinations), array_unique($keys), implode("\n", $keys));

        foreach ($keys as $key) {
            // Prefiks yang dipakai invalidate() (master_opt_{key}_*).
            $this->assertStringStartsWith('master_opt_uji-cabang_', $key);
        }

        // Kombinasi yang sama = kunci yang sama (cache tetap terpakai); parameter di luar allowlist tidak ikut kunci.
        $this->service->options($def, 'A', ['flag' => '1', 'lain' => 'x']);
        $this->assertSame($keys[3], $this->cache->lastKey());
    }

    public function testParentMustBeCanonicalAndIsIgnoredForMasterWithoutParent(): void
    {
        $jurusan = $this->service->registry()->get('uji-jurusan');

        // Termasuk bentuk array/objek dari query string (`?parent[]=1`, `?parent[a]=1`, F-OPT/CR-024): dulu dibuang
        // menjadi "tanpa induk" sehingga dropdown memuat seluruh entri lintas induk.
        foreach (['1_f' . md5('x'), '01', '1abc', ' 1', ['1'], ['a' => '1'], [''], 1.0, true] as $parent) {
            $label = (string) json_encode($parent);

            try {
                $this->service->options($jurusan, $parent);
                $this->fail("Induk non-kanonik harus ditolak: {$label}");
            } catch (ValidationException $e) {
                $this->assertSame(['parent' => ['Filter Bidang Uji tidak valid.']], $e->getErrors(), $label);
            }
        }

        $this->assertSame([], $this->cache->keys);

        // Kosong = tanpa filter; kode kanonik (juga int dari pemanggil internal) = filter induk itu.
        $this->service->options($jurusan, '');
        $this->service->options($jurusan, 1);
        $this->service->options($jurusan, '1');
        $this->assertSame(['master_opt_uji-jurusan_all', 'master_opt_uji-jurusan_p_1', 'master_opt_uji-jurusan_p_1'], $this->cache->keys);

        $this->cache->keys = [];
        $bidang            = $this->service->registry()->get('uji-bidang');
        $this->service->options($bidang);
        $this->service->options($bidang, 'apa-saja');
        $this->service->options($bidang, ['1']);
        $this->assertSame(['master_opt_uji-bidang_all', 'master_opt_uji-bidang_all', 'master_opt_uji-bidang_all'], $this->cache->keys);
    }
}
