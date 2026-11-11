<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Konten\Gambar\JenisGambar;
use App\Libraries\Dashboard\Konten\Gambar\KontenGambarService;
use App\Libraries\Dashboard\Konten\Gambar\PenyimpananGambar;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Konten;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * F-04 Popup lewat HTTP (MAKE-079; PRD Fase 7 v2.0 §5.3, §6): `aktif` hanya untuk penerima, gambar terautentikasi
 * (penerima/role 1, selain itu 404; tanpa cache), kelola hanya role 1 (403 untuk lainnya), envelope sukses. Unggah multipart
 * diuji di level service (KontenGambarServiceTest).
 *
 * @internal
 */
final class KontenGambarEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;

    protected $seed = AuthSeeder::class;

    private string $akar;

    private int $idPopup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->akar = sys_get_temp_dir() . '/konten_http_' . bin2hex(random_bytes(4));
        $this->daftarkanPegawai(AuthSeeder::nipForRole(Role::PEGAWAI));

        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;
        Factories::injectMock('config', Konten::class, $c);
        $im = imagecreatetruecolor(300, 200);
        $f  = tempnam(sys_get_temp_dir(), 'gbr') . '.png';
        imagepng($im, $f);

        $svc = new KontenGambarService(JenisGambar::Popup, $this->db, new PenyimpananGambar($c));
        $r   = $svc->buat(
            ['tgl_mulai' => '2000-01-01 00:00', 'tgl_akhir' => '2099-12-31 23:59', 'target' => ['pegawai' => [AuthSeeder::nipForRole(Role::PEGAWAI)]]],
            new UploadedFile($f, 'uji.png', 'image/png', (int) filesize($f), UPLOAD_ERR_OK),
            1,
        );
        @unlink($f);
        $this->idPopup = $r['id'];

    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        foreach (glob($this->akar . '/*/*') ?: [] as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*') ?: [] as $d) {
            @rmdir($d);
        }

        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testAktifHanyaUntukPenerima(): void
    {
        $hasil = $this->asRole(Role::PEGAWAI)->get('api/v1/konten/popup/aktif');
        $hasil->assertStatus(200);
        $data = json_decode($hasil->getJSON(), true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame($this->idPopup, $data[0]['id']);
        $this->assertSame("/konten/popup/{$this->idPopup}/gambar", $data[0]['gambar_url']);

        // bukan penerima → kosong; role 1 tidak ber-NIP → 403 (Q7-05)
        $this->assertSame([], json_decode($this->asRole(Role::ADMIN_SATKER)->get('api/v1/konten/popup/aktif')->getJSON(), true)['data']);
        $this->asRole(Role::SUPER_ADMIN)->get('api/v1/konten/popup/aktif')->assertStatus(403);
    }

    /**
     * Q7-05 / Q7-39 (bukti QA Lapis 1 butir K6): banner aktif tampil untuk SETIAP role ber-NIP yang menjadi target — termasuk admin
     * satker (3), admin view eselon I (4), menteri (5), dan pimpinan (8) — dan route menolak role 1 (tanpa NIP) dengan 403.
     */
    public function testBannerAktifUntukRoleBerNipDanRole1Ditolak(): void
    {
        $role = [Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN];
        $nip  = array_map(static fn (int $r): string => AuthSeeder::nipForRole($r), $role);
        $this->daftarkanPegawai(...$nip);

        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;
        $f                  = tempnam(sys_get_temp_dir(), 'bnr') . '.png';
        imagepng(imagecreatetruecolor(1600, 400), $f);
        $r = (new KontenGambarService(JenisGambar::Banner, $this->db, new PenyimpananGambar($c)))->buat(
            ['tgl_mulai' => '2000-01-01', 'tgl_akhir' => '2099-12-31', 'target' => ['pegawai' => $nip]],
            new UploadedFile($f, 'banner.png', 'image/png', (int) filesize($f), UPLOAD_ERR_OK),
            1,
        );
        @unlink($f);

        foreach ($role as $kode) {
            $hasil = $this->asRole($kode)->get('api/v1/konten/banner/aktif');
            $hasil->assertStatus(200);
            $data = json_decode($hasil->getJSON(), true)['data'];
            $this->assertCount(1, $data, "role {$kode} ber-NIP target harus melihat banner");
            $this->assertSame($r['id'], $data[0]['id']);
        }

        $this->asRole(Role::SUPER_ADMIN)->get('api/v1/konten/banner/aktif')->assertStatus(403);
    }

    public function testGambarTerautentikasiPenerimaAtauRole1(): void
    {
        $url = "api/v1/konten/popup/{$this->idPopup}/gambar";

        $ok = $this->asRole(Role::PEGAWAI)->get($url);
        $ok->assertStatus(200);
        $ok->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $ok->response()->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith("\x89PNG", $ok->response()->getBody());

        $this->asRole(Role::SUPER_ADMIN)->get($url)->assertStatus(200);
        $this->asRole(Role::ADMIN_SATKER)->get($url)->assertStatus(404);
        $this->clearAuthState();
        $this->withHeaders([])->get($url)->assertStatus(401);
    }

    public function testKelolaHanyaRole1(): void
    {
        $daftar = $this->asRole(Role::SUPER_ADMIN)->get('api/v1/konten/popup');
        $daftar->assertStatus(200);
        $this->assertSame(1, json_decode($daftar->getJSON(), true)['data']['total']);

        $this->asRole(Role::PEGAWAI)->get('api/v1/konten/popup')->assertStatus(403);
        $this->asRole(Role::PEGAWAI)->delete("api/v1/konten/popup/{$this->idPopup}")->assertStatus(403);
    }
}
