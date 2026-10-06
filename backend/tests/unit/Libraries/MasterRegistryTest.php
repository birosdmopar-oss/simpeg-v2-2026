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

    /**
     * CR-010: kode sentinel LAIN-LAIN wilayah = baris sistem (config = satu-satunya sumber untuk aplikasi); field ref
     * ber-allowSystem + isian otherFor-nya diterima registry.
     */
    public function testSystemIdsAndAllowSystemFieldsAreAccepted(): void
    {
        $registry = new MasterRegistry(new MasterDataConfig());

        $expected = ['provinsi' => ['99'], 'kabupaten-kota' => ['9999'], 'kecamatan' => ['9999999'], 'kelurahan' => ['9999999999'], 'agama' => []];

        foreach ($expected as $key => $ids) {
            $this->assertSame($ids, $registry->get($key)->systemIds, $key);
        }

        $this->assertTrue($registry->get('provinsi')->isSystemId('99'));
        $this->assertFalse($registry->get('provinsi')->isSystemId('31'));
        $this->assertSame(['9999'], $registry->get('kabupaten-kota')->toMeta()['system_ids']);

        $config                  = new MasterDataConfig();
        $config->entities['uji'] = [...self::BASE, 'fields' => [
            'id_provinsi'   => ['label' => 'Provinsi', 'type' => 'ref', 'entity' => 'provinsi', 'allowSystem' => true],
            'provinsi_lain' => ['label' => 'Provinsi Lainnya', 'otherFor' => 'id_provinsi'],
        ]];

        $uji = (new MasterRegistry($config))->get('uji');
        $this->assertTrue($uji->field('id_provinsi')?->allowSystem);
        $this->assertSame('id_provinsi', $uji->field('provinsi_lain')?->otherFor);
    }

    /**
     * CR-026 (DBV-008): kelas jabatan = kode sebagai nama (PK alami TINYINT tanpa kolom nama) dengan rentang kode 1–20.
     * Kode kanonik = bilangan bulat tanpa nol di depan ('07' bukan alias 7); kolom PK = kolom nama didaftarkan sekali.
     */
    public function testCodeAsNameWithIdRangeIsAccepted(): void
    {
        $kelas = (new MasterRegistry(new MasterDataConfig()))->get('kelas-jabatan');

        $this->assertTrue($kelas->codeAsName);
        $this->assertSame([1, 20], $kelas->idRange);
        $this->assertSame($kelas->primaryKey, $kelas->nameField);

        foreach (['7', '20', '99'] as $id) {
            $this->assertTrue($kelas->isCanonicalId($id), $id);
        }

        foreach (['07', '0', '-1', '7a', ' 7', '7 '] as $id) {
            $this->assertFalse($kelas->isCanonicalId($id), $id);
        }

        $this->assertSame(['kelas_jabatan', 'tukin', 'status'], $kelas->columns());
        $this->assertSame([true, [1, 20], false], [$kelas->toMeta()['code_as_name'], $kelas->toMeta()['id_range'], $kelas->toMeta()['has_order']]);

        // Master lain: code_as_name false, id_range null (kunci meta selalu ada).
        $agama = (new MasterRegistry(new MasterDataConfig()))->get('agama');
        $this->assertSame([false, null], [$agama->toMeta()['code_as_name'], $agama->toMeta()['id_range']]);
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
            // CR-010: baris sistem & rujukannya.
            'systemIds 9 bukan kode kanonik master ini' => static function (MasterDataConfig $c): void {
                $c->entities['provinsi']['systemIds'] = ['9'];
            },
            'systemIds 0 bukan kode kanonik master ini' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'systemIds' => ['0']];
            },
            'systemIds 99 ditulis lebih dari sekali' => static function (MasterDataConfig $c): void {
                $c->entities['provinsi']['systemIds'] = ['99', '99'];
            },
            'allowSystem field id_agama: master agama tidak punya systemIds' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['id_agama' => ['label' => 'Agama', 'type' => 'ref', 'entity' => 'agama', 'allowSystem' => true]]];
            },
            'otherFor field provinsi_lain harus menunjuk field ref ber-allowSystem' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => [
                    'id_provinsi'   => ['label' => 'Provinsi', 'type' => 'ref', 'entity' => 'provinsi'],
                    'provinsi_lain' => ['label' => 'Provinsi Lainnya', 'otherFor' => 'id_provinsi'],
                ]];
            },
            'otherFor field kota_lain harus menunjuk field ref ber-allowSystem' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'fields' => ['kota_lain' => ['label' => 'Kota Lainnya', 'otherFor' => 'tidak_ada']]];
            },
            // CR-026: kode sebagai nama & rentang kode angka.
            'codeAsName hanya untuk master ber-kode manual dengan nameField = primaryKey' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'codeAsName' => true];
            },
            'master uji tidak valid: codeAsName hanya untuk master ber-kode manual' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'nameField' => 'id_uji', 'codeAsName' => true];
            },
            'nameField sama dengan primaryKey hanya boleh dengan codeAsName' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'autoIncrement' => false, 'nameField' => 'id_uji'];
            },
            'idRange hanya untuk kode manual tanpa idDigits' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'idRange' => [1, 20]];
            },
            'idRange master uji harus [min, max] bilangan bulat dengan 1 <= min <= max' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'autoIncrement' => false, 'idRange' => [0, 20]];
            },
            'idRange master uji harus [min, max]' => static function (MasterDataConfig $c): void {
                $c->entities['uji'] = [...self::BASE, 'autoIncrement' => false, 'idRange' => [20, 1]];
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
