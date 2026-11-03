<?php

declare(strict_types=1);

namespace Tests\Unit\Lampiran;

use App\Libraries\Lampiran\ManifestLampiran;
use App\Libraries\Lampiran\TransportHttp;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;

/**
 * MAKE-069 K-M4 — command `lampiran:tarik` dengan transport palsu (tanpa jaringan): alamat dasar dari env, saringan
 * `--sumber`, `--izinkan-http`, pesan berhenti, dan keluaran tanpa host/URL/path.
 *
 * @internal
 */
final class LampiranTarikCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private string $kerja;

    /**
     * @var list<string>
     */
    public array $diminta = [];

    protected function setUp(): void
    {
        parent::setUp();

        // command() mengurai argumen seperti shell: backslash Windows hilang, jadi pakai garis miring.
        $this->kerja = str_replace('\\', '/', WRITEPATH) . 'uji_tarik_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->kerja . '/akar', 0750, true);
        ManifestLampiran::tulis($this->kerja . '/m.json', [
            'versi'  => 1, 'tujuan' => 'uji', 'tidak_sah' => [],
            'berkas' => [
                'arsip/sk rahasia.pdf'     => ['sumber' => 'arsip', 'rujukan' => ['document_attachment.path' => 1], 'sha256' => null, 'hasil' => null],
                'assets/upload/foto/1.jpg' => ['sumber' => 'aplikasi', 'rujukan' => ['pegawai.foto' => 1], 'sha256' => null, 'hasil' => null],
            ],
        ]);

        $uji = $this;
        Services::injectMock('transportLampiran', new class ($uji) implements TransportHttp {
            public function __construct(private readonly LampiranTarikCommandTest $t)
            {
            }

            public function ambil(string $url): array
            {
                $this->t->diminta[] = $url;

                return str_contains($url, 'login-dulu')
                    ? ['status' => 200, 'tipe' => 'text/html', 'lokasi' => null, 'isi' => '<html>login</html>']
                    : ['status' => 200, 'tipe' => 'application/octet-stream', 'lokasi' => null, 'isi' => 'isi'];
            }
        });
    }

    protected function tearDown(): void
    {
        Services::resetSingle('transportLampiran');
        putenv('LAMPIRAN_DASAR_ARSIP');

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->kerja, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }

        rmdir($this->kerja);
        parent::tearDown();
    }

    public function testEnvFallbackSaringanSumberDanKeluaranTanpaHostAtauPath(): void
    {
        putenv('LAMPIRAN_DASAR_ARSIP=https://arsip.rahasia.test/dasar');

        command("lampiran:tarik --manifest={$this->kerja}/m.json --akar={$this->kerja}/akar --sumber=arsip --jeda-ms=0");
        $keluaran = $this->getStreamFilterBuffer();

        $this->assertSame(['https://arsip.rahasia.test/dasar/arsip/sk%20rahasia.pdf'], $this->diminta, 'hanya sumber arsip, alamat dari env');
        $this->assertStringContainsString('sumber arsip: berhasil=1 dilewati=0 404=0 galat=0 butuh_login=0', $keluaran);
        $this->assertStringContainsString('Penarikan selesai.', $keluaran);
        $this->assertStringNotContainsString('rahasia', $keluaran, 'tanpa host/path di keluaran');
        $this->assertFileExists($this->kerja . '/akar/arsip/sk rahasia.pdf');
        $this->assertSame('berhasil', ManifestLampiran::baca($this->kerja . '/m.json')['berkas']['arsip/sk rahasia.pdf']['hasil'], 'manifest diperbarui');
        $this->assertNull(ManifestLampiran::baca($this->kerja . '/m.json')['berkas']['assets/upload/foto/1.jpg']['hasil'], 'sumber lain tidak disentuh');
    }

    public function testHttpTanpaIzinDitolakDanDenganIzinMemberiPeringatan(): void
    {
        command("lampiran:tarik --manifest={$this->kerja}/m.json --akar={$this->kerja}/akar --dasar-aplikasi=http://app.test --jeda-ms=0");
        $this->assertStringContainsString('http hanya dengan --izinkan-http', $this->getStreamFilterBuffer());
        $this->assertSame([], $this->diminta);

        command("lampiran:tarik --manifest={$this->kerja}/m.json --akar={$this->kerja}/akar --dasar-aplikasi=http://app.test --izinkan-http --jeda-ms=0");
        $this->assertStringContainsString('PERINGATAN: --izinkan-http', $this->getStreamFilterBuffer());
        $this->assertCount(1, $this->diminta);
    }

    public function testTanpaAlamatDasarDanButuhLoginMemberiKodeKeluar(): void
    {
        command("lampiran:tarik --manifest={$this->kerja}/m.json");
        $this->assertStringContainsString('Alamat dasar belum diisi', $this->getStreamFilterBuffer());

        command("lampiran:tarik --manifest={$this->kerja}/m.json --akar={$this->kerja}/akar --dasar-arsip=https://login-dulu.test --ambang-login=1 --jeda-ms=0");
        $this->assertStringContainsString('BERHENTI: respons halaman login mencapai ambang', $this->getStreamFilterBuffer());
    }
}
