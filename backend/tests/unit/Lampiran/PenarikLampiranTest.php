<?php

declare(strict_types=1);

namespace Tests\Unit\Lampiran;

use App\Libraries\Lampiran\PemeriksaLampiran;
use App\Libraries\Lampiran\PenarikLampiran;
use App\Libraries\Lampiran\TransportHttp;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * MAKE-069 K-M4 opsi 1 — `lampiran:tarik` dengan transport palsu (TANPA jaringan): validasi URL/SSRF, resume checksum,
 * 404/5xx/timeout, deteksi halaman login, tulis atomik; lalu `lampiran:periksa`.
 *
 * @internal
 */
final class PenarikLampiranTest extends CIUnitTestCase
{
    private const DASAR = ['arsip' => 'https://arsip.contoh.test/simpeg', 'aplikasi' => 'https://app.contoh.test/'];

    private string $akar;

    /**
     * @var list<string>
     */
    private array $diminta = [];

    /**
     * @var array<string, list<array{status: int, tipe: string, lokasi: string|null, isi: string}>>
     */
    private array $respons = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->akar = WRITEPATH . 'uji_tarik_' . bin2hex(random_bytes(4));
        mkdir($this->akar, 0750, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->akar, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }

        rmdir($this->akar);
        parent::tearDown();
    }

    private function transport(): TransportHttp
    {
        return new class ($this) implements TransportHttp {
            public function __construct(private readonly PenarikLampiranTest $t)
            {
            }

            public function ambil(string $url): array
            {
                return $this->t->jawab($url);
            }
        };
    }

    /**
     * @return array{status: int, tipe: string, lokasi: string|null, isi: string}
     */
    public function jawab(string $url): array
    {
        $this->diminta[] = $url;

        return array_shift($this->respons[$url]) ?? ['status' => 404, 'tipe' => 'text/plain', 'lokasi' => null, 'isi' => ''];
    }

    private function penarik(int $ambang = 5): PenarikLampiran
    {
        return new PenarikLampiran($this->transport(), self::DASAR, $this->akar, 10, 2, $ambang, static function (int $ms): void {
        });
    }

    /**
     * @param array<string, string> $path path → sumber
     *
     * @return array{versi: int, tujuan: string, berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>, tidak_sah: array<string, int>}
     */
    private static function manifest(array $path): array
    {
        $berkas = [];

        foreach ($path as $p => $s) {
            $berkas[$p] = ['sumber' => $s, 'rujukan' => ['document_attachment.path' => 1], 'sha256' => null, 'hasil' => null];
        }

        return ['versi' => 1, 'tujuan' => 'uji', 'berkas' => $berkas, 'tidak_sah' => []];
    }

    private static function ok(string $isi, string $tipe = 'application/pdf'): array
    {
        return ['status' => 200, 'tipe' => $tipe, 'lokasi' => null, 'isi' => $isi];
    }

    public function testUrlDariAlamatDasarDanPathTervalidasi(): void
    {
        $p = $this->penarik();

        $this->assertSame('https://arsip.contoh.test/simpeg/arsip/rw%20jabatan/SK%2001.pdf', $p->url('arsip', 'arsip/rw jabatan/SK 01.pdf'));
        $this->assertNull($p->url('arsip', 'arsip/../rahasia'), 'traversal');
        $this->assertNull($p->url('arsip', '/arsip/a.pdf'), 'manifest wajib sudah ternormalisasi');
        $this->assertNull($p->url('arsip', 'http://host-lain/a.pdf'), 'host/skema dari path ditolak (SSRF)');
        $this->assertNull($p->url('lain', 'arsip/a.pdf'), 'sumber tanpa alamat dasar');
    }

    public function testAlamatDasarDitolakBilaBukanHttpAtauBerkredensial(): void
    {
        foreach (['ftp://x.test/', 'https://user:rahasia@x.test/', 'https://x.test/?a=1', 'x.test', 'file:///etc', 'http://x.test/'] as $salah) {
            try {
                PenarikLampiran::alamatDasar($salah);
                $this->fail("Alamat dasar {$salah} harus ditolak.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('https://x.test/a/', PenarikLampiran::alamatDasar('https://x.test/a'));
    }

    public function testTarikResumeDan404DanRetry5xx(): void
    {
        $u             = 'https://arsip.contoh.test/simpeg/arsip/';
        $this->respons = [
            $u . 'a.pdf' => [self::ok('isi-a')],
            $u . 'b.pdf' => [],
            $u . 'c.pdf' => [['status' => 503, 'tipe' => '', 'lokasi' => null, 'isi' => ''], ['status' => 0, 'tipe' => '', 'lokasi' => null, 'isi' => ''], self::ok('isi-c')],
            $u . 'd.pdf' => array_fill(0, 3, ['status' => 500, 'tipe' => '', 'lokasi' => null, 'isi' => '']),
        ];
        $m = self::manifest(['arsip/a.pdf' => 'arsip', 'arsip/b.pdf' => 'arsip', 'arsip/c.pdf' => 'arsip', 'arsip/d.pdf' => 'arsip']);

        $hasil = $this->penarik()->tarik($m);

        $this->assertSame(['berhasil' => 2, '404' => 1, 'galat' => 1], $hasil['per_sumber']['arsip']);
        $this->assertSame(1, count(array_keys($this->diminta, $u . 'b.pdf')), '404 tidak diulang');
        $this->assertSame(3, count(array_keys($this->diminta, $u . 'd.pdf')), '5xx diulang sampai batas');
        $this->assertSame('isi-a', file_get_contents($this->akar . '/arsip/a.pdf'));
        $this->assertSame(hash('sha256', 'isi-a'), $m['berkas']['arsip/a.pdf']['sha256']);
        $this->assertSame([], glob($this->akar . '/arsip/.tarik-*') ?: [], 'tidak ada berkas sementara tersisa');

        // Ulang (delta): checksum sama → dilewati tanpa permintaan jaringan.
        $this->diminta = [];
        $hasil2        = $this->penarik()->tarik($m);
        $this->assertSame(2, $hasil2['per_sumber']['arsip']['dilewati']);
        $this->assertNotContains($u . 'a.pdf', $this->diminta);

        $periksa = PemeriksaLampiran::periksa($m, $this->akar);
        $this->assertSame(['ada' => 2, 'hilang' => 2, 'beda_checksum' => 0, 'tidak_sah' => 0], $periksa['document_attachment.path']);

        file_put_contents($this->akar . '/arsip/a.pdf', 'diubah');
        $this->assertSame(1, PemeriksaLampiran::periksa($m, $this->akar)['document_attachment.path']['beda_checksum']);
    }

    public function testHalamanLoginDihitungDanBerhentiDiAmbang(): void
    {
        $u             = 'https://app.contoh.test/assets/upload/';
        $this->respons = [
            $u . '1.jpg' => [self::ok('<html>Login</html>', 'text/html; charset=UTF-8')],
            $u . '2.jpg' => [['status' => 302, 'tipe' => '', 'lokasi' => 'https://app.contoh.test/auth/login', 'isi' => '']],
            $u . '3.jpg' => [self::ok('jpg')],
        ];
        $m = self::manifest(['assets/upload/1.jpg' => 'aplikasi', 'assets/upload/2.jpg' => 'aplikasi', 'assets/upload/3.jpg' => 'aplikasi']);

        $hasil = $this->penarik(2)->tarik($m);

        $this->assertTrue($hasil['berhenti']);
        $this->assertSame(['butuh_login' => 2], $hasil['per_sumber']['aplikasi']);
        $this->assertNotContains($u . '3.jpg', $this->diminta, 'berhenti sebelum berkas berikutnya');
        $this->assertFileDoesNotExist($this->akar . '/assets/upload/1.jpg', 'halaman login tidak disimpan');
    }

    public function testRedirectKeHostLainBukanLoginDihitungGalatDanTidakDiikuti(): void
    {
        $u             = 'https://app.contoh.test/assets/upload/x.pdf';
        $this->respons = [$u => [['status' => 301, 'tipe' => '', 'lokasi' => 'https://host-lain.test/x.pdf', 'isi' => '']]];
        $m             = self::manifest(['assets/upload/x.pdf' => 'aplikasi']);

        $hasil = $this->penarik()->tarik($m);

        $this->assertSame(['galat' => 1], $hasil['per_sumber']['aplikasi']);
        $this->assertSame([$u], $this->diminta, 'Location tidak pernah diminta');
    }

    public function testHttpHanyaDenganIzinEksplisit(): void
    {
        try {
            PenarikLampiran::alamatDasar('http://x.test/');
            $this->fail('http tanpa izin harus ditolak (review P-7).');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('http://x.test/', PenarikLampiran::alamatDasar('http://x.test', true));
    }

    public function testResponsMelebihiBatasTidakDiulang(): void
    {
        $u             = 'https://arsip.contoh.test/simpeg/arsip/besar.pdf';
        $this->respons = [$u => [['status' => -1, 'tipe' => '', 'lokasi' => null, 'isi' => '']]];
        $m             = self::manifest(['arsip/besar.pdf' => 'arsip']);

        $this->assertSame(['galat' => 1], $this->penarik()->tarik($m)['per_sumber']['arsip']);
        $this->assertSame([$u], $this->diminta, 'batas ukuran tidak di-retry (review S-3)');
    }

    public function testPemeriksaMenolakPathManifestTidakSah(): void
    {
        $m = self::manifest(['arsip/../rahasia' => 'arsip', '/arsip/a.pdf' => 'arsip']);

        $this->assertSame(['ada' => 0, 'hilang' => 0, 'beda_checksum' => 0, 'tidak_sah' => 2], PemeriksaLampiran::periksa($m, $this->akar)['document_attachment.path']);
    }
}
