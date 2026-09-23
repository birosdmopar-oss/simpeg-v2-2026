<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * G-10 — FAQ. DoD: CRUD topic → sub-topic → article (role 1) dan view terbuka untuk UL_ALL (MTC-013, MTC-014).
 * Rating artikel (`faq_rate`) BELUM diimplementasikan — DDL legacy belum tersedia & butuh tabel `pegawai` (Fase 3).
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    /**
     * MTC-013: pegawai bisa melihat & mencari FAQ published, tapi tidak bisa CRUD.
     */
    public function testEveryLoggedInRoleCanBrowsePublishedFaq(): void
    {
        foreach (Role::all() as $role) {
            $this->asRole($role);

            $result = $this->get('api/v1/faq');
            $result->assertStatus(200);
            $topics = $this->json($result)['data'];

            $this->assertSame(['Kepegawaian', 'Presensi & Tukin', 'Aplikasi SIMPEG'], array_column($topics, 'nama_topic'), "role {$role}");
            $this->assertSame(['Cuti', 'Kenaikan Pangkat'], array_column($topics[0]['sub_topics'], 'nama_sub_topic'));
            $this->assertSame(
                ['Bagaimana cara mengajukan cuti tahunan?', 'Berapa hak cuti tahunan saya?'],
                array_column($topics[0]['sub_topics'][0]['articles'], 'judul'),
            );

            $article = $this->get('api/v1/faq/A01');
            $article->assertStatus(200);
            $this->assertSame('Cuti', $this->json($article)['data']['nama_sub_topic']);
            $this->assertStringContainsString('Layanan', (string) $this->json($article)['data']['isi']);
        }

        $this->withHeaders(['Authorization' => ''])->get('api/v1/faq')->assertStatus(401);
    }

    public function testSearchFiltersArticlesByTitleAndBody(): void
    {
        $this->asRole(Role::PEGAWAI);

        $byTitle = $this->json($this->get('api/v1/faq', ['search' => 'hak cuti']))['data'];
        $this->assertSame(['Kepegawaian'], array_column($byTitle, 'nama_topic'));
        $this->assertSame(['Berapa hak cuti tahunan saya?'], array_column($byTitle[0]['sub_topics'][0]['articles'], 'judul'));

        $byBody = $this->json($this->get('api/v1/faq', ['search' => 'radius']))['data'];
        $this->assertSame(['Presensi & Tukin'], array_column($byBody, 'nama_topic'));

        $this->assertSame([], $this->json($this->get('api/v1/faq', ['search' => 'tidak ada kata ini']))['data']);
    }

    /**
     * Konten non-aktif tidak boleh bocor ke pegawai — baik lewat daftar maupun akses langsung.
     */
    public function testUnpublishedContentIsHiddenFromEmployees(): void
    {
        $this->asRole(Role::SUPER_ADMIN);
        $this->sendJson('PATCH', 'api/v1/master/faq-article/A01/status', ['status' => '0'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/faq-sub-topic/S03/status', ['status' => '0'])->assertStatus(200);

        $this->asRole(Role::PEGAWAI);
        $topics = $this->json($this->get('api/v1/faq'))['data'];
        $byName = array_column($topics, null, 'nama_topic');

        $this->assertSame(['Berapa hak cuti tahunan saya?'], array_column($byName['Kepegawaian']['sub_topics'][0]['articles'], 'judul'));
        $this->assertSame([], array_column($byName['Presensi & Tukin']['sub_topics'], 'nama_sub_topic'));

        $this->get('api/v1/faq/A01')->assertStatus(404);
        $this->get('api/v1/faq/A03')->assertStatus(404); // sub topik non-aktif
        $this->get('api/v1/faq/TIDAK')->assertStatus(404);
    }

    /**
     * MTC-014: role 1 CRUD penuh; perubahan langsung terlihat di sisi pegawai.
     */
    public function testSuperAdminCrudIsImmediatelyVisibleToEmployees(): void
    {
        $this->asRole(Role::SUPER_ADMIN);

        $this->sendJson('POST', 'api/v1/master/faq-topic', ['id_topic' => 'T09', 'nama_topic' => 'Tugas Belajar'])->assertStatus(201);
        $this->sendJson('POST', 'api/v1/master/faq-sub-topic', ['id_sub_topic' => 'S09', 'id_topic' => 'T09', 'nama_sub_topic' => 'Beasiswa'])->assertStatus(201);
        $this->sendJson('POST', 'api/v1/master/faq-article', [
            'id_article' => 'A09', 'id_sub_topic' => 'S09', 'judul' => 'Bagaimana cara mengajukan tugas belajar?', 'isi' => 'Ajukan lewat menu Layanan > Tugas Belajar.',
        ])->assertStatus(201);

        $this->asRole(Role::PEGAWAI);
        $topics = array_column($this->json($this->get('api/v1/faq'))['data'], null, 'id_topic');
        $this->assertSame('Beasiswa', $topics['T09']['sub_topics'][0]['nama_sub_topic']);
        $this->get('api/v1/faq/A09')->assertStatus(200);

        // Edit judul & nonaktifkan → langsung ter-reflect.
        $this->asRole(Role::SUPER_ADMIN);
        $this->sendJson('PUT', 'api/v1/master/faq-article/A09', ['judul' => 'Cara mengajukan tugas belajar'])->assertStatus(200);

        $this->asRole(Role::PEGAWAI);
        $this->assertSame('Cara mengajukan tugas belajar', $this->json($this->get('api/v1/faq/A09'))['data']['judul']);

        $this->asRole(Role::SUPER_ADMIN);
        $this->delete('api/v1/master/faq-article/A09')->assertStatus(200);

        $this->asRole(Role::PEGAWAI);
        $this->get('api/v1/faq/A09')->assertStatus(404);
    }

    public function testFaqContentManagementIsSuperAdminOnly(): void
    {
        foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
            $this->asRole($role);

            foreach (['faq-topic', 'faq-sub-topic', 'faq-article'] as $entity) {
                $this->get("api/v1/master/{$entity}")->assertStatus(403);
                $this->sendJson('POST', "api/v1/master/{$entity}", [])->assertStatus(403);
            }

            $this->sendJson('PUT', 'api/v1/master/faq-article/A01', ['judul' => 'Diubah pegawai'])->assertStatus(403);
            $this->delete('api/v1/master/faq-topic/T01')->assertStatus(403);
        }

        $this->seeInDatabase('faq_article', ['id_article' => 'A01', 'judul' => 'Bagaimana cara mengajukan cuti tahunan?']);
        $this->seeInDatabase('faq_topic', ['id_topic' => 'T01', 'status' => '1']);
    }
}
