<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;
use App\Libraries\Kepegawaian\Kalkulasi\KenaikanPangkat;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-025 (K-CR025-4) — hasil gagal atas input pengguna dipetakan ke 422 per field (ADR-001).
 *
 * @internal
 */
final class HasilKalkulasiTest extends CIUnitTestCase
{
    public function testGagalMelemparValidationException422PerField(): void
    {
        $hasil = KenaikanPangkat::validasiTmt('2026-04-15');

        try {
            $hasil->lemparBilaGagal('tmtsk');
            $this->fail('ValidationException tidak dilempar');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(422, $e->getCode());
            $this->assertSame($hasil->pesan, $e->getMessage());
            $this->assertSame(['tmtsk' => [$hasil->pesan]], $e->getErrors());
        }
    }

    public function testBerhasilMengembalikanInstanceYangSama(): void
    {
        $hasil = HasilKalkulasi::berhasil('2026-04-01', ['a' => 1]);

        $this->assertSame($hasil, $hasil->lemparBilaGagal('tmtsk'));
        $this->assertTrue($hasil->valid);
        $this->assertSame('2026-04-01', $hasil->tanggal);
        $this->assertNull($hasil->kode);
        $this->assertNull($hasil->pesan);
        $this->assertSame(['a' => 1], $hasil->detail);
    }

    public function testBerhasilDenganInfoTidakMelempar(): void
    {
        $hasil = HasilKalkulasi::berhasil(null, [], 'tanpa_masa', 'Info');

        $this->assertSame($hasil, $hasil->lemparBilaGagal('akhir_hukdis'));
    }

    public function testGagal(): void
    {
        $hasil = HasilKalkulasi::gagal('kode_x', 'Pesan X.', ['k' => 'v']);

        $this->assertFalse($hasil->valid);
        $this->assertNull($hasil->tanggal);
        $this->assertSame('kode_x', $hasil->kode);
        $this->assertSame('Pesan X.', $hasil->pesan);
        $this->assertSame(['k' => 'v'], $hasil->detail);
    }
}
