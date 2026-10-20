<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\DataPdfSlip;
use App\Libraries\Presensi\Slip\KopSlip;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Presensi;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Kop PDF slip dari `web_config` (jawaban reviewer Q1/Q3 MAKE-031): logo ASSET_PATH di-resolve dari akar aset v2,
 * path traversal / absolut / ekstensi lain / berkas tidak ada → tanpa logo; nama instansi `nama_kementerian` dengan
 * bawaan literal legacy; akar aset `Config\Presensi::$akarAset` dengan override `.env` tervalidasi.
 *
 * @internal
 */
final class KopSlipTest extends CIUnitTestCase
{
    private string $akar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kopslip-' . bin2hex(random_bytes(4));
        mkdir($this->akar . DIRECTORY_SEPARATOR . 'kop', 0o777, true);
        file_put_contents($this->akar . DIRECTORY_SEPARATOR . 'kop' . DIRECTORY_SEPARATOR . 'logo-kiri.png', 'x');
        file_put_contents($this->akar . DIRECTORY_SEPARATOR . 'logo.jpg', 'x');
        file_put_contents(dirname($this->akar) . DIRECTORY_SEPARATOR . basename($this->akar) . '-luar.png', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->akar . DIRECTORY_SEPARATOR . 'kop' . DIRECTORY_SEPARATOR . 'logo-kiri.png');
        @unlink($this->akar . DIRECTORY_SEPARATOR . 'logo.jpg');
        @unlink(dirname($this->akar) . DIRECTORY_SEPARATOR . basename($this->akar) . '-luar.png');
        @rmdir($this->akar . DIRECTORY_SEPARATOR . 'kop');
        @rmdir($this->akar);
        unset($_ENV['presensi.akarAset']);
        parent::tearDown();
    }

    public function testResolveAsetSah(): void
    {
        $this->assertSame(realpath($this->akar . '/kop/logo-kiri.png'), KopSlip::resolveAset($this->akar, 'kop/logo-kiri.png'));
        $this->assertSame(realpath($this->akar . '/logo.jpg'), KopSlip::resolveAset($this->akar . DIRECTORY_SEPARATOR, 'logo.jpg'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function pathDitolak(): iterable
    {
        yield 'null' => [null];
        yield 'kosong' => [''];
        yield 'spasi' => ['   '];
        yield 'bukan string' => [123];
        yield 'traversal' => ['../' . 'x.png'];
        yield 'traversal tengah' => ['kop/../logo.jpg'];
        yield 'absolut unix' => ['/etc/logo.png'];
        yield 'absolut windows' => ['C:\\logo.png'];
        yield 'garis miring balik' => ['kop\\logo-kiri.png'];
        yield 'ekstensi lain' => ['kop/logo-kiri.gif'];
        yield 'tidak ada' => ['kop/tidak-ada.png'];
        yield 'spasi tepi' => [' logo.jpg'];
    }

    #[DataProvider('pathDitolak')]
    public function testResolveAsetDitolak(mixed $relatif): void
    {
        $this->assertNull(KopSlip::resolveAset($this->akar, $relatif));
    }

    public function testTraversalKeBerkasYangAdaTetapDitolak(): void
    {
        $this->assertNull(KopSlip::resolveAset($this->akar, '../' . basename($this->akar) . '-luar.png'));
    }

    public function testAkarTidakAdaTanpaLogo(): void
    {
        $this->assertNull(KopSlip::resolveAset($this->akar . DIRECTORY_SEPARATOR . 'tidak-ada', 'logo.jpg'));
    }

    public function testDariWebConfigLengkap(): void
    {
        $hasil = KopSlip::dariWebConfig([
            'nama_kementerian'              => ' Kementerian Uji ',
            'pdf_header_nama_kementerian'   => 'Kementerian<br>Uji',
            'pdf_header_nama_unit'          => 'Biro SDM',
            'pdf_header_alamat_kementerian' => 'Jl. Contoh',
            'logo_kementerian_pdf'          => 'kop/logo-kiri.png',
            'logo_wonderful_pdf'            => '../rahasia.png',
        ], $this->akar);

        $this->assertSame(['nama_kementerian' => 'Kementerian<br>Uji', 'nama_unit' => 'Biro SDM', 'alamat' => 'Jl. Contoh'], $hasil['kop']);
        $this->assertSame(realpath($this->akar . '/kop/logo-kiri.png'), $hasil['logoKiri']);
        $this->assertNull($hasil['logoKanan']);
        $this->assertSame('Kementerian Uji', $hasil['namaInstansi']);
    }

    public function testDariWebConfigKosongPakaiBawaan(): void
    {
        $hasil = KopSlip::dariWebConfig(['nama_kementerian' => '', 'pdf_header_nama_kementerian' => null], $this->akar);

        $this->assertSame(['nama_kementerian' => '', 'nama_unit' => '', 'alamat' => ''], $hasil['kop']);
        $this->assertNull($hasil['logoKiri']);
        $this->assertNull($hasil['logoKanan']);
        $this->assertSame(DataPdfSlip::NAMA_INSTANSI_BAWAAN, $hasil['namaInstansi']);

        $hasil = KopSlip::dariWebConfig(['nama_kementerian' => 'Kementerian Uji'], $this->akar);
        $this->assertSame('Kementerian Uji', $hasil['kop']['nama_kementerian'], 'kop jatuh ke nama_kementerian (pola PDF LKH)');
    }

    public function testDirektoriAbsolut(): void
    {
        $this->assertSame('/srv/aset/', Presensi::direktoriAbsolut('/srv/aset'));
        $this->assertSame('/srv/aset/', Presensi::direktoriAbsolut(' /srv/aset// '));
        $this->assertSame('D:/data/aset/', Presensi::direktoriAbsolut('D:/data/aset'));
        $this->assertSame('D:\\data\\aset/', Presensi::direktoriAbsolut('D:\\data\\aset\\'));

        foreach ([null, '', 'aset/', './aset', '/srv/../etc', '/srv/aset/..', "/srv/a\nb", 12] as $tidakSah) {
            $this->assertNull(Presensi::direktoriAbsolut($tidakSah), var_export($tidakSah, true));
        }
    }

    public function testAkarAsetBawaanDanOverrideEnv(): void
    {
        $this->assertSame(WRITEPATH . 'aset/', (new Presensi())->akarAset);

        $_ENV['presensi.akarAset'] = '/srv/aset-v2';
        $this->assertSame('/srv/aset-v2/', (new Presensi())->akarAset);

        $_ENV['presensi.akarAset'] = '../aset';
        $this->assertSame(WRITEPATH . 'aset/', (new Presensi())->akarAset, 'override tidak sah diabaikan');
    }
}
