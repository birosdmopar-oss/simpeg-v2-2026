<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Commands\CutiSaldoTahunan;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * CR-080 — `cuti:saldo-tahunan` menolak opsi salah dengan EXIT_USER_INPUT + pemakaian sebelum menyentuh database: opsi
 * tak dikenal (mis. `--tahun=2027`), `--nip` tanpa nilai (dulu diam-diam berarti semua pegawai), `--dry-run` yang menelan
 * argumen tahun, dan argumen berlebih. Kedua bentuk `--nip=` / `--nip <nip>` diuji dengan database di
 * `Layanan/Kuota/CutiSaldoTahunanCommandTest`.
 *
 * @internal
 */
final class CutiSaldoTahunanOpsiTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    public function testOpsiSalahDitolakSebelumDatabase(): void
    {
        foreach ([
            'tak dikenal'         => [0 => '2027', 'tahun=2027' => null],
            'tak dikenal spasi'   => [0 => '2027', 'nips' => 'X1'],
            'nip tanpa nilai ='   => [0 => '2027', 'nip=' => null],
            'nip tanpa nilai'     => [0 => '2027', 'nip' => null],
            'dry-run bernilai'    => ['dry-run' => '2027'],
            'nip=v menelan tahun' => ['nip=X1' => '2027'],
            'argumen berlebih'    => [0 => '2027', 1 => '2028'],
            'tahun kosong'        => ['dry-run' => null],
        ] as $kasus => $params) {
            $this->resetStreamFilterBuffer();
            $this->assertSame(EXIT_USER_INPUT, (new CutiSaldoTahunan(service('logger'), service('commands')))->run($params), $kasus);
            $this->assertStringContainsString('Pemakaian: cuti:saldo-tahunan', $this->getStreamFilterBuffer(), $kasus);
            $this->assertStringNotContainsString('Saldo cuti tahun', $this->getStreamFilterBuffer(), $kasus);
        }
    }
}
