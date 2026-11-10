<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\Berita\BeritaService;
use App\Libraries\Dashboard\Konten\Berita\PenyimpananLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';

/**
 * Lampiran berita (paritas legacy `L_news.php:282-340,489-545,785-818`, `views/hr/news/detail.php:15`): satu berkas
 * JPG/JPEG/PNG/GIF/PDF ≤ 5 MB, disimpan privat; ganti menghapus berkas lama setelah commit; hapus berita menghapus berkas;
 * dibaca lewat aturan visibilitas detail. Juga jam terbit (`pub_time` legacy). Memuat draf migration `MigrationsDitahan/`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class BeritaLampiranTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private CreateKontenInteraksiFase7 $m;

    private string $akar;

    /** @var list<string> */
    private array $tmp = [];

    private int $unitA;

    private int $kategori;

    private AuthContext $admin;

    private AuthContext $pegawaiA;

    private AuthContext $pegawaiB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKontenInteraksiFase7();
        $this->m->up();
        $this->akar = sys_get_temp_dir() . '/lampiran_berita_' . bin2hex(random_bytes(4));

        $this->unitA = $this->buatUnit();
        $satkerA     = $this->buatSatker($this->unitA);
        $satkerB     = $this->buatSatker($this->buatUnit());

        $this->admin    = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerA), Role::SUPER_ADMIN));
        $this->pegawaiA = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerA), Role::PEGAWAI));
        $this->pegawaiB = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerB), Role::PEGAWAI));

        $this->db->table('news_category')->insert(['category' => 'Umum', 'allow_ap' => 0, 'order' => 1]);
        $this->kategori = (int) $this->db->insertID();
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

    public function testBuatDenganLampiranPngDanPdfLaluDibaca(): void
    {
        $svc = $this->service();
        $png = $svc->buat($this->admin, $this->input(), $this->png());
        $pdf = $svc->buat($this->admin, $this->input(['judul' => 'Dengan PDF']), $this->pdf());

        $this->assertSame(['image/png', "/konten/berita/{$png['id']}/lampiran"], [$png['lampiran']['tipe'], $png['lampiran']['url']]);
        $this->assertSame('application/pdf', $pdf['lampiran']['tipe']);

        $baca = $svc->bacaLampiran($this->pegawaiA, $png['id']);
        $this->assertSame('image/png', $baca['mime']);
        $this->assertStringStartsWith("\x89PNG", $baca['isi']);
        $this->assertSame('application/pdf', $svc->bacaLampiran($this->pegawaiA, $pdf['id'])['mime']);

        $kunci = (string) $this->db->table('news')->where('id_news', $png['id'])->get()->getRow()->lampiran;
        $this->assertMatchesRegularExpression('#^berita/[0-9a-f]{32}\.png$#', $kunci);
        $this->assertFileExists($this->akar . '/' . $kunci);
    }

    public function testTanpaLampiranDetailNullDan404(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input());

        $this->assertNull($r['lampiran']);
        $this->expectException(NotFoundException::class);
        $svc->bacaLampiran($this->pegawaiA, $r['id']);
    }

    public function testLampiranMengikutiVisibilitasBerita(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(['target' => (string) $this->unitA]), $this->png());

        $this->assertSame('image/png', $svc->bacaLampiran($this->pegawaiA, $r['id'])['mime']);
        $this->assertSame('image/png', $svc->bacaLampiran($this->admin, $r['id'])['mime']);

        $this->expectException(NotFoundException::class);
        $svc->bacaLampiran($this->pegawaiB, $r['id']);
    }

    public function testJenisDanIsiBerkasDiperiksa(): void
    {
        $svc = $this->service();

        foreach ([$this->berkas('bukan gambar', 'png'), $this->berkas('<?php echo 1;', 'php'), $this->berkas("MZ\x90\x00", 'exe')] as $buruk) {
            try {
                $svc->buat($this->admin, $this->input(), $buruk);
                $this->fail('Harus 422');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lampiran', $e->getErrors() ?? []);
            }
        }

        $this->assertSame(0, $this->db->table('news')->countAllResults(), 'berita tidak dibuat bila lampiran ditolak');
    }

    public function testGantiLampiranMenghapusBerkasLamaDanTanpaBerkasDipertahankan(): void
    {
        $svc  = $this->service();
        $r    = $svc->buat($this->admin, $this->input(), $this->png());
        $lama = (string) $this->db->table('news')->where('id_news', $r['id'])->get()->getRow()->lampiran;

        $svc->ubah($this->admin, $r['id'], $this->input(['judul' => 'Tanpa berkas baru']));
        $this->assertSame($lama, (string) $this->db->table('news')->where('id_news', $r['id'])->get()->getRow()->lampiran);
        $this->assertFileExists($this->akar . '/' . $lama);

        $svc->ubah($this->admin, $r['id'], $this->input(), $this->pdf());
        $baru = (string) $this->db->table('news')->where('id_news', $r['id'])->get()->getRow()->lampiran;

        $this->assertNotSame($lama, $baru);
        $this->assertFileDoesNotExist($this->akar . '/' . $lama);
        $this->assertFileExists($this->akar . '/' . $baru);
    }

    public function testHapusBeritaMenghapusBerkas(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), $this->png());
        $k   = (string) $this->db->table('news')->where('id_news', $r['id'])->get()->getRow()->lampiran;

        $svc->hapus($this->admin, $r['id']);

        $this->assertFileDoesNotExist($this->akar . '/' . $k);
    }

    public function testKelolaMenandaiAdaLampiranDanJamTerbitWib(): void
    {
        $svc = $this->service();
        $svc->buat($this->admin, $this->input(['publish_at' => '2026-11-01 08:30']), $this->png());
        $svc->buat($this->admin, $this->input(['judul' => 'Tanpa']));

        $items = array_column($svc->kelola($this->admin, [])['items'], 'ada_lampiran', 'judul');
        $this->assertTrue($items['Judul uji']);
        $this->assertFalse($items['Tanpa']);

        $id = (int) $this->db->table('news')->where('title', 'Judul uji')->get()->getRow()->id_news;
        $d  = $svc->detail($this->admin, $id);
        $this->assertSame(['2026-11-01', '08:30'], [$d['publish_at'], $d['publish_jam']]);
        $this->assertSame('2026-11-01 01:30:00', (string) $this->db->table('news')->where('id_news', $id)->get()->getRow()->publish_at, 'disimpan UTC');
    }

    public function testKunciLampiranRusakTidakBisaMenembusPath(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), $this->png());

        $luar = dirname($this->akar) . '/luar_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($luar, "%PDF-1.4\nRAHASIA\n%%EOF\n");
        $this->tmp[] = $luar;

        foreach (['../' . basename($luar), 'berita/../../' . basename($luar), '/etc/passwd', $luar] as $jahat) {
            $this->db->table('news')->where('id_news', $r['id'])->update(['lampiran' => $jahat]);

            try {
                $svc->bacaLampiran($this->admin, $r['id']);
                $this->fail('Kunci tidak boleh terbaca: ' . $jahat);
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }

            $this->assertLogged('warning', 'Kunci berkas lampiran-berita tidak valid (data diubah?): id=' . $r['id']);
        }
    }

    public function testNamaUnduhanLampiranAman(): void
    {
        $r = $this->service()->buat($this->admin, $this->input(), $this->pdf());
        $b = $this->service()->bacaLampiran($this->admin, $r['id']);

        $this->assertSame('lampiran-berita-' . $r['id'] . '.pdf', $b['nama']);
        $this->assertDoesNotMatchRegularExpression('/[\r\n";\\\\\/ ]/', $b['nama']);
    }

    /**
     * @dataProvider provBerkasPalsu
     */
    public function testLampiranPalsuDitolak(string $isi, string $ekstensi): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->buat($this->admin, $this->input(), $this->berkas($isi, $ekstensi));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provBerkasPalsu(): iterable
    {
        yield 'PHP bernama .png' => ["<?php system('id');", 'png'];
        yield 'HTML+script bernama .pdf' => ['<html><script>alert(1)</script></html>', 'pdf'];
        yield 'SVG berskrip bernama .png' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'png'];
        yield 'PDF bernama .png (isi != ekstensi)' => ["%PDF-1.4\n%%EOF\n", 'png'];
        yield 'ekstensi terlarang .svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg'];
        yield 'ekstensi terlarang .html' => ['<html></html>', 'html'];
        yield 'berkas kosong' => ['', 'pdf'];
    }

    // ------------------------------------------------------------------

    private function service(): BeritaService
    {
        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;

        return new BeritaService(
            $this->db,
            null,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-15 05:00:00', new DateTimeZone('UTC')),
            new PenyimpananLampiran($c),
        );
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function input(array $o = []): array
    {
        return $o + ['judul' => 'Judul uji', 'id_kategori' => $this->kategori, 'isi' => '<p>Isi</p>', 'publish_at' => '2026-11-01', 'target' => '0'];
    }

    private function png(): UploadedFile
    {
        $im = imagecreatetruecolor(20, 20);
        $f  = tempnam(sys_get_temp_dir(), 'lmp') . '.png';
        imagepng($im, $f);

        return $this->daftar($f, 'lampiran.png');
    }

    private function pdf(): UploadedFile
    {
        return $this->berkas("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", 'pdf');
    }

    private function berkas(string $isi, string $ext): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'lmp') . '.' . $ext;
        file_put_contents($f, $isi);

        return $this->daftar($f, 'lampiran.' . $ext);
    }

    private function daftar(string $path, string $nama): UploadedFile
    {
        $this->tmp[] = $path;

        return new UploadedFile($path, $nama, null, (int) filesize($path), UPLOAD_ERR_OK);
    }
}
