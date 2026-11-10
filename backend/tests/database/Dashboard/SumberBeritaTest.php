<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\Berita\BeritaService;
use App\Libraries\Dashboard\Konten\Berita\SumberBerita;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';

/**
 * Sumber notifikasi berita untuk Pusat Notifikasi F-08 (kontrak A ↔ C): notifikasi pembuat role 3 lewat `show_notif`
 * (1 baru, 3 dibaca, 2 dihapus), antrean/histori role 1, penghitung lonceng; role lain kosong. Memuat draf migration
 * `MigrationsDitahan/` (DBV-032).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SumberBeritaTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private CreateKontenInteraksiFase7 $m;

    private AuthContext $admin;

    private AuthContext $satker3A;

    private AuthContext $satker3B;

    private AuthContext $pegawai;

    private int $kategoriAp;

    private SumberBerita $sumber;

    private BeritaService $berita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKontenInteraksiFase7();
        $this->m->up();

        $satkerA = $this->buatSatker($this->buatUnit());
        $satkerB = $this->buatSatker($this->buatUnit());

        $this->admin    = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerA), Role::SUPER_ADMIN));
        $this->satker3A = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerA), Role::ADMIN_SATKER));
        $this->satker3B = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerB), Role::ADMIN_SATKER));
        $this->pegawai  = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satkerA), Role::PEGAWAI));

        $this->db->table('news_category')->insert(['category' => 'Satker', 'allow_ap' => 1, 'order' => 1]);
        $this->kategoriAp = (int) $this->db->insertID();

        $this->berita = new BeritaService($this->db);
        $this->sumber = new SumberBerita($this->db, $this->berita);
    }

    protected function tearDown(): void
    {
        $this->m->down();
        parent::tearDown();
    }

    public function testKontrakDasar(): void
    {
        $this->assertSame('berita', $this->sumber->kode());
        $this->assertTrue($this->sumber->tersedia());
    }

    public function testNotifikasiPembuatSetelahDiprosesLaluDibacaDanDihapus(): void
    {
        $a = $this->buat($this->satker3A, 'Berita A');
        $b = $this->buat($this->satker3A, 'Berita B');
        $this->berita->proses($this->admin, $a, ['aksi' => 'setuju']);
        $this->berita->proses($this->admin, $b, ['aksi' => 'tolak', 'alasan' => 'Kurang lengkap']);

        $this->assertSame(2, $this->sumber->jumlah($this->satker3A, true));
        $daftar = $this->sumber->daftar($this->satker3A, false, 10);
        $this->assertCount(2, $daftar);

        $byId = array_column($daftar, null, 'id');
        $this->assertSame(['Disetujui', null, false, '/berita/' . $a], [$byId[$a]['status_label'], $byId[$a]['reason_note'], $byId[$a]['dibaca'], $byId[$a]['url_fe']]);
        $this->assertSame(['Ditolak', 'Kurang lengkap', '/konten/berita'], [$byId[$b]['status_label'], $byId[$b]['reason_note'], $byId[$b]['url_fe']]);

        foreach (['id', 'judul', 'keterangan', 'status_label', 'reason_note', 'url_fe', 'dibaca', 'waktu', 'dapat_dihapus'] as $kunci) {
            $this->assertArrayHasKey($kunci, $byId[$a]);
        }

        // satker lain tidak melihat milik satker A
        $this->assertSame(0, $this->sumber->jumlah($this->satker3B, false));
        $this->assertSame([], $this->sumber->daftar($this->satker3B, false, 10));
        $this->assertSame(0, $this->sumber->tandaiDibaca($this->satker3B, [$a]));
        $this->assertFalse($this->sumber->hapus($this->satker3B, $a));

        $this->assertSame(1, $this->sumber->tandaiDibaca($this->satker3A, [$a]));
        $this->assertSame(1, $this->sumber->jumlah($this->satker3A, true));
        $this->assertSame(2, $this->sumber->jumlah($this->satker3A, false));

        $this->assertTrue($this->sumber->hapus($this->satker3A, $a));
        $this->assertFalse($this->sumber->hapus($this->satker3A, $a), 'sudah dihapus');
        $this->assertSame(1, $this->sumber->jumlah($this->satker3A, false));
        $this->assertSame(2, (int) $this->db->table('news')->where('id_news', $a)->get()->getRow()->show_notif);
    }

    public function testAntreanDanHistoriRole1(): void
    {
        $a = $this->buat($this->satker3A, 'Menunggu');
        $b = $this->buat($this->satker3B, 'Disetujui');
        $this->berita->proses($this->admin, $b, ['aksi' => 'setuju']);

        $this->assertSame(1, $this->sumber->jumlahMenunggu($this->admin));
        $antre = $this->sumber->daftarMenunggu($this->admin, 1, 10);
        $this->assertSame(1, $antre['total']);
        $this->assertSame([$a], array_column($antre['items'], 'id'));
        $this->assertSame('/konten/berita', $antre['url_fe']);
        $this->assertNotNull($antre['items'][0]['nama']);

        $bulan   = gmdate('Y-m');
        $histori = $this->sumber->histori($this->admin, $bulan, 1, 10);
        $this->assertSame([$b], array_column($histori['items'], 'id'));
        $this->assertSame(0, $this->sumber->histori($this->admin, '2000-01', 1, 10)['total']);
        $this->assertSame(0, $this->sumber->histori($this->admin, 'bukan-bulan', 1, 10)['total']);
    }

    public function testRoleLainKosongDanLoncengUntukSemua(): void
    {
        $this->assertSame(0, $this->sumber->jumlah($this->pegawai, false));
        $this->assertSame([], $this->sumber->daftar($this->pegawai, false, 5));
        $this->assertSame(0, $this->sumber->jumlahMenunggu($this->satker3A));
        $this->assertSame(['items' => [], 'total' => 0, 'url_fe' => null], $this->sumber->daftarMenunggu($this->satker3A, 1, 10));

        $this->berita->buat($this->admin, ['judul' => 'Semua', 'id_kategori' => $this->kategoriAp, 'isi' => '<p>x</p>', 'publish_at' => '2020-01-01', 'target' => '0']);
        $this->assertSame(1, $this->sumber->jumlahLonceng($this->pegawai));
        $this->berita->dibuka($this->pegawai);
        $this->assertSame(0, $this->sumber->jumlahLonceng($this->pegawai));
    }

    private function buat(AuthContext $auth, string $judul): int
    {
        return (int) $this->berita->buat($auth, ['judul' => $judul, 'id_kategori' => $this->kategoriAp, 'isi' => '<p>Isi</p>', 'publish_at' => '2020-01-01', 'target' => '0'])['id'];
    }
}
