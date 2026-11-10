<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\Gambar\JenisGambar;
use App\Libraries\Dashboard\Konten\Gambar\KontenGambarService;
use App\Libraries\Dashboard\Konten\Gambar\PenyimpananGambar;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';

/**
 * Keamanan unggah/unduh gambar popup/banner (self-review Jalur A): kunci penyimpanan di DB yang rusak/diubah tidak boleh
 * membaca berkas di luar akar penyimpanan, nama unduhan tidak memuat karakter berbahaya, dan isi yang menyamar sebagai gambar
 * (PHP/HTML/SVG berskrip/PDF bernama .png) atau ekstensi terlarang ditolak. Memuat draf migration `MigrationsDitahan/` (DBV-032).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class KontenGambarKeamananTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private CreateKontenInteraksiFase7 $m;

    private string $akar;

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKontenInteraksiFase7();
        $this->m->up();
        $this->akar = sys_get_temp_dir() . '/gambar_aman_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->m->down();

        foreach ($this->tmp as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*/*') ?: [] as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*') ?: [] as $d) {
            @rmdir($d);
        }

        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testKunciGambarRusakTidakBisaMenembusPath(): void
    {
        $svc = $this->service(JenisGambar::Popup);
        $r   = $svc->buat($this->input(), $this->gambar(), 1);

        $luar = dirname($this->akar) . '/luar_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($luar, 'RAHASIA');
        $this->tmp[] = $luar;

        foreach (['../' . basename($luar), 'popup/../../' . basename($luar), '/etc/passwd', $luar] as $jahat) {
            $this->db->table('popup')->where('id_popup', $r['id'])->update(['file' => $jahat]);

            try {
                $svc->bacaGambar($r['id'], Role::SUPER_ADMIN, null);
                $this->fail('Kunci tidak boleh terbaca: ' . $jahat);
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }

            $this->assertLogged('warning', 'Kunci berkas popup tidak valid (data diubah?): id=' . $r['id']);
        }
    }

    public function testNamaUnduhanGambarAman(): void
    {
        $svc = $this->service(JenisGambar::Banner);
        $r   = $svc->buat(['tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-30', 'target' => ['unit_satker' => ['0']]], $this->gambar(1600, 400), 1);
        $b   = $svc->bacaGambar($r['id'], Role::SUPER_ADMIN, null);

        $this->assertSame('banner-' . $r['id'] . '.png', $b['nama']);
        $this->assertDoesNotMatchRegularExpression('/[\r\n";\\\\\/ ]/', $b['nama']);
    }

    /**
     * @dataProvider provGambarPalsu
     */
    public function testGambarPalsuDitolak(string $isi, string $ekstensi): void
    {
        $f = tempnam(sys_get_temp_dir(), 'pal') . '.' . $ekstensi;
        file_put_contents($f, $isi);
        $this->tmp[] = $f;

        $this->expectException(ValidationException::class);
        $this->service(JenisGambar::Popup)->buat($this->input(), new UploadedFile($f, 'g.' . $ekstensi, null, (int) filesize($f), UPLOAD_ERR_OK), 1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provGambarPalsu(): iterable
    {
        yield 'PHP bernama .png' => ["<?php system('id');", 'png'];
        yield 'HTML bernama .gif' => ['<html><script>alert(1)</script></html>', 'gif'];
        yield 'SVG berskrip bernama .png' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'png'];
        yield 'PDF bernama .jpg' => ["%PDF-1.4\n%%EOF\n", 'jpg'];
        yield 'ekstensi terlarang .svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg'];
        yield 'ekstensi terlarang .php' => ["GIF89a\x01\x00\x01\x00", 'php'];
        yield 'berkas kosong' => ['', 'png'];
    }

    // ------------------------------------------------------------------

    private function service(JenisGambar $jenis): KontenGambarService
    {
        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;

        return new KontenGambarService($jenis, $this->db, new PenyimpananGambar($c));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return ['tgl_mulai' => '2026-11-01 08:00', 'tgl_akhir' => '2026-11-30 17:00', 'target' => ['unit_satker' => ['0']]];
    }

    private function gambar(int $w = 300, int $h = 200): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        $f  = tempnam(sys_get_temp_dir(), 'gbr') . '.png';
        imagepng($im, $f);
        $this->tmp[] = $f;

        return new UploadedFile($f, 'uji.png', 'image/png', (int) filesize($f), UPLOAD_ERR_OK);
    }
}
