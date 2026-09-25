<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\MasterData\MasterField;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;

/**
 * CR-009 — MasterField: batas angka per tipe kolom, boolean 1/0, ref, nilai filter, dan metadata. Tipe lama
 * (text/select/html) tidak berubah.
 *
 * @internal
 */
final class MasterFieldTest extends CIUnitTestCase
{
    public function testIntRulesFollowColumnTypeRange(): void
    {
        $cases = [
            [null, 0, 2147483647],
            ['tinyint', 0, 127],
            ['tinyint unsigned', 0, 255],
            ['smallint', 0, 32767],
            ['smallint unsigned', 0, 65535],
            ['mediumint unsigned', 0, 16777215],
            ['int unsigned', 0, 4294967295],
            ['bigint', 0, PHP_INT_MAX],
        ];

        foreach ($cases as [$columnType, $min, $max]) {
            $field = new MasterField('nilai', 'Nilai', MasterField::TYPE_INT, columnType: $columnType);

            $this->assertSame([$min, $max], $field->bounds(), (string) $columnType);
            $this->assertSame(
                "permit_empty|is_natural|greater_than_equal_to[{$min}]|less_than_equal_to[{$max}]",
                $field->validationRules(true),
                (string) $columnType,
            );
        }

        $messages = (new MasterField('bobot', 'Bobot', MasterField::TYPE_INT, columnType: 'tinyint'))->validationMessages();
        $this->assertSame('Bobot maksimal 127.', $messages['less_than_equal_to']);
        $this->assertSame('Bobot minimal 0.', $messages['greater_than_equal_to']);
        $this->assertSame('Bobot harus bilangan bulat tidak negatif.', $messages['is_natural']);

        $big = (new MasterField('kuota', 'Kuota', MasterField::TYPE_INT, columnType: 'int unsigned'))->validationMessages();
        $this->assertSame('Kuota maksimal 4.294.967.295.', $big['less_than_equal_to']);
    }

    public function testExplicitBoundsAreClampedToColumnType(): void
    {
        $this->assertSame([1, 100], (new MasterField('a', 'A', MasterField::TYPE_INT, min: 1, max: 100, columnType: 'tinyint'))->bounds());
        $this->assertSame([0, 127], (new MasterField('a', 'A', MasterField::TYPE_INT, max: 500, columnType: 'tinyint'))->bounds());
        $this->assertSame([-128, 127], (new MasterField('a', 'A', MasterField::TYPE_INT, min: -500, columnType: 'tinyint'))->bounds());
        $this->assertSame([0, 255], (new MasterField('a', 'A', MasterField::TYPE_INT, min: -5, columnType: 'tinyint unsigned'))->bounds());

        // Min negatif = bilangan bulat bertanda (integer), bukan is_natural.
        $signed = new MasterField('selisih', 'Selisih', MasterField::TYPE_INT, required: true, min: -5, columnType: 'tinyint');
        $this->assertSame('required|integer|greater_than_equal_to[-5]|less_than_equal_to[127]', $signed->validationRules(true));
        $this->assertSame('if_exist|required|integer|greater_than_equal_to[-5]|less_than_equal_to[127]', $signed->validationRules(false));
        $this->assertSame('Selisih minimal -5.', $signed->validationMessages()['greater_than_equal_to']);
    }

    public function testDecimalHasOnlyExplicitBounds(): void
    {
        $this->assertSame('permit_empty|decimal', (new MasterField('lat', 'Lintang', MasterField::TYPE_DECIMAL))->validationRules(true));
        $this->assertSame([null, null], (new MasterField('lat', 'Lintang', MasterField::TYPE_DECIMAL))->bounds());

        $uang = new MasterField('uang_makan', 'Uang Makan', MasterField::TYPE_DECIMAL, min: 0, max: 999999.5);
        $this->assertSame('permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[999999.5]', $uang->validationRules(true));
        $this->assertSame('Uang Makan maksimal 999.999,5.', $uang->validationMessages()['less_than_equal_to']);
    }

    public function testBooleanAcceptsOneOrZeroAndNormalizesToInt(): void
    {
        $flag = new MasterField('D_III', 'D-III', MasterField::TYPE_BOOLEAN);
        $this->assertSame('permit_empty|in_list[0,1]', $flag->validationRules(true));
        $this->assertSame('D-III hanya boleh 1 (ya) atau 0 (tidak).', $flag->validationMessages()['in_list']);

        foreach ([[true, 1], ['1', 1], [1, 1], [false, 0], ['0', 0], [0, 0], ['', 0], [null, 0]] as [$input, $expected]) {
            $this->assertSame($expected, $flag->normalize($input), var_export($input, true));
        }

        $this->assertSame([null, null], $flag->bounds());
    }

