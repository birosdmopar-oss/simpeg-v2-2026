<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\MasterData\MasterRegistry;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use Config\MasterData as MasterDataConfig;
use LogicException;
use Tests\Support\Config\MasterDataUji;

/**
 * CR-009 — MasterRegistry memeriksa konsistensi konfigurasi master saat dibangun (rujukan antar-master, field yang
 * disebut opsi engine, rantai induk melingkar), sehingga salah konfigurasi grup DBV gagal di test pertama.
 *
 * @internal
 */
final class MasterRegistryTest extends CIUnitTestCase
{
    /**
     * Entri minimal yang valid; tiap kasus menimpa/menambah opsi di atasnya.
     */
    private const BASE = [
        'label'         => 'Uji',
        'controller'    => 'UmumController',
        'table'         => 'uji',
        'primaryKey'    => 'id_uji',
        'autoIncrement' => true,
        'nameField'     => 'nama',
        'nameLabel'     => 'Nama',
        'nameMaxLength' => 50,
    ];

    public function testRealAndTestConfigsAreConsistent(): void
    {
        $this->assertTrue((new MasterRegistry(new MasterDataConfig()))->has('faq-article'));

        $registry = new MasterRegistry(new MasterDataUji());
        $this->assertSame(['uji-bidang'], $registry->ancestorsOf($registry->get('uji-jurusan')));
        $this->assertSame(['kecamatan', 'kabupaten-kota', 'provinsi'], $registry->ancestorsOf($registry->get('kelurahan')));
        $this->assertSame([], $registry->ancestorsOf($registry->get('agama')));
    }

    public function testInconsistentConfigsAreRejected(): void
    {
        $cases = [
            'induk tidak-ada tidak terdaftar' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'parent' => ['field' => 'id_x', 'entity' => 'tidak-ada']];
            },
            'rantai induk melingkar' => static function (MasterDataConfig $c): void {
                $c->entities['uji']     = [...self::BASE, 'parent' => ['field' => 'id_dua', 'entity' => 'uji-dua']];
                $c->entities['uji-dua'] = [...self::BASE, 'table' => 'uji_dua', 'parent' => ['field' => 'id_uji', 'entity' => 'uji']];
            },
            'statusChain hanya untuk master berinduk' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'statusChain' => true];
            },
            'field ref id_x merujuk master tidak-ada yang tidak terdaftar' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['id_x' => ['label' => 'X', 'type' => 'ref', 'entity' => 'tidak-ada']]];
            },
            'dependsOn field id_kab harus field ref lain' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => [
                    'kode'   => ['label' => 'Kode'],
                    'id_kab' => ['label' => 'Kabupaten', 'type' => 'ref', 'entity' => 'kabupaten-kota', 'dependsOn' => 'kode'],
                ]];
            },
            'induk master kabupaten-kota bukan agama' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => [
                    'id_agama' => ['label' => 'Agama', 'type' => 'ref', 'entity' => 'agama'],
                    'id_kab'   => ['label' => 'Kabupaten', 'type' => 'ref', 'entity' => 'kabupaten-kota', 'dependsOn' => 'id_agama'],
                ]];
            },
            'orderScope hanya untuk master yang memakai kolom order' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'hasOrder' => false, 'fields' => ['jenis' => ['label' => 'Jenis', 'type' => 'select', 'required' => true, 'options' => [1 => 'A']]], 'orderScope' => ['jenis']];
            },
            'orderScope jenis harus field wajib' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['jenis' => ['label' => 'Jenis', 'type' => 'select', 'options' => [1 => 'A']]], 'orderScope' => ['jenis']];
            },
            // Tanpa filter lingkup, daftar admin mencampur beberapa lingkup urutan (panah naik/turun FE salah hitung).
            'orderScope jenis harus ikut filters' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['jenis' => ['label' => 'Jenis', 'type' => 'select', 'required' => true, 'options' => [1 => 'A']]], 'orderScope' => ['jenis']];
            },
            'uniqueFields kode bukan field' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'uniqueFields' => ['kode']];
            },
            'lingkup uniqueFields kode (jenis) bukan field/induk' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['kode' => ['label' => 'Kode']], 'uniqueFields' => ['kode' => ['jenis']]];
            },
            'filter search harus field master ini dan bukan parameter query engine' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['search' => ['label' => 'Cari']], 'filters' => ['search']];
            },
            'filter tidak_ada harus field master ini' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'filters' => ['tidak_ada']];
            },
            'orderMode master uji tidak dikenal: zigzag' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'orderMode' => 'zigzag'];
            },
            'Tipe kolom bilangan bulat tidak dikenal: byte' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'orderColumnType' => 'byte'];
            },
        ];

        foreach ($cases as $expected => $configure) {
            $this->assertRegistryRejects($configure, $expected);
        }
    }

    /**
     * @param Closure(MasterDataConfig): void $configure
     */
    private function assertRegistryRejects(Closure $configure, string $expected): void
    {
        $config = new MasterDataConfig();
        $configure($config);

        try {
            new MasterRegistry($config);
            $this->fail("Konfigurasi tidak valid harus ditolak: {$expected}");
        } catch (LogicException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }
}
