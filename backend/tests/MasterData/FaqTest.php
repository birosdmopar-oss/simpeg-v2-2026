<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\MasterData\FaqService;
use App\Models\MasterData\FaqRateModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use ReflectionProperty;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * G-10 — FAQ pilot (DBV-002 / CR-003): baca untuk semua role yang login (MTC-013), rantai status topik → sub topik →
 * artikel (U3), pencarian FULLTEXT + fallback judul, perubahan admin langsung terlihat (MTC-014), sanitasi HTML
 * isi artikel (U1), kolom audit legacy created_by/updated_by (E1), dan rating artikel oleh UL_PEGAWAI (U2).
 *
 * CRUD generik tiga master FAQ ikut diuji MasterGenericTcTest & RbacMasterEndpointsTest (fixture MasterDataTestTrait).
 * Data seed: MasterDataSeeder::seedFaq(). Pencarian FULLTEXT hanya melihat data yang sudah di-commit (InnoDB):
 * seluruh data uji di sini ditulis dengan autocommit / transaksi yang sudah selesai sebelum dicari.
 *
 * @internal
 */
final class FaqTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const RATER_ROLES     = [Role::PEGAWAI, Role::PTT, Role::PPPK];
    private const NON_RATER_ROLES = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Baca (UL_ALL) + rantai status
    // ------------------------------------------------------------------

    public function testTreeIsOpenToEveryLoggedInRoleAndSortedByOrder(): void
    {
        $expected = [
            ['id' => 1, 'nama' => 'Akun dan Login', 'sub_topics' => [
                ['id' => 1, 'nama' => 'Kata Sandi', 'articles' => [
                    ['id' => 1, 'title' => 'Cara mengatur ulang kata sandi'],
                    ['id' => 2, 'title' => 'Syarat kata sandi baru'],
                ]],
                ['id' => 2, 'nama' => 'Profil Akun', 'articles' => [['id' => 3, 'title' => 'Mengganti foto profil']]],
            ]],
            ['id' => 2, 'nama' => 'Kepegawaian', 'sub_topics' => [
                ['id' => 3, 'nama' => 'Data Pribadi', 'articles' => [['id' => 4, 'title' => 'Memperbarui alamat rumah']]],
                // Sub topik tanpa artikel aktif tetap tampil.
                ['id' => 5, 'nama' => 'Riwayat Jabatan', 'articles' => []],
            ]],
            ['id' => 3, 'nama' => 'Presensi', 'sub_topics' => [
                ['id' => 4, 'nama' => 'Absensi Harian', 'articles' => [['id' => 5, 'title' => 'Lupa absen pulang']]],
            ]],
        ];

        foreach (Role::all() as $role) {
            $result = $this->asRole($role)->get('api/v1/faq');
            $result->assertStatus(200);
            $this->assertSame(['status' => 'success', 'data' => ['topics' => $expected]], $this->json($result), "role {$role}");
        }

        // Urutan mengikuti kolom `order` (lalu nama/judul), bukan id.
        $this->db->table('faq_article')->where('id_faq_article', 2)->update(['order' => 0]);
        $this->assertSame([2, 1], array_column($this->tree()[0]['sub_topics'][0]['articles'], 'id'));

        // D8: wajib login (legacy terbuka untuk tamu).
        $this->withHeaders(['Authorization' => ''])->get('api/v1/faq')->assertStatus(401);
        $this->withHeaders(['Authorization' => ''])->get('api/v1/faq/1')->assertStatus(401);
        $this->withHeaders(['Authorization' => ''])->get('api/v1/faq', ['search' => 'sandi'])->assertStatus(401);
    }

    /**
     * U3: status anak tidak ditulis ulang; tampilan pegawai menyaring SELURUH rantai; memulihkan induk memunculkan
     * anaknya kembali.
     */
    public function testInactiveOrDeletedAncestorHidesDescendantsUntilRestored(): void
    {
        $this->asRole(Role::SUPER_ADMIN);

        foreach (['PATCH-2' => fn () => $this->sendJson('PATCH', 'api/v1/master/faq-topic/1/status', ['status' => '2']), 'DELETE-10' => fn () => $this->delete('api/v1/master/faq-topic/1')] as $case => $hide) {
            $hide()->assertStatus(200);
            $this->asRole(Role::PEGAWAI);

            $this->assertSame([2, 3], array_column($this->tree(), 'id'), $case);
            $this->assertSame([], $this->searchIds('sandi'), $case);
            $this->assertSame([], $this->searchIds('fo'), $case);
            $this->get('api/v1/faq/1')->assertStatus(404);
            $this->get('api/v1/faq/3')->assertStatus(404);

            // Status anak tidak ditulis ulang.
            $this->seeInDatabase('faq_sub_topic', ['id_faq_sub_topic' => 1, 'status' => 1]);
            $this->seeInDatabase('faq_article', ['id_faq_article' => 1, 'status' => 1]);

            $this->asRole(Role::SUPER_ADMIN)->sendJson('PATCH', 'api/v1/master/faq-topic/1/status', ['status' => '1'])->assertStatus(200);
            $this->asRole(Role::PEGAWAI);

            // Pemulihan dari status 10 menaruh topik di akhir urutan (perilaku engine), jadi cukup cek isinya.
            $topics = array_column($this->tree(), null, 'id');
            $this->assertEqualsCanonicalizing([1, 2, 3], array_keys($topics), "{$case}: dipulihkan");
            $this->assertSame([1, 2], array_column($topics[1]['sub_topics'], 'id'), "{$case}: anak muncul kembali");
            $this->assertEqualsCanonicalizing([1, 2], $this->searchIds('sandi'), $case);
            $this->get('api/v1/faq/1')->assertStatus(200);
            $this->asRole(Role::SUPER_ADMIN);
        }

        // Sub topik Tidak Aktif → artikelnya hilang, topik tetap tampil dengan sub topik lain.
        $this->sendJson('PATCH', 'api/v1/master/faq-sub-topic/2/status', ['status' => '2'])->assertStatus(200);
        // Artikel Tidak Aktif / Dihapus → 404.
        $this->sendJson('PATCH', 'api/v1/master/faq-article/2/status', ['status' => '2'])->assertStatus(200);
        $this->delete('api/v1/master/faq-article/4')->assertStatus(200);

        $this->asRole(Role::PEGAWAI);
        $tree = array_column($this->tree(), null, 'id');
        $this->assertSame([1], array_column($tree[1]['sub_topics'], 'id'));
        $this->assertSame([1], array_column($tree[1]['sub_topics'][0]['articles'], 'id'));
        $this->assertSame([], $tree[2]['sub_topics'][0]['articles']);

        foreach ([2, 3, 4] as $hidden) {
            $this->get("api/v1/faq/{$hidden}")->assertStatus(404);
        }

        $this->assertSame([1], $this->searchIds('sandi'));
        $this->assertSame([], $this->searchIds('foto'));
    }

    public function testArticleDetailWithRelatedArticlesAndRatingFlags(): void
    {
        // Sub topik 1 berisi artikel 1, 2 + enam artikel tambahan (satu Tidak Aktif).
        foreach (range(10, 15) as $id) {
            $this->insertArticle($id, 1, "Artikel tambahan {$id}", "<p>Isi {$id}.</p>", $id === 15 ? 2 : 1);
        }

        $result = $this->asRole(Role::PEGAWAI)->get('api/v1/faq/1');
        $result->assertStatus(200);
        $data = $this->json($result)['data'];

        $this->assertSame(1, $data['id']);
        $this->assertSame('Cara mengatur ulang kata sandi', $data['title']);
        $this->assertStringContainsString('<strong>Lupa kata sandi</strong>', $data['content']);
        $this->assertSame(['id' => 1, 'nama' => 'Akun dan Login'], $data['topic']);
        $this->assertSame(['id' => 1, 'nama' => 'Kata Sandi'], $data['sub_topic']);

        // Baris seed belum pernah diubah (updated_at NULL): detail memakai created_at persis, bukan kolom lain.
        $seed = $this->db->table('faq_article')->select('created_at, updated_at')->where('id_faq_article', 1)->get()->getRowArray();
        $this->assertNull($seed['updated_at']);
        $this->assertSame($seed['created_at'], $data['updated_at'], 'baris tanpa updated_at memakai created_at');
        // ≤5 artikel aktif lain di sub topik yang sama, id terbaru dulu (legacy); artikel Tidak Aktif (15) tidak ikut.
        $this->assertSame([14, 13, 12, 11, 10], array_column($data['related'], 'id'));
        $this->assertSame(['id' => 14, 'title' => 'Artikel tambahan 14'], $data['related'][0]);
        $this->assertSame(['can_rate' => true, 'rated' => false, 'rate' => null], $data['rating']);

        foreach (self::NON_RATER_ROLES as $role) {
            $rating = $this->json($this->asRole($role)->get('api/v1/faq/1'))['data']['rating'];
            $this->assertSame(['can_rate' => false, 'rated' => false, 'rate' => null], $rating, "role {$role}");
        }

        // Id non-kanonik / tidak ada / tidak aktif → 404.
        foreach (['01', '1e0', 'abc', '0', '999', '15'] as $id) {
            $this->asRole(Role::PEGAWAI)->get("api/v1/faq/{$id}")->assertStatus(404);
        }
    }

    // ------------------------------------------------------------------
    // Pencarian
    // ------------------------------------------------------------------

    public function testSearchUsesFulltextOnTitleAndContent(): void
    {
        $this->asRole(Role::PEGAWAI);

        // Kata hanya ada di isi (content_stripped) artikel 1.
        $result = $this->json($this->get('api/v1/faq', ['search' => '  email  ']))['data'];
        $this->assertSame('email', $result['search']);
        $this->assertSame([[
            'id'        => 1,
            'title'     => 'Cara mengatur ulang kata sandi',
            'topic'     => ['id' => 1, 'nama' => 'Akun dan Login'],
            'sub_topic' => ['id' => 1, 'nama' => 'Kata Sandi'],
            // Kalimat pertama content_stripped.
            'snippet' => 'Buka halaman masuk lalu pilih Lupa kata sandi.',
        ]], $result['results']);

        $this->assertEqualsCanonicalizing([1, 2], $this->searchIds('sandi'));
        // Tanda kutip/operator tidak merusak query (parameter terikat).
        $this->get('api/v1/faq', ['search' => "sandi' OR 1=1 -- \""])->assertStatus(200);
        $this->assertSame([], $this->searchIds('tidakadayangcocok'));

        // Snippet dipotong maks 200 karakter.
        $this->insertArticle(20, 4, 'Panduan presensi daring', '<p>' . str_repeat('lokasi ', 60) . '</p>');
        $snippet = $this->json($this->get('api/v1/faq', ['search' => 'daring']))['data']['results'][0]['snippet'];
        $this->assertSame(200, mb_strlen($snippet));
        $this->assertStringEndsWith('…', $snippet);
    }

    public function testShortOrUnmatchedSearchFallsBackToTitleLikeWithEscapedWildcards(): void
    {
        $this->insertArticle(21, 4, 'Potongan 100% tunjangan', '<p>Aturan potongan.</p>');
        $this->insertArticle(22, 4, 'Kode_akses presensi', '<p>Kode akses mesin.</p>');
        $this->asRole(Role::PEGAWAI);

        // < 3 karakter: tidak memakai FULLTEXT (token pendek tidak terindeks) → judul LIKE.
        $this->assertSame([3], $this->searchIds('fo'));
        // >= 3 karakter tapi bukan kata utuh (FULLTEXT kosong) → fallback judul, id terbaru dulu.
        $this->assertSame([2, 1], $this->searchIds('sand'));

        // Wildcard LIKE di-escape: '%' dan '_' dicari sebagai karakter biasa.
        $this->assertSame([21], $this->searchIds('%'));
        $this->assertSame([21], $this->searchIds('0%'));
        $this->assertSame([22], $this->searchIds('_'));
        // Karakter escape LIKE ('!') juga dicari apa adanya — tanpa escape, '!%' akan berarti '%' literal.
        $this->assertSame([], $this->searchIds('!'));
        $this->assertSame([], $this->searchIds('!%'));

        // Kata kunci kosong/spasi = pohon topik.
        $this->assertArrayHasKey('topics', $this->json($this->get('api/v1/faq', ['search' => '   ']))['data']);

        // Maksimal 100 karakter.
        $this->get('api/v1/faq', ['search' => str_repeat('a', 100)])->assertStatus(200);
        $result = $this->get('api/v1/faq', ['search' => str_repeat('a', 101)]);
        $result->assertStatus(422);
        $this->assertArrayHasKey('search', $this->json($result)['errors']);
        $this->get('api/v1/faq', ['search' => ['sandi']])->assertStatus(422);
    }

    public function testSearchReturnsAtMostFiftyResults(): void
    {
        $rows = [];

        foreach (range(100, 159) as $id) {
            $rows[] = $this->articleRow($id, 5, "Panduan riwayat jabatan nomor {$id}", '<p>Panduan singkat.</p>');
        }

        $this->db->table('faq_article')->insertBatch($rows);

        $this->asRole(Role::PEGAWAI);
        // Jalur FULLTEXT.
        $this->assertCount(50, $this->searchIds('panduan'));
        $this->assertCount(50, $this->searchIds('nomor 1'));

        // Jalur fallback judul LIKE juga dibatasi 50: kata kunci < 3 karakter, dan kata ≥ 3 karakter yang bukan kata
        // utuh (FULLTEXT kosong). Keenam puluh judul memuat 'no'/'nomo'; judul seed tidak.
        $this->assertSame(60, $this->db->table('faq_article')->like('title', 'nomo')->countAllResults());
        $this->assertSame(range(159, 110), $this->searchIds('no'));
        $this->assertSame(range(159, 110), $this->searchIds('nomo'));
    }

    /**
     * Hasil FULLTEXT diurutkan menurut relevansi, lalu id terbaru bila relevansinya sama (SPEC §4).
     */
    public function testFulltextResultsAreOrderedByRelevanceThenNewestId(): void
    {
        // 31: kata kunci di judul dan berulang di isi (relevansi tertinggi) walau id-nya paling kecil.
        $this->insertArticle(31, 4, 'Lembur akhir pekan', '<p>Lembur dihitung per jam. Lembur wajib disetujui atasan. Lembur maksimal empat jam.</p>');
        // 32 & 33: dokumen setara (sekali di judul; angka satu digit tidak terindeks) → relevansi seri → id terbaru dulu.
        $this->insertArticle(32, 4, 'Formulir lembur 1', '<p>Isi formulir.</p>');
        $this->insertArticle(33, 4, 'Formulir lembur 2', '<p>Isi formulir.</p>');

        // Peringkat MATCH InnoDB memakai statistik jumlah baris tabel. Tabel baru dibuat migrate:refresh, jadi statistiknya
        // masih 0 (dihitung ulang di latar) dan seluruh relevansi bernilai 0 — hitung ulang sekarang agar peringkat nyata.
        $this->db->query('ANALYZE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('faq_article')));
        $this->asRole(Role::PEGAWAI);
        $this->assertSame([31, 33, 32], $this->searchIds('lembur'));
    }

    // ------------------------------------------------------------------
    // Admin: CRUD, perubahan langsung terlihat, sanitasi, list, kolom audit
    // ------------------------------------------------------------------

    /**
     * MTC-014: perubahan admin langsung terlihat oleh pegawai (tanpa cache).
     */
    public function testAdminChangesAreVisibleImmediately(): void
    {
        $this->asRole(Role::SUPER_ADMIN);
        $topic   = $this->json($this->sendJson('POST', 'api/v1/master/faq-topic', ['faq_topic' => 'Cuti dan Izin', 'order' => '1']))['data'];
        $sub     = $this->json($this->sendJson('POST', 'api/v1/master/faq-sub-topic', ['id_faq_topic' => (string) $topic['id_faq_topic'], 'faq_sub_topic' => 'Cuti Tahunan']))['data'];
        $created = $this->sendJson('POST', 'api/v1/master/faq-article', [
            'id_faq_sub_topic' => (string) $sub['id_faq_sub_topic'],
            'title'            => 'Kuota cuti tahunan',
            'content'          => '<p>Kuota cuti tahunan dua belas hari kerja.</p>',
        ]);
        $created->assertStatus(201);
        $articleId = (int) $this->json($created)['data']['id_faq_article'];

        $this->asRole(Role::PPPK);
        $tree = $this->tree();
        $this->assertSame((int) $topic['id_faq_topic'], $tree[0]['id'], 'topik baru di urutan 1');
        $this->assertSame([['id' => $articleId, 'title' => 'Kuota cuti tahunan']], $tree[0]['sub_topics'][0]['articles']);
        $this->assertSame([$articleId], $this->searchIds('kuota'));
        $this->get("api/v1/faq/{$articleId}")->assertStatus(200);

        // Jam aplikasi dibekukan jauh dari jam server: detail wajib mengirim updated_at hasil ubah, bukan created_at.
        Time::setTestNow('2021-03-04 05:06:07', 'UTC');
        $this->asRole(Role::SUPER_ADMIN)->sendJson('PUT', "api/v1/master/faq-article/{$articleId}", ['title' => 'Kuota cuti tahunan pegawai'])->assertStatus(200);
        $detail = $this->json($this->asRole(Role::PPPK)->get("api/v1/faq/{$articleId}"))['data'];
        $this->assertSame('Kuota cuti tahunan pegawai', $detail['title']);
        $this->assertSame('2021-03-04 05:06:07', $detail['updated_at']);
        Time::setTestNow();

        $this->asRole(Role::SUPER_ADMIN)->sendJson('PATCH', "api/v1/master/faq-article/{$articleId}/status", ['status' => '2'])->assertStatus(200);
        $this->asRole(Role::PPPK)->get("api/v1/faq/{$articleId}")->assertStatus(404);
        $this->assertSame([], $this->tree()[0]['sub_topics'][0]['articles']);
    }

    public function testFaqMasterCrudIsSuperAdminOnly(): void
    {
        $payloads = [
            'faq-topic'     => ['faq_topic' => 'Topik Uji'],
            'faq-sub-topic' => ['id_faq_topic' => '1', 'faq_sub_topic' => 'Sub Topik Uji'],
            'faq-article'   => ['id_faq_sub_topic' => '1', 'title' => 'Artikel Uji', 'content' => '<p>Isi uji.</p>'],
        ];

        foreach ($payloads as $entity => $payload) {
            foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
                $this->asRole($role)->sendJson('POST', "api/v1/master/{$entity}", $payload)->assertStatus(403);
                $this->asRole($role)->get("api/v1/master/{$entity}")->assertStatus(403);
            }

            $this->asRole(Role::SUPER_ADMIN)->sendJson('POST', "api/v1/master/{$entity}", $payload)->assertStatus(201);
            $this->get("api/v1/master/{$entity}")->assertStatus(200);
        }

        $this->assertSame(1, $this->db->table('faq_article')->where('title', 'Artikel Uji')->countAllResults());
    }

    /**
     * U1: isi artikel disanitasi server (HTMLPurifier whitelist) saat tulis; content_stripped dihitung dari konten
     * tersanitasi (rumus legacy) dan tidak bisa dikirim dari input.
     */
    public function testArticleContentIsSanitizedOnWrite(): void
    {
        $this->asRole(Role::SUPER_ADMIN);
        $dirty = '<p onclick="curi()" style="color:red" class="x">Halo <script>alert(1)</script>'
            . '<img src="https://simpeg.kemenparekraf.go.id/a.png" onerror="alert(1)" alt="gambar">'
            . '<img src="javascript:alert(1)"><a href="javascript:alert(1)">klik</a> '
            . '<a href="https://kemenparekraf.go.id" target="_blank">situs</a></p><iframe src="https://contoh.id"></iframe>';

        $created = $this->sendJson('POST', 'api/v1/master/faq-article', [
            'id_faq_sub_topic' => '1',
            'title'            => 'Artikel berbahaya',
            'content'          => $dirty,
            'content_stripped' => 'DIABAIKAN',
        ]);
        $created->assertStatus(201);
        $id  = (string) $this->json($created)['data']['id_faq_article'];
        $row = $this->db->table('faq_article')->where('id_faq_article', $id)->get()->getRowArray();

        foreach (['<script', 'alert(', 'onclick', 'onerror', 'javascript:', 'style=', 'class=', '<iframe'] as $danger) {
            $this->assertStringNotContainsString($danger, (string) $row['content'], $danger);
        }

        $this->assertStringContainsString('<img src="https://simpeg.kemenparekraf.go.id/a.png" alt="gambar"', (string) $row['content']);
        $this->assertStringContainsString('target="_blank"', (string) $row['content']);
        $this->assertMatchesRegularExpression('/rel="(noreferrer noopener|noopener noreferrer)"/', (string) $row['content']);
        $this->assertSame('Halo klik situs', $row['content_stripped']);

        // GET mengirim konten yang sudah bersih.
        $this->assertSame($row['content'], $this->json($this->asRole(Role::PEGAWAI)->get("api/v1/faq/{$id}"))['data']['content']);

        // Update: disanitasi & content_stripped dihitung ulang.
        $this->asRole(Role::SUPER_ADMIN)->sendJson('PUT', "api/v1/master/faq-article/{$id}", ['content' => "<h2>Langkah</h2>\t<p>Buka <em>menu</em>.<script>x()</script></p>"])->assertStatus(200);
        $this->seeInDatabase('faq_article', ['id_faq_article' => $id, 'content' => "<h2>Langkah</h2>\t<p>Buka <em>menu</em>.</p>", 'content_stripped' => 'LangkahBuka menu.']);

        // Konten yang habis setelah sanitasi = kosong → 422, tidak ada yang ditulis.
        foreach (['<script>alert(1)</script>', '<p onclick="x()"></p>', '<iframe src="https://contoh.id"></iframe>&nbsp;'] as $empty) {
            $result = $this->sendJson('POST', 'api/v1/master/faq-article', ['id_faq_sub_topic' => '1', 'title' => 'Kosong', 'content' => $empty]);
            $result->assertStatus(422);
            $this->assertSame(['Isi artikel wajib diisi.'], $this->json($result)['errors']['content'], $empty);
        }

        $this->dontSeeInDatabase('faq_article', ['title' => 'Kosong']);
        $this->sendJson('PUT', "api/v1/master/faq-article/{$id}", ['content' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->seeInDatabase('faq_article', ['id_faq_article' => $id, 'content_stripped' => 'LangkahBuka menu.']);

        // Isi wajib (html, required) + batas 1.000.000 byte.
        $this->sendJson('POST', 'api/v1/master/faq-article', ['id_faq_sub_topic' => '1', 'title' => 'Tanpa isi'])->assertStatus(422);
        $result = $this->sendJson('POST', 'api/v1/master/faq-article', ['id_faq_sub_topic' => '1', 'title' => 'Terlalu besar', 'content' => '<p>' . str_repeat('a', 1000000) . '</p>']);
        $result->assertStatus(422);
        $this->assertSame(['Isi Artikel maksimal 1.000.000 byte.'], $this->json($result)['errors']['content']);
    }

    /**
     * E4 + E7: daftar admin artikel tidak mengirim content/content_stripped (LONGTEXT), detail tetap mengirimnya;
     * pencarian admin ikut mencari di content_stripped.
     */
    public function testAdminArticleListExcludesContentButDetailIncludesIt(): void
    {
        $this->asRole(Role::SUPER_ADMIN);
        $list = $this->json($this->get('api/v1/master/faq-article', ['parent' => '1']))['data'];

        $this->assertSame(2, $list['total']);
        $this->assertSame(['1', '2'], array_map('strval', array_column($list['items'], 'id_faq_article')));

        foreach ($list['items'] as $item) {
            $this->assertArrayNotHasKey('content', $item);
            $this->assertArrayNotHasKey('content_stripped', $item);
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayHasKey('created_by', $item);
            $this->assertSame('Kata Sandi', $item['parent_nama']);
        }

        $detail = $this->json($this->get('api/v1/master/faq-article/1'))['data'];
        $this->assertStringContainsString('Lupa kata sandi', $detail['content']);

        $search = $this->json($this->get('api/v1/master/faq-article', ['search' => 'email dinas']))['data'];
        $this->assertSame(['1'], array_map('strval', array_column($search['items'], 'id_faq_article')));

        // Master lain tanpa listExclude tetap mengirim semua kolom.
        $this->assertArrayHasKey('remark', $this->json($this->get('api/v1/master/faq-topic'))['data']['items'][0]);

        $meta   = array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');
        $fields = array_column($meta['faq-article']['fields'], null, 'name');
        $this->assertSame(['name' => 'content', 'label' => 'Isi Artikel', 'type' => 'html', 'required' => true, 'options' => null, 'hint' => null, 'max_bytes' => 1000000], $fields['content']);
    }

    /**
     * E1: tabel FAQ punya created_by → insert mengisi created_by (updated_by NULL, seperti legacy), update mengisi
     * updated_by. Timestamp dari jam aplikasi (UTC).
     */
    public function testCreatedByOnInsertAndUpdatedByOnUpdate(): void
    {
        $adminId = $this->penggunaId(AuthSeeder::nipForRole(Role::SUPER_ADMIN));
        $this->asRole(Role::SUPER_ADMIN);
        Time::setTestNow('2020-01-02 03:04:05', 'UTC');

        $topic = $this->json($this->sendJson('POST', 'api/v1/master/faq-topic', ['faq_topic' => 'Audit', 'remark' => 'catatan']))['data'];
        $id    = (string) $topic['id_faq_topic'];
        $this->seeInDatabase('faq_topic', ['id_faq_topic' => $id, 'created_by' => $adminId, 'updated_by' => null, 'created_at' => '2020-01-02 03:04:05']);

        $article = $this->json($this->sendJson('POST', 'api/v1/master/faq-article', ['id_faq_sub_topic' => '1', 'title' => 'Audit', 'content' => '<p>x</p>']))['data'];
        $this->assertSame($adminId, (int) $article['created_by']);
        $this->assertNull($article['updated_by']);

        Time::setTestNow('2020-02-03 04:05:06', 'UTC');
        $this->sendJson('PUT', "api/v1/master/faq-topic/{$id}", ['remark' => 'catatan baru'])->assertStatus(200);
        $this->seeInDatabase('faq_topic', ['id_faq_topic' => $id, 'created_by' => $adminId, 'updated_by' => $adminId, 'updated_at' => '2020-02-03 04:05:06']);

        // created_by/updated_by tidak bisa dikirim dari input.
        $this->sendJson('PUT', "api/v1/master/faq-topic/{$id}", ['remark' => 'lagi', 'created_by' => 999, 'updated_by' => 999])->assertStatus(200);
        $this->seeInDatabase('faq_topic', ['id_faq_topic' => $id, 'created_by' => $adminId, 'updated_by' => $adminId]);
    }

    /**
     * E1 + CR-003: tambah dengan `order` langsung meng-insert di posisi final (dijepit 1..jumlah saudara tampil + 1),
     * sehingga baris baru tidak di-update sesudahnya (updated_by tetap NULL, tanpa audit 'update'); hanya saudara
     * yang digeser — tanpa mengubah updated_at/updated_by saudara.
     */
    public function testCreateWithOrderInsertsAtFinalPositionWithoutUpdatingNewRow(): void
    {
        $adminId = $this->penggunaId(AuthSeeder::nipForRole(Role::SUPER_ADMIN));
        $this->asRole(Role::SUPER_ADMIN);
        Time::setTestNow('2021-01-02 03:04:05', 'UTC');

        $created = $this->sendJson('POST', 'api/v1/master/faq-topic', ['faq_topic' => 'Cuti dan Izin', 'order' => '1']);
        $created->assertStatus(201);
        $topic = $this->json($created)['data'];
        $id    = (string) $topic['id_faq_topic'];

        $this->assertSame(1, (int) $topic['order']);
        $this->assertNull($topic['updated_by']);
        $this->seeInDatabase('faq_topic', ['id_faq_topic' => $id, 'order' => 1, 'created_by' => $adminId, 'updated_by' => null]);
        $this->seeInDatabase('audit_logs', ['entity' => 'faq_topic', 'entity_id' => $id, 'event' => 'create']);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'faq_topic', 'entity_id' => $id, 'event' => 'update']);

        // Saudara bergeser 1..n tanpa di-stamp (baris seed: updated_at/updated_by NULL).
        foreach ([1 => 2, 2 => 3, 3 => 4] as $sibling => $order) {
            $this->seeInDatabase('faq_topic', ['id_faq_topic' => $sibling, 'order' => $order, 'updated_at' => null, 'updated_by' => null]);
            $this->seeInDatabase('audit_logs', ['entity' => 'faq_topic', 'entity_id' => (string) $sibling, 'event' => 'update']);
        }

        // Posisi di luar jangkauan dijepit ke akhir (4 saudara tampil → 5), tetap tanpa update baris baru.
        $last = (string) $this->json($this->sendJson('POST', 'api/v1/master/faq-topic', ['faq_topic' => 'Lain-lain', 'order' => '99']))['data']['id_faq_topic'];
        $this->seeInDatabase('faq_topic', ['id_faq_topic' => $last, 'order' => 5, 'updated_by' => null]);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'faq_topic', 'entity_id' => $last, 'event' => 'update']);

        // Berinduk: sisip di posisi 1 sub topik 1 (artikel 1, 2 bergeser).
        $article = $this->json($this->sendJson('POST', 'api/v1/master/faq-article', [
            'id_faq_sub_topic' => '1', 'title' => 'Artikel sisipan', 'content' => '<p>Isi sisipan.</p>', 'order' => '1',
        ]))['data'];
        $this->assertNull($article['updated_by']);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'faq_article', 'entity_id' => (string) $article['id_faq_article'], 'event' => 'update']);
        $this->assertSame([(int) $article['id_faq_article'], 1, 2], array_column($this->tree()[1]['sub_topics'][0]['articles'], 'id'));
    }

    /**
     * CR-003: menggeser SAUDARA (reorder/hapus entri lain) tidak mengubah updated_at/updated_by saudara — "Diperbarui"
     * artikel pegawai tidak berubah hanya karena urutan. Baris yang diedit tetap di-stamp; audit tetap mencatat
     * perubahan order saudara.
     */
    public function testShiftingSiblingsDoesNotTouchTheirAuditColumns(): void
    {
        $adminId = $this->penggunaId(AuthSeeder::nipForRole(Role::SUPER_ADMIN));
        $this->asRole(Role::SUPER_ADMIN);
        Time::setTestNow('2021-05-06 07:08:09', 'UTC');

        // Artikel 2 ke posisi 1: artikel 2 diedit (di-stamp), artikel 1 hanya bergeser.
        $this->sendJson('PATCH', 'api/v1/master/faq-article/2/order', ['order' => 1])->assertStatus(200);
        $this->seeInDatabase('faq_article', ['id_faq_article' => 2, 'order' => 1, 'updated_by' => $adminId, 'updated_at' => '2021-05-06 07:08:09']);
        $this->seeInDatabase('faq_article', ['id_faq_article' => 1, 'order' => 2, 'updated_by' => null, 'updated_at' => null]);

        $log   = $this->db->table('audit_logs')->where(['entity' => 'faq_article', 'entity_id' => '1', 'event' => 'update'])->get()->getRowArray();
        $after = json_decode((string) $log['after_json'], true);
        $this->assertSame(2, (int) $after['order']);
        $this->assertNull($after['updated_at']);

        // Hapus artikel 2 → artikel 1 dirapatkan ke 1, tetap tanpa stamp; detail pegawai tetap memakai created_at.
        $this->delete('api/v1/master/faq-article/2')->assertStatus(200);
        $this->seeInDatabase('faq_article', ['id_faq_article' => 1, 'order' => 1, 'updated_by' => null, 'updated_at' => null]);

        // Pulihkan artikel 2 lalu PUT dengan order: yang diedit di-stamp, saudara tidak.
        $this->sendJson('PATCH', 'api/v1/master/faq-article/2/status', ['status' => '1'])->assertStatus(200);
        $this->sendJson('PUT', 'api/v1/master/faq-article/2', ['order' => 1])->assertStatus(200);
        $this->seeInDatabase('faq_article', ['id_faq_article' => 2, 'order' => 1, 'updated_by' => $adminId]);
        $this->seeInDatabase('faq_article', ['id_faq_article' => 1, 'order' => 2, 'updated_by' => null, 'updated_at' => null]);

        $created = $this->db->table('faq_article')->select('created_at')->where('id_faq_article', 1)->get()->getRowArray()['created_at'];
        $this->assertSame($created, $this->json($this->asRole(Role::PEGAWAI)->get('api/v1/faq/1'))['data']['updated_at']);
    }

    /**
     * CR-003: dropdown options master FAQ hanya untuk role 1 (konsumennya hanya form admin). Terbuka untuk semua role,
     * endpoint ini membocorkan judul entri yang rantainya non-aktif (U3). Query options hanya membaca kolom yang
     * dipakai (tanpa LONGTEXT isi artikel).
     */
    public function testFaqOptionsAreSuperAdminOnlyAndSkipLargeColumns(): void
    {
        service('masterService')->options(service('masterRegistry')->get('faq-article'));
        $sql = (string) $this->db->getLastQuery();
        $this->assertStringContainsString('`id_faq_article`', $sql);
        $this->assertStringNotContainsString('content', $sql);
        $this->assertStringNotContainsString('*', $sql);

        // Topik 3 dihapus: sub topik 4 & artikel 5 tersembunyi dari pegawai, juga lewat options.
        $this->asRole(Role::SUPER_ADMIN)->delete('api/v1/master/faq-topic/3')->assertStatus(200);

        foreach (['faq-topic' => null, 'faq-sub-topic' => '3', 'faq-article' => '4'] as $entity => $parent) {
            $query = $parent === null ? [] : ['parent' => $parent];

            foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
                $result = $this->asRole($role)->get("api/v1/master/{$entity}/options", $query);
                $result->assertStatus(403);
                $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $this->json($result), "{$entity} role {$role}");
            }

            $this->withHeaders(['Authorization' => ''])->get("api/v1/master/{$entity}/options")->assertStatus(401);
            $this->asRole(Role::SUPER_ADMIN);
            $this->assertNotSame([], $this->optionIds($entity, $entity === 'faq-topic' ? null : '1'), $entity);
        }

        // Master lain tetap UL_ALL.
        $this->assertNotSame([], $this->asRole(Role::PEGAWAI)->optionIds('agama'));
    }

    /**
     * CR-003: kata kunci / alasan yang bukan UTF-8 valid → 422 pada field terkait (sebelumnya 500 karena `search`
     * dipantulkan ke JSON, dan `reason` gagal ditulis di koneksi strict).
     */
    public function testInvalidUtf8SearchOrReasonIsRejected(): void
    {
        $this->asRole(Role::PEGAWAI);

        // Pendek (fallback LIKE) dan ≥ 3 byte (jalur FULLTEXT), lewat query string mentah.
        foreach (['%C3', '%C3%C3%C3', 'sandi%FF'] as $raw) {
            $result = $this->get("api/v1/faq?search={$raw}");
            $result->assertStatus(422);
            $this->assertSame(['Kata kunci pencarian tidak valid.'], $this->json($result)['errors']['search'], $raw);
        }

        // Alasan tidak bisa berisi byte non-UTF-8 lewat JSON; lewat form-urlencoded bisa.
        $body   = ['rate' => '2', 'reason' => "\xFF\xFF"];
        $result = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueTokensFor(AuthSeeder::nipForRole(Role::PEGAWAI))['access_token'],
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ])->withBody(http_build_query($body))->post('api/v1/faq/1/rate', $body);
        $result->assertStatus(422);
        $this->assertSame(['Alasan tidak valid.'], $this->json($result)['errors']['reason']);
        $this->assertSame(0, $this->db->table('faq_rate')->countAllResults());
    }

    /**
     * CR-003: induk ber-PK AUTO_INCREMENT hanya menerima id kanonik. MySQL meng-cast '01'/'1abc' ke 1 untuk kolom INT,
     * sehingga tanpa cek ini id tersebut lolos sebagai induk 1 (memicu pindah urutan palsu atau 500 saat tulis).
     */
    public function testNonCanonicalParentIdIsRejected(): void
    {
        $this->asRole(Role::SUPER_ADMIN);
        $cases = [
            'faq-sub-topic' => ['id_faq_topic', 'Topik FAQ', ['faq_sub_topic' => 'Sub Topik Induk Aneh'], '2'],
            'faq-article'   => ['id_faq_sub_topic', 'Sub Topik FAQ', ['title' => 'Artikel Induk Aneh', 'content' => '<p>Isi.</p>'], '2'],
        ];

        foreach ($cases as $entity => [$field, $label, $payload, $existing]) {
            foreach (['01', '1abc', '0', '1.0', '+1'] as $parent) {
                $result = $this->sendJson('POST', "api/v1/master/{$entity}", [$field => $parent] + $payload);
                $result->assertStatus(422);
                $this->assertSame(["{$label} tidak ditemukan."], $this->json($result)['errors'][$field], "{$entity} POST {$parent}");

                $result = $this->sendJson('PUT', "api/v1/master/{$entity}/{$existing}", [$field => $parent]);
                $result->assertStatus(422);
                $this->assertSame(["{$label} tidak ditemukan."], $this->json($result)['errors'][$field], "{$entity} PUT {$parent}");
            }
        }

        $this->dontSeeInDatabase('faq_sub_topic', ['faq_sub_topic' => 'Sub Topik Induk Aneh']);
        $this->dontSeeInDatabase('faq_article', ['title' => 'Artikel Induk Aneh']);
        // Tidak ada pindah induk / pindah urutan palsu.
        $this->seeInDatabase('faq_sub_topic', ['id_faq_sub_topic' => 2, 'id_faq_topic' => 1, 'order' => 2, 'updated_by' => null]);
        $this->seeInDatabase('faq_article', ['id_faq_article' => 2, 'id_faq_sub_topic' => 1, 'order' => 2, 'updated_by' => null]);

        // Id kanonik tetap diterima.
        $this->sendJson('POST', 'api/v1/master/faq-sub-topic', ['id_faq_topic' => '1', 'faq_sub_topic' => 'Sub Topik Induk Aneh'])->assertStatus(201);
    }

    /**
     * D6: kolom `icon` topik belum dikelola dan tidak diekspos di respons admin mana pun (daftar, detail, tulis,
     * options); nilainya di DB tidak tersentuh.
     */
    public function testTopicIconIsNotExposedByAdminApi(): void
    {
        $this->db->table('faq_topic')->where('id_faq_topic', 1)->update(['icon' => 'assets/upload/faq/topic/akun.png']);
        $this->asRole(Role::SUPER_ADMIN);

        $responses = [
            'list'   => $this->json($this->get('api/v1/master/faq-topic'))['data']['items'],
            'detail' => [$this->json($this->get('api/v1/master/faq-topic/1'))['data']],
            'create' => [$this->json($this->sendJson('POST', 'api/v1/master/faq-topic', ['faq_topic' => 'Topik Baru', 'icon' => 'x.png']))['data']],
            'update' => [$this->json($this->sendJson('PUT', 'api/v1/master/faq-topic/1', ['remark' => 'ubah', 'icon' => 'y.png']))['data']],
            'order'  => [$this->json($this->sendJson('PATCH', 'api/v1/master/faq-topic/1/order', ['order' => 2]))['data']],
            'status' => [$this->json($this->sendJson('PATCH', 'api/v1/master/faq-topic/1/status', ['status' => '2']))['data']],
            'delete' => [$this->json($this->delete('api/v1/master/faq-topic/1'))['data']['item']],
        ];

        foreach ($responses as $case => $rows) {
            $this->assertNotSame([], $rows, $case);

            foreach ($rows as $row) {
                $this->assertArrayNotHasKey('icon', $row, $case);
                $this->assertArrayHasKey('faq_topic', $row, $case);
            }
        }

        $this->seeInDatabase('faq_topic', ['id_faq_topic' => 1, 'icon' => 'assets/upload/faq/topic/akun.png']);
        $this->seeInDatabase('faq_topic', ['faq_topic' => 'Topik Baru', 'icon' => null]);
    }

    // ------------------------------------------------------------------
    // Rating (UL_PEGAWAI: 2, 6, 7)
    // ------------------------------------------------------------------

    public function testEmployeeRolesCanRateOnceWithAuditTrail(): void
    {
        Time::setTestNow('2020-01-02 03:04:05', 'UTC');

        foreach (self::RATER_ROLES as $role) {
            $nip = AuthSeeder::nipForRole($role);
            $this->asRole($role);

            $result = $this->sendJson('POST', 'api/v1/faq/1/rate', ['rate' => 1, 'reason' => 'diabaikan untuk rate 1']);
            $result->assertStatus(201);
            $this->assertSame(['status' => 'success', 'data' => ['rated' => true, 'rate' => 1]], $this->json($result), "role {$role}");

            $this->seeInDatabase('faq_rate', [
                'id_faq_article' => 1, 'nip' => $nip, 'rate' => 1, 'reason' => null,
                'created_by'     => $this->penggunaId($nip), 'created_at' => '2020-01-02 03:04:05',
            ]);
            $this->seeInDatabase('audit_logs', ['entity' => 'faq_rate', 'entity_id' => "1:{$nip}", 'event' => 'create', 'nip_actor' => $nip]);

            // Sekali saja, tidak bisa diubah.
            $again = $this->sendJson('POST', 'api/v1/faq/1/rate', ['rate' => 2, 'reason' => 'ganti pendapat']);
            $again->assertStatus(422);
            $this->assertSame(['Artikel ini sudah Anda nilai.'], $this->json($again)['errors']['rate']);

            $rating = $this->json($this->get('api/v1/faq/1'))['data']['rating'];
            $this->assertSame(['can_rate' => false, 'rated' => true, 'rate' => 1], $rating, "role {$role}");
        }

        $this->assertSame(3, $this->db->table('faq_rate')->countAllResults());
        $this->seeInDatabase('faq_rate', ['id_faq_article' => 1, 'nip' => AuthSeeder::nipForRole(Role::PEGAWAI), 'rate' => 1]);
    }

    public function testNonEmployeeRolesCannotRate(): void
    {
        foreach (self::NON_RATER_ROLES as $role) {
            $result = $this->asRole($role)->sendJson('POST', 'api/v1/faq/1/rate', ['rate' => 1]);
            $result->assertStatus(403);
            $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $this->json($result), "role {$role}");
        }

        $this->withHeaders(['Authorization' => ''])->sendJson('POST', 'api/v1/faq/1/rate', ['rate' => 1])->assertStatus(401);
        $this->assertSame(0, $this->db->table('faq_rate')->countAllResults());
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'faq_rate']);
    }

    public function testRatingValidation(): void
    {
        $this->asRole(Role::PEGAWAI);
        $nip = AuthSeeder::nipForRole(Role::PEGAWAI);

        foreach ([['rate' => 3], ['rate' => 0], ['rate' => 'abc'], ['rate' => ''], []] as $body) {
            $result = $this->sendJson('POST', 'api/v1/faq/1/rate', $body);
            $result->assertStatus(422);
            $this->assertArrayHasKey('rate', $this->json($result)['errors'], json_encode($body, JSON_THROW_ON_ERROR));
        }

        // rate 2 (Kurang Membantu): alasan wajib, di-trim, maks 255 BYTE (bukan karakter).
        foreach ([[], ['reason' => '   '], ['reason' => ['a']], ['reason' => str_repeat('é', 128)]] as $extra) {
            $result = $this->sendJson('POST', 'api/v1/faq/1/rate', ['rate' => 2] + $extra);
            $result->assertStatus(422);
            $this->assertArrayHasKey('reason', $this->json($result)['errors'], json_encode($extra, JSON_THROW_ON_ERROR));
        }

        $this->assertSame(0, $this->db->table('faq_rate')->countAllResults());

        $reason = str_repeat('é', 127) . 'a';
        $this->assertSame(255, strlen($reason));
        $this->sendJson('POST', 'api/v1/faq/1/rate', ['rate' => '2', 'reason' => "  {$reason}  "])->assertStatus(201);
        $this->seeInDatabase('faq_rate', ['id_faq_article' => 1, 'nip' => $nip, 'rate' => 2, 'reason' => $reason]);
        $this->assertSame(['can_rate' => false, 'rated' => true, 'rate' => 2], $this->json($this->get('api/v1/faq/1'))['data']['rating']);

        // Artikel lain belum dinilai.
        $this->assertSame(['can_rate' => true, 'rated' => false, 'rate' => null], $this->json($this->get('api/v1/faq/2'))['data']['rating']);
    }

    public function testHiddenOrUnknownArticleCannotBeRated(): void
    {
        $this->asRole(Role::SUPER_ADMIN)->sendJson('PATCH', 'api/v1/master/faq-article/2/status', ['status' => '2'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/faq-sub-topic/3/status', ['status' => '2'])->assertStatus(200);
        $this->delete('api/v1/master/faq-topic/3')->assertStatus(200);

        $this->asRole(Role::PEGAWAI);

        // 2: artikel Tidak Aktif; 4: sub topik Tidak Aktif; 5: topik Dihapus; 999: tidak ada; 01: non-kanonik.
        foreach (['2', '4', '5', '999', '01', 'abc'] as $id) {
            $this->sendJson('POST', "api/v1/faq/{$id}/rate", ['rate' => 1])->assertStatus(404);
        }

        $this->assertSame(0, $this->db->table('faq_rate')->countAllResults());
    }

    /**
     * Balapan: dua permintaan lolos cek "sudah menilai"; PK (id_faq_article, nip) menolak yang kedua (1062) dan
     * diterjemahkan ke 422 yang sama.
     */
    public function testDuplicateRatingRaceIsTranslatedTo422(): void
    {
        $nip     = AuthSeeder::nipForRole(Role::PEGAWAI);
        $service = new FaqService();
        $blind   = new class ($this->db) extends FaqRateModel {
            public function findRating(int $articleId, string $nip): ?array
            {
                return null;
            }
        };
        (new ReflectionProperty(FaqService::class, 'rates'))->setValue($service, $blind);

        $this->assertSame(['rated' => true, 'rate' => 1], $service->rate('1', $nip, Role::PEGAWAI, 1, null));

        try {
            $service->rate('1', $nip, Role::PEGAWAI, 2, 'balapan');
            $this->fail('PK ganda harus diterjemahkan menjadi ValidationException (422).');
        } catch (ValidationException $e) {
            $this->assertSame(['rate' => ['Artikel ini sudah Anda nilai.']], $e->getErrors());
        }

        $this->seeInDatabase('faq_rate', ['id_faq_article' => 1, 'nip' => $nip, 'rate' => 1]);
        $this->assertSame(1, $this->db->table('faq_rate')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function tree(): array
    {
        $result = $this->get('api/v1/faq');
        $result->assertStatus(200);

        return $this->json($result)['data']['topics'];
    }

    /**
     * @return list<int>
     */
    private function searchIds(string $search): array
    {
        $result = $this->get('api/v1/faq', ['search' => $search]);
        $result->assertStatus(200);

        return array_column($this->json($result)['data']['results'], 'id');
    }

    private function insertArticle(int $id, int $subTopic, string $title, string $content, int $status = 1): void
    {
        $this->db->table('faq_article')->insert($this->articleRow($id, $subTopic, $title, $content, $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function articleRow(int $id, int $subTopic, string $title, string $content, int $status = 1): array
    {
        return [
            'id_faq_article'   => $id,
            'id_faq_sub_topic' => $subTopic,
            'title'            => $title,
            'content'          => $content,
            'content_stripped' => trim((string) preg_replace('/\t+/', '', strip_tags($content))),
            'order'            => $id,
            'status'           => $status,
        ];
    }

    private function penggunaId(string $nip): int
    {
        return (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }
}
