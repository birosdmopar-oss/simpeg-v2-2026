<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Konket;

/**
 * CR-050 (QA B-F4): daftar id_jenis_konket terlarang — bawaan sama persis dengan legacy (`L_konket.php`), override `.env`
 * hanya bila seluruh tokennya bilangan bulat positif; nilai kosong/salah diabaikan (bawaan tetap dipakai).
 *
 * @internal
 */
final class KonketConfigTest extends CIUnitTestCase
{
    private const KUNCI = ['konket.jenisTerlarangPegawai', 'konket.jenisTerlarangAdmin'];

    protected function tearDown(): void
    {
        foreach (self::KUNCI as $k) {
            putenv($k);
        }

        parent::tearDown();
    }

    public function testBawaanSamaDenganLegacy(): void
    {
        $config = new Konket();

        $this->assertSame([2, 4, 5, 6], $config->jenisTerlarangPegawai);
        $this->assertSame([4, 5], $config->jenisTerlarangAdmin);
    }

    public function testParseDaftar(): void
    {
        $this->assertSame([2, 4, 5], Konket::parseDaftar(' 2, 4,5 '));
        $this->assertSame([7], Konket::parseDaftar('7,7'));

        foreach (['', '  ', '2,', '2,x', '0', '-1', '1.5', '2;4', '01', '99999999999'] as $salah) {
            $this->assertNull(Konket::parseDaftar($salah), var_export($salah, true));
        }
    }

    public function testOverrideEnvTervalidasi(): void
    {
        putenv('konket.jenisTerlarangPegawai=2, 9');
        putenv('konket.jenisTerlarangAdmin=4,abc');

        $config = new Konket();

        $this->assertSame([2, 9], $config->jenisTerlarangPegawai, 'nilai sah dipakai');
        $this->assertSame([4, 5], $config->jenisTerlarangAdmin, 'nilai salah diabaikan → bawaan legacy');

        putenv('konket.jenisTerlarangPegawai=');
        $this->assertSame([2, 4, 5, 6], (new Konket())->jenisTerlarangPegawai, 'kosong diabaikan');
    }
}