    public function testRefValidatesShapeAndNormalizesCode(): void
    {
        $ref = new MasterField('id_kabupaten', 'Kabupaten/Kota', MasterField::TYPE_REF, entity: 'kabupaten-kota', dependsOn: 'id_provinsi');
        $this->assertSame('permit_empty|max_length[4]', $ref->validationRules(true, 4));
        $this->assertSame('if_exist|permit_empty|max_length[255]', $ref->validationRules(false));
        $this->assertSame('Kabupaten/Kota tidak valid.', $ref->validationMessages()['max_length']);
        $this->assertNull($ref->normalize(''));
        $this->assertSame('3171', $ref->normalize(' 3171 '));
        $this->assertSame('31', $ref->normalize(31));

        $meta = $ref->toMeta();
        $this->assertSame(['ref', 'kabupaten-kota', 'id_provinsi', null, null], [$meta['type'], $meta['entity'], $meta['depends_on'], $meta['min'], $meta['max']]);
    }

    public function testFilterValuesAreCheckedPerType(): void
    {
        $select = new MasterField('jenis', 'Jenis', MasterField::TYPE_SELECT, options: [1 => 'Struktural', 2 => 'Teknis']);
        $this->assertTrue($select->acceptsFilterValue('2'));
        $this->assertFalse($select->acceptsFilterValue('3'));
        $this->assertFalse($select->acceptsFilterValue('1 OR 1=1'));

        $flag = new MasterField('cpns', 'CPNS', MasterField::TYPE_BOOLEAN);
        $this->assertTrue($flag->acceptsFilterValue('0'));
        $this->assertFalse($flag->acceptsFilterValue('true'));

        $int = new MasterField('old_id', 'Kode Lama', MasterField::TYPE_INT);
        $this->assertTrue($int->acceptsFilterValue('13'));
        $this->assertFalse($int->acceptsFilterValue('13a'));

        $ref = new MasterField('id_provinsi', 'Provinsi', MasterField::TYPE_REF, entity: 'provinsi');
        $this->assertTrue($ref->acceptsFilterValue('31'));
        $this->assertFalse($ref->acceptsFilterValue("31'--"));

        $text = new MasterField('gol', 'Golongan');
        $this->assertTrue($text->acceptsFilterValue('III/a'));
        $this->assertFalse($text->acceptsFilterValue("\xC3("));
        $this->assertFalse($text->acceptsFilterValue(str_repeat('a', 256)));
    }

    /**
     * Tipe lama tidak berubah rule-nya (regresi Batch 1 & FAQ); meta tetap mengirim kunci baru bernilai null.
     */
    public function testExistingTypesAreUnchanged(): void
    {
        $this->assertSame('permit_empty|string|max_length[4]', (new MasterField('kd_area', 'Kode Area', rules: 'max_length[4]'))->validationRules(true));
        $this->assertSame(
            'required|in_list[1,2]',
            (new MasterField('status_pegawai', 'Status Pegawai', MasterField::TYPE_SELECT, true, options: [1 => 'Aktif', 2 => 'Tidak Aktif']))->validationRules(true),
        );
        $this->assertSame('if_exist|permit_empty|string|max_byte_length[255]', (new MasterField('remark', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255))->validationRules(false));
        $this->assertSame('Kode Area maksimal 4 karakter.', (new MasterField('kd_area', 'Kode Area', rules: 'max_length[4]'))->validationMessages()['max_length']);

        $meta = (new MasterField('remark', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255))->toMeta();
        $this->assertSame([255, null, null, null, null], [$meta['max_bytes'], $meta['min'], $meta['max'], $meta['entity'], $meta['depends_on']]);
    }

    public function testInvalidDefinitionsAreRejected(): void
    {
        $cases = [
            'Tipe field master a tidak dikenal: angka'         => static fn () => new MasterField('a', 'A', 'angka'),
            'columnType field master a tidak dikenal: integer' => static fn () => new MasterField('a', 'A', MasterField::TYPE_INT, columnType: 'integer'),
            'Field ref a wajib menyebut entity'                => static fn () => new MasterField('a', 'A', MasterField::TYPE_REF),
            'hanya untuk field bertipe ref (a)'                => static fn () => new MasterField('a', 'A', MasterField::TYPE_SELECT, entity: 'provinsi'),
        ];

        foreach ($cases as $expected => $make) {
            try {
                $make();
                $this->fail("Definisi field tidak valid harus ditolak: {$expected}");
            } catch (LogicException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }
}
