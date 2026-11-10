<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Libraries\Dashboard\Konten\Dibo\DiboService;
use App\Libraries\Dashboard\Konten\Dibo\PenyimpananDibo;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Konten;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';

/**
 * F-12 DIBO lewat HTTP (MAKE-082; PRD Fase 7 v2.0 §5.6, §6): baca semua role (hanya aktif), unduh berkas terautentikasi
 * (attachment, tanpa cache; non-admin tidak bisa mengunduh yang dicabut), kelola hanya role 1. Unggah multipart diuji di level
 * service (DiboServiceTest). Memuat draf migration `MigrationsDitahan/` (DBV-032).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class DiboEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    protected $seed = AuthSeeder::class;

    private CreateKontenInteraksiFase7 $m;

    private string $akar;

    private int $idAktif;

    private int $idDicabut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->m = new CreateKontenInteraksiFase7();
        $this->m->up();
        $this->akar = sys_get_temp_dir() . '/dibo_http_' . bin2hex(random_bytes(4));


        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;
        Factories::injectMock('config', Konten::class, $c);

        $this->db->table('jenis_dibo')->insert(['jenis_dibo' => 'Peraturan', 'order' => 1, 'status' => 1]);
        $jenis = (int) $this->db->insertID();

        $svc   = new DiboService($this->db, new PenyimpananDibo($c));
        $admin = $this->authUntukAkun((int) $this->db->table('pengguna')->where('user_level', Role::SUPER_ADMIN)->get()->getRow()->id_pengguna);

        $this->idAktif   = $svc->buat($admin, ['id_jenis' => $jenis, 'nomor' => 'PER-1', 'tahun' => '2026', 'tentang' => 'Aktif'], [1 => $this->pdf()])['id'];
        $this->idDicabut = $svc->buat($admin, ['id_jenis' => $jenis, 'nomor' => 'PER-2', 'tahun' => '2026', 'tentang' => 'Dicabut'], [1 => $this->pdf()])['id'];
        $svc->setStatus($admin, $this->idDicabut, '2');
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        $this->m->down();

        foreach (glob($this->akar . '/*/*') ?: [] as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*') ?: [] as $d) {
            @rmdir($d);
        }

        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testBacaSemuaRoleHanyaAktif(): void
    {
        $hasil = $this->asRole(Role::PEGAWAI)->get('api/v1/konten/dibo');
        $hasil->assertStatus(200);
        $data = json_decode($hasil->getJSON(), true)['data'];

        $this->assertSame(1, $data['total']);
        $this->assertSame(['PER-1', [1]], [$data['items'][0]['nomor'], $data['items'][0]['berkas']]);
    }

    public function testUnduhBerkasTerautentikasi(): void
    {
        $ok = $this->asRole(Role::PEGAWAI)->get("api/v1/konten/dibo/{$this->idAktif}/berkas/1");
        $ok->assertStatus(200);
        $ok->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $ok->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $ok->response()->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $ok->response()->getBody());

        $this->asRole(Role::PEGAWAI)->get("api/v1/konten/dibo/{$this->idAktif}/berkas/2")->assertStatus(404);
        $this->asRole(Role::PEGAWAI)->get("api/v1/konten/dibo/{$this->idDicabut}/berkas/1")->assertStatus(404);
        $this->asRole(Role::SUPER_ADMIN)->get("api/v1/konten/dibo/{$this->idDicabut}/berkas/1")->assertStatus(200);

        $this->withHeaders([])->get("api/v1/konten/dibo/{$this->idAktif}/berkas/1")->assertStatus(401);
    }

    public function testKelolaHanyaRole1(): void
    {
        $daftar = $this->asRole(Role::SUPER_ADMIN)->get('api/v1/konten/dibo', ['kelola' => '1']);
        $daftar->assertStatus(200);
        $this->assertSame(2, json_decode($daftar->getJSON(), true)['data']['total']);

        $this->asRole(Role::PEGAWAI)->get('api/v1/konten/dibo', ['kelola' => '1'])->assertStatus(403);
        $this->asRole(Role::PEGAWAI)->delete("api/v1/konten/dibo/{$this->idAktif}")->assertStatus(403);
        $this->asRole(Role::PEGAWAI)->patch("api/v1/konten/dibo/{$this->idAktif}/status")->assertStatus(403);
        $this->asRole(Role::SUPER_ADMIN)->delete("api/v1/konten/dibo/{$this->idAktif}")->assertStatus(200);
    }

    private function pdf(): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'dbo') . '.pdf';
        file_put_contents($f, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($f, 'berkas.pdf', null, (int) filesize($f), UPLOAD_ERR_OK);
    }
}
