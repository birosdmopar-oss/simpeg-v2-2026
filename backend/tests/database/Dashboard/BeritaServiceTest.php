<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\Berita\BeritaService;
use App\Libraries\Dashboard\Konten\JenisMaster;
use App\Libraries\Dashboard\Konten\MasterKontenService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';

/**
 * F-07 Berita (MAKE-081; PRD Fase 7 v2.0 §5.5, Q7-07..10): sanitasi isi, visibilitas menurut target & jadwal terbit (portal =
 * detail), alur role 3 → menunggu → persetujuan role 1, kepemilikan role 3, penanda `news_flag`, dan master kategori/jenis.
 * Memuat draf migration `MigrationsDitahan/` (DBV-032).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class BeritaServiceTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private CreateKontenInteraksiFase7 $m;

    private int $unitA;

    private int $satkerA;

    private int $unitB;

    private int $satkerB;

    private int $kategori;

    private int $kategoriAp;

    private AuthContext $admin;

    private AuthContext $satker3A;

    private AuthContext $satker3B;

    private AuthContext $pegawaiA;

    private AuthContext $pegawaiB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKontenInteraksiFase7();
        $this->m->up();

        $this->unitA   = $this->buatUnit();
        $this->satkerA = $this->buatSatker($this->unitA);
        $this->unitB   = $this->buatUnit();
        $this->satkerB = $this->buatSatker($this->unitB);

        $this->kategori   = $this->buatKategori('Umum', 0);
        $this->kategoriAp = $this->buatKategori('Pengumuman Satker', 1);

        $this->admin    = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satkerA), Role::SUPER_ADMIN));
        $this->satker3A = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satkerA), Role::ADMIN_SATKER));
        $this->satker3B = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satkerB), Role::ADMIN_SATKER));
        $this->pegawaiA = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satkerA), Role::PEGAWAI));
        $this->pegawaiB = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satkerB), Role::PEGAWAI));
    }

    protected function tearDown(): void
    {
        $this->m->down();
        parent::tearDown();
    }

    public function testAdminMembuatBeritaTerbitDenganIsiDisanitasi(): void
    {
        $r = $this->service()->buat($this->admin, $this->input([
            'isi' => '<p onclick="x()">Halo <strong>dunia</strong><script>alert(1)</script> <a href="javascript:alert(1)">jahat</a> <a href="https://contoh.test" target="_blank">baik</a> <img src="x.png"></p>',
        ]));

        $this->assertSame(1, $r['status']);
        $this->assertStringContainsString('<strong>dunia</strong>', $r['isi']);
        $this->assertDoesNotMatchRegularExpression('/script|onclick|javascript:|<img|target=/i', $r['isi']);
        $this->assertStringContainsString('href="https://contoh.test"', $r['isi']);
        $this->assertSame('0', $r['target']);
    }

    public function testValidasi(): void
    {
        foreach ([
            'judul'       => ['judul' => ' '],
            'id_kategori' => ['id_kategori' => 9999],
            'isi'         => ['isi' => '<p><script>x()</script></p>'],
            'publish_at'  => ['publish_at' => '31-12-2026'],
            'target'      => ['target' => 'abc'],
        ] as $field => $ubah) {
            try {
                $this->service()->buat($this->admin, $this->input($ubah));
                $this->fail("Harus 422: {$field}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->getErrors() ?? [], $field);
            }
        }

        $this->assertSame(0, $this->db->table('news')->countAllResults());
    }

    public function testVisibilitasMenurutTargetJadwalDanPortalSamaDenganDetail(): void
    {
        $svc   = $this->service();
        $semua = $svc->buat($this->admin, $this->input(['judul' => 'Semua']));
        $unitA = $svc->buat($this->admin, $this->input(['judul' => 'Unit A', 'target' => (string) $this->unitA]));
        $satB  = $svc->buat($this->admin, $this->input(['judul' => 'Satker B', 'target' => "sat_{$this->satkerB}"]));
        $nanti = $svc->buat($this->admin, $this->input(['judul' => 'Nanti', 'publish_at' => '2026-12-01']));

        $judul = static fn (array $r): array => array_column($r['items'], 'judul');

        $this->assertEqualsCanonicalizing(['Semua', 'Unit A'], $judul($svc->portal($this->pegawaiA, [])));
        $this->assertEqualsCanonicalizing(['Semua', 'Satker B'], $judul($svc->portal($this->pegawaiB, [])));
        $this->assertEqualsCanonicalizing(['Semua', 'Unit A', 'Satker B'], $judul($svc->portal($this->admin, [])), 'role 1 melihat semua yang terbit');

        // detail memakai aturan yang sama (perbaikan anomali legacy)
        $this->assertSame('Unit A', $svc->detail($this->pegawaiA, $unitA['id'])['judul']);
        foreach ([[$this->pegawaiB, $unitA['id']], [$this->pegawaiA, $satB['id']], [$this->pegawaiA, $nanti['id']]] as [$auth, $id]) {
            try {
                $svc->detail($auth, $id);
                $this->fail('Harus 404');
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotSame($semua['id'], $nanti['id']);
    }

    public function testPencarianDanFilterKategori(): void
    {
        $svc = $this->service();
        $svc->buat($this->admin, $this->input(['judul' => 'Cuti tahunan', 'isi' => '<p>aturan cuti</p>']));
        $svc->buat($this->admin, $this->input(['judul' => 'Gaji', 'isi' => '<p>kenaikan</p>', 'id_kategori' => $this->kategoriAp]));

        $this->assertSame(['Cuti tahunan'], array_column($svc->portal($this->pegawaiA, ['q' => 'cuti'])['items'], 'judul'));
        $this->assertSame(['Gaji'], array_column($svc->portal($this->pegawaiA, ['kategori' => (string) $this->kategoriAp])['items'], 'judul'));
        $this->assertSame(0, $svc->portal($this->pegawaiA, ['q' => '%'])['total'], 'wildcard dicari sebagai karakter biasa');
    }

    public function testRole3MenungguPersetujuanLaluDisetujui(): void
    {
        $svc = $this->service();

        try {
            $svc->buat($this->satker3A, $this->input(['id_kategori' => $this->kategori]));
            $this->fail('Kategori tanpa allow_ap harus ditolak untuk role 3');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id_kategori', $e->getErrors() ?? []);
        }

        $r = $svc->buat($this->satker3A, $this->input(['id_kategori' => $this->kategoriAp, 'judul' => 'Dari satker']));
        $this->assertSame(0, $r['status']);
        $this->assertSame([], array_column($svc->portal($this->pegawaiA, [])['items'], 'judul'), 'menunggu tidak tampil di portal');
        $this->assertSame(0, $this->db->table('news_flag')->countAllResults());

        // pemilik melihat; satker lain tidak
        $this->assertSame('Dari satker', $svc->detail($this->satker3A, $r['id'])['judul']);
        $this->assertSame(1, $svc->kelola($this->satker3A, [])['total']);
        $this->assertSame(0, $svc->kelola($this->satker3B, [])['total']);
        $this->assertSame(1, $svc->kelola($this->admin, ['status' => '0'])['total']);

        // hanya role 1 yang memproses; alasan wajib saat tolak; hanya status 0
        foreach ([[$this->satker3A, ['aksi' => 'setuju']], [$this->pegawaiA, ['aksi' => 'setuju']]] as [$auth, $in]) {
            try {
                $svc->proses($auth, $r['id'], $in);
                $this->fail('Harus 403');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        try {
            $svc->proses($this->admin, $r['id'], ['aksi' => 'tolak']);
            $this->fail('Alasan wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('alasan', $e->getErrors() ?? []);
        }

        $ok = $svc->proses($this->admin, $r['id'], ['aksi' => 'setuju']);
        $this->assertSame(1, $ok['status']);
        $row = $this->db->table('news')->where('id_news', $r['id'])->get()->getRowArray();
        $this->assertSame(1, (int) $row['show_notif']);
        $this->assertNotNull($row['approved_at']);
        $this->assertSame(['Dari satker'], array_column($svc->portal($this->pegawaiA, [])['items'], 'judul'));
        $this->assertGreaterThan(0, $this->db->table('news_flag')->where('id_news', $r['id'])->countAllResults());

        try {
            $svc->proses($this->admin, $r['id'], ['aksi' => 'tolak', 'alasan' => 'x']);
            $this->fail('Sudah diproses');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function testTolakMenyimpanAlasanDanTidakTerbit(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->satker3A, $this->input(['id_kategori' => $this->kategoriAp]));
        $t   = $svc->proses($this->admin, $r['id'], ['aksi' => 'tolak', 'alasan' => 'Kurang lengkap']);

        $this->assertSame(2, $t['status']);
        $this->assertSame('Kurang lengkap', $t['alasan']);
        $this->assertSame([], array_column($svc->portal($this->pegawaiA, [])['items'], 'judul'));
    }

    public function testRole3HanyaMengubahMilikSendiriDanKembaliMenunggu(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->satker3A, $this->input(['id_kategori' => $this->kategoriAp]));
        $svc->proses($this->admin, $r['id'], ['aksi' => 'setuju']);

        try {
            $svc->ubah($this->satker3B, $r['id'], $this->input(['id_kategori' => $this->kategoriAp]));
            $this->fail('Bukan milik satker B');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $svc->hapus($this->satker3B, $r['id']);
            $this->fail('Bukan milik satker B');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        $u = $svc->ubah($this->satker3A, $r['id'], $this->input(['id_kategori' => $this->kategoriAp, 'judul' => 'Revisi']));
        $this->assertSame(0, $u['status'], '[V2] ubah oleh role 3 kembali ke menunggu');
        $this->assertSame(0, $this->db->table('news_flag')->where('id_news', $r['id'])->countAllResults(), 'penanda dicabut');

        $svc->hapus($this->satker3A, $r['id']);
        $this->assertSame(0, $this->db->table('news')->countAllResults());
    }

    public function testAdminUbahTargetMenghitungUlangPenanda(): void
    {
        $svc          = $this->service();
        $r            = $svc->buat($this->admin, $this->input());
        $semuaPenanda = $this->db->table('news_flag')->where('id_news', $r['id'])->countAllResults();
        $this->assertGreaterThanOrEqual(5, $semuaPenanda);

        $svc->ubah($this->admin, $r['id'], $this->input(['target' => "sat_{$this->satkerA}"]));
        $idA = $this->db->table('pengguna')->where('id_satker', (string) $this->satkerA)->countAllResults();
        $this->assertSame($idA, $this->db->table('news_flag')->where('id_news', $r['id'])->countAllResults());
    }

    public function testDibukaMenghapusPenandaPembaca(): void
    {
        $svc = $this->service();
        $svc->buat($this->admin, $this->input(['judul' => 'Satu']));
        $svc->buat($this->admin, $this->input(['judul' => 'Dua']));

        $this->assertSame(2, $svc->jumlahBelumDibuka($this->pegawaiA));
        $svc->dibuka($this->pegawaiA);
        $this->assertSame(0, $svc->jumlahBelumDibuka($this->pegawaiA));
        $this->assertSame(2, $svc->jumlahBelumDibuka($this->pegawaiB), 'penanda pembaca lain tetap');
    }

    public function testKelolaDitolakUntukRoleLain(): void
    {
        $this->expectException(ApiException::class);
        $this->service()->kelola($this->pegawaiA, []);
    }

    public function testMasterKategoriNamaUnikUrutanStatusDanHapus(): void
    {
        $m = new MasterKontenService(JenisMaster::BeritaKategori, $this->db);

        $baru = $m->simpan(null, 'Kesehatan', 1, 1);
        $this->assertSame(['Kesehatan', 1, 1], [$baru['nama'], $baru['allow_ap'], $baru['status']]);

        try {
            $m->simpan(null, 'Kesehatan', 0, 1);
            $this->fail('Nama harus unik');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $urut = array_column($m->daftar($this->admin), 'nama');
        $this->assertSame('Kesehatan', end($urut));
        $m->geser($baru['id'], 'naik', 1);
        $this->assertSame($baru['id'], $m->daftar($this->admin)[1]['id']);

        $m->setStatus($baru['id'], '2', 1);
        $this->assertSame([], array_values(array_filter($m->aktif(), static fn (array $a): bool => $a['nama'] === 'Kesehatan')));
        $m->setStatus($baru['id'], '1', 1);

        // kategori yang dipakai berita tidak bisa dihapus; yang belum dipakai dihapus lunak (status 10) dan bisa dipulihkan
        $this->service()->buat($this->admin, $this->input(['id_kategori' => $this->kategori]));

        try {
            $m->hapus($this->kategori, 1);
            $this->fail('Masih dipakai');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $m->hapus($baru['id'], 1);
        $this->assertSame(10, (int) $this->db->table('news_category')->where('id_news_category', $baru['id'])->get()->getRow()->status);
        $m->setStatus($baru['id'], '1', 1);
        $this->assertSame(1, (int) $this->db->table('news_category')->where('id_news_category', $baru['id'])->get()->getRow()->status);
    }

    public function testRole3HanyaMelihatKategoriAllowApDanTidakBolehMenulis(): void
    {
        $m = new MasterKontenService(JenisMaster::BeritaKategori, $this->db);

        $this->assertSame(['Pengumuman Satker'], array_column($m->daftar($this->satker3A), 'nama'));

        $this->expectException(ApiException::class);
        (new MasterKontenService(JenisMaster::DiboJenis, $this->db))->daftar($this->satker3A);
    }

    // ------------------------------------------------------------------

    private function service(): BeritaService
    {
        return new BeritaService(
            $this->db,
            null,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-15 05:00:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function input(array $o = []): array
    {
        return $o + ['judul' => 'Judul uji', 'id_kategori' => $this->kategori, 'isi' => '<p>Isi berita</p>', 'publish_at' => '2026-11-01', 'target' => '0'];
    }

    private function buatKategori(string $nama, int $allowAp): int
    {
        $this->db->table('news_category')->insert(['category' => $nama, 'allow_ap' => $allowAp, 'order' => (int) $this->db->table('news_category')->countAllResults() + 1]);

        return (int) $this->db->insertID();
    }
}
