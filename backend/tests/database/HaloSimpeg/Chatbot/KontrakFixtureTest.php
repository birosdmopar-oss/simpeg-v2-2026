<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use App\Libraries\HaloSimpeg\Chatbot\QnaService;
use App\Libraries\HaloSimpeg\Chatbot\TokenFeedback;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Chatbot;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;

/**
 * Kontrak beku MAKE-094 ↔ MAKE-095: bentuk respons ASLI (kunci dan tipe JSON, rekursif) sama dengan contoh respons sintetis
 * `tests/_support/HaloSimpeg/Chatbot/*.json` yang disalin FE (PRD Fase 8 §3). Juga: gagal sisip relasi tag → QnA tidak
 * tersimpan (AC-E10.25).
 *
 * @internal
 */
final class KontrakFixtureTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use ChatbotFixtureTrait;
    use FeatureTestTrait;

    private const URL = 'api/v1/halo-simpeg/chatbot/';

    protected $seed = AuthSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangConfigChatbot();
        $this->pasangThrottlerChatbot();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    public function testBentukResponsSamaDenganFixture(): void
    {
        $kat  = $this->buatKategori('Cuti', 1, 1);
        $kat2 = $this->buatKategori('Kinerja', 2);
        $t1   = $this->buatTag('cuti', 2);
        $t2   = $this->buatTag('sandi');
        $q1   = $this->buatQna('Bagaimana cara mengajukan cuti tahunan?', $kat, [$t1, $t2], 1, 1);
        $this->buatQna('Berapa lama cuti besar?', $kat2, [$t1], 2);
        $this->buatQna('Bagaimana cara mengganti kata sandi?', $kat, [$t2], 1, 1);

        $this->cocok('widget-kategori', $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori')->getJSON());
        $this->cocok('widget-pertanyaan', $this->asRole(Role::PEGAWAI)->get(self::URL . "widget/kategori/{$kat}/pertanyaan")->getJSON());
        $this->cocok('widget-populer', $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/populer')->getJSON());
        $cocok = $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'bagaimana cara mengajukan cuti tahunan'])->getJSON();
        $this->cocok('widget-balas-cocok', $cocok);
        $this->cocok('widget-balas-fallback', $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'zzz'])->getJSON());
        $c = json_decode((string) $cocok, true)['data'];
        $this->cocok('widget-feedback', $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $c['id_log'], 'nilai' => -1, 'token' => $c['token_feedback']])->getJSON());
        $this->cocok('galat-422-pesan', $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => ''])->getJSON());
        $this->cocok('galat-403-feedback', $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $c['id_log'], 'nilai' => 1, 'token' => 'x'])->getJSON());

        $this->cocok('kategori-daftar', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'kategori')->getJSON());
        $this->cocok('kategori-detail', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . "kategori/{$kat}")->getJSON());
        $this->cocok('tag-daftar', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'tag')->getJSON());
        $this->cocok('qna-daftar', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'qna')->getJSON());
        $this->cocok('qna-detail', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . "qna/{$q1}")->getJSON());
        $this->cocok('qna-opsi', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'qna/opsi')->getJSON());
        $this->cocok('statistik', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'statistik')->getJSON());
        $this->cocok('riwayat', $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'riwayat')->getJSON());

        // Galat lain dengan status masing-masing.
        $tidakCocok = $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'zzz']);
        $logTC      = json_decode((string) $tidakCocok->getJSON(), true)['data']['id_log'];
        $tokTC      = (new TokenFeedback(config(Chatbot::class)))->buat((int) $logTC, (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole(Role::PEGAWAI))->get()->getRow('id_pengguna'));
        $this->cocok('galat-422-feedback-tidak-cocok', $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $logTC, 'nilai' => 1, 'token' => $tokTC]), 422);
        $hapus = $this->buatKategori('Dihapus', 10);
        $this->db->table('qna')->where('id_qna', $q1)->update(['id_qna_kategori_fk' => $hapus]);
        $this->cocok('galat-422-qna', $this->asRole(Role::SUPER_ADMIN)->withBodyFormat('json')->put(self::URL . "qna/{$q1}", ['pertanyaan' => 'P', 'jawaban' => '<p>J</p>', 'id_kategori' => $hapus, 'tag' => [], 'is_fallback_option' => 0]), 422);

        // Impor lewat HTTP multipart (sukses + galat), keduanya 200.
        $this->cocok('qna-impor-sukses', $this->imporHttp([['Impor satu?', 'Jawab', 'Impor', 'a, b'], ['', 'lewati', 'Impor', '']]), 200);
        $this->cocok('qna-impor-galat', $this->imporHttp([['Tanpa kategori?', 'Jawab', '', '']]), 200);

        $this->pasangConfigChatbot(self::KUNCI_UJI, static function (Chatbot $c): void {
            $c->throttleBaca = 1;
        });
        $this->pasangThrottlerChatbot();
        $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/populer')->assertStatus(200);
        $this->cocok('galat-429', $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/populer'), 429);

        $this->pasangConfigChatbot();
        $this->bersihkanChatbot();
        $this->cocok('widget-balas-korpus-kosong', $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cuti'])->getJSON());
    }

    #[Group('db-isolasi-penuh')]
    public function testGagalSisipRelasiTagQnaTidakTersimpan(): void
    {
        $kat = $this->buatKategori('K');
        $tag = $this->buatTag('t');
        $trg = $this->db->prefixTable('trg_uji_tolak_relasi');
        $this->db->query("CREATE TRIGGER {$trg} BEFORE INSERT ON " . $this->db->prefixTable('qna_has_tag') . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji'");

        try {
            try {
                (new QnaService($this->db))->simpan(null, ['pertanyaan' => 'P', 'jawaban' => 'J', 'id_kategori' => $kat, 'tag' => [$tag], 'is_fallback_option' => 0]);
                $this->fail('sisip relasi seharusnya gagal');
            } catch (DatabaseException $e) {
                $this->assertSame('uji', $e->getMessage());
            }

            $this->assertSame(0, $this->db->table('qna')->countAllResults(), 'QnA ikut dibatalkan');
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
            $this->bersihkanChatbot();
        }
    }

    /**
     * POST multipart `qna/impor` (field `berkas`) dengan xlsx sintetis. FeatureTestTrait tidak mengirim berkas, jadi array berkas
     * diisi lewat service `superglobals` (dibaca FileCollection CI4 saat request diproses).
     *
     * @param list<list<string>> $baris
     */
    private function imporHttp(array $baris): TestResponse
    {
        $isi  = EksporTabel::xlsx(ImporQna::JUDUL_KOLOM, $baris);
        $path = (string) tempnam(sys_get_temp_dir(), 'cbh');
        file_put_contents($path, $isi);
        service('superglobals')->setFilesArray(['berkas' => ['name' => 'qna.xlsx', 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($isi)]]);

        try {
            return $this->asRole(Role::SUPER_ADMIN)->post(self::URL . 'qna/impor');
        } finally {
            service('superglobals')->setFilesArray([]);
            @unlink($path);
        }
    }

    /**
     * Bandingkan bentuk (kunci + tipe JSON) secara rekursif. Daftar dibandingkan lewat elemen pertama; daftar kosong = bentuk
     * `[]` (harus kosong juga di sisi lain); `null` di salah satu sisi = kolom nullable, dianggap sama dengan tipe sisi lain.
     * Bila `$status` diberikan, status HTTP ikut ditegaskan.
     */
    private function cocok(string $fixture, TestResponse|string|null $json, ?int $status = null): void
    {
        if ($json instanceof TestResponse) {
            if ($status !== null) {
                $json->assertStatus($status);
            }

            $json = $json->getJSON();
        }

        $harap  = json_decode((string) file_get_contents(SUPPORTPATH . "HaloSimpeg/Chatbot/{$fixture}.json"), true, 512, JSON_THROW_ON_ERROR);
        $aktual = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(self::samakanNull(self::bentuk($harap), self::bentuk($aktual)), self::samakanNull(self::bentuk($aktual), self::bentuk($harap)), $fixture);
    }

    /**
     * Kolom nullable (`string | null`, `Ringkas | null`): `null` di satu sisi dianggap sama dengan tipe di sisi lain.
     */
    private static function samakanNull(mixed $a, mixed $b): mixed
    {
        if ($a === 'null' && $b !== 'null') {
            return $b;
        }

        if (is_array($a) && is_array($b)) {
            foreach ($a as $k => $v) {
                if (array_key_exists($k, $b)) {
                    $a[$k] = self::samakanNull($v, $b[$k]);
                }
            }
        }

        return $a;
    }

    private static function bentuk(mixed $v): mixed
    {
        if (is_array($v) && array_is_list($v)) {
            return $v === [] ? '[]' : ['[]' => self::bentuk($v[0])];
        }

        if (is_array($v)) {
            $out = [];
            ksort($v);

            foreach ($v as $k => $x) {
                $out[$k] = self::bentuk($x);
            }

            return $out;
        }

        return match (true) {
            is_int($v), is_float($v) => 'number',
            is_bool($v)              => 'bool',
            is_string($v)            => 'string',
            default                  => 'null',
        };
    }
}
