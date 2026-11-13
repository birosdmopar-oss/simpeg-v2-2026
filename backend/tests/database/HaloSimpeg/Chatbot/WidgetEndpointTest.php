<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\Chatbot\ChatbotService;
use App\Libraries\HaloSimpeg\Chatbot\TokenFeedback;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Chatbot;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;

/**
 * Widget Vanya lewat HTTP (MAKE-094; PRD Fase 8 §6.5 `widget/*`, AC-E10.5..11, AC-E10.14, AC-E10.19, AC-E10.27, AC-E10.29).
 * Data sintetis.
 *
 * @internal
 */
final class WidgetEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use ChatbotFixtureTrait;
    use FeatureTestTrait;

    private const URL = 'api/v1/halo-simpeg/chatbot/';

    protected $seed = AuthSeeder::class;

    private int $katCuti;

    private int $qCuti;

    private int $qSandi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangConfigChatbot();
        $this->pasangThrottlerChatbot();

        $this->katCuti = $this->buatKategori('Cuti', 1, 1);
        $katAkun       = $this->buatKategori('Akun', 1, 1);
        $this->buatKategori('Kosong', 1, 1);
        $katNonaktif  = $this->buatKategori('Pensiun', 2, 1);
        $tagCuti      = $this->buatTag('cuti');
        $this->qCuti  = $this->buatQna('cara mengajukan cuti tahunan', $this->katCuti, [$tagCuti]);
        $this->qSandi = $this->buatQna('cara mengganti kata sandi', $katAkun, [], 1, 1);
        $this->buatQna('syarat pensiun dini', $katNonaktif);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    public function testRoleWidgetDanAdmin(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::PIMPINAN] as $role) {
            $this->asRole($role)->get(self::URL . 'widget/kategori')->assertStatus(403);
            $this->asRole($role)->post(self::URL . 'widget/balas', ['pesan' => 'cuti'])->assertStatus(403);
        }

        foreach (Role::UL_PEGAWAI as $role) {
            $this->asRole($role)->get(self::URL . 'widget/kategori')->assertStatus(200);
            $this->asRole($role)->get(self::URL . 'kategori')->assertStatus(403);
        }

        $this->withHeaders([])->get(self::URL . 'widget/kategori')->assertStatus(401);
    }

    public function testBubbleTopikPertanyaanPopuler(): void
    {
        $this->assertSame([['id' => $this->katCuti, 'judul' => 'Cuti'], ['id' => $this->katCuti + 1, 'judul' => 'Akun']], $this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori')));

        $q2 = $this->buatQna('cuti besar', $this->katCuti);
        $q3 = $this->buatQna('cuti sakit', $this->katCuti, [], 1, 1);
        $this->buatQna('cuti dihapus', $this->katCuti, [], 10);
        $this->buatLog($q2);
        $this->buatLog($q2);
        $this->buatLog($this->qCuti);

        $hasil = $this->data($this->asRole(Role::PEGAWAI)->get(self::URL . "widget/kategori/{$this->katCuti}/pertanyaan"));
        $this->assertSame([$q3, $q2, $this->qCuti], array_column($hasil, 'id_qna'), 'unggulan → paling sering cocok → id');
        $this->assertSame([], $this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori/0/pertanyaan')));
        $this->assertSame([], $this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori/999999/pertanyaan')));

        $this->assertSame([$q3, $this->qSandi], array_column($this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/populer')), 'id_qna'));
    }

    public function testBalasCocokMenulisSatuLogAnonimDanToken(): void
    {
        $auditSebelum = $this->db->table('audit_logs')->countAllResults();
        $d            = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => '  bagaimana cara mengajukan cuti tahunan  ']));

        $this->assertTrue($d['cocok']);
        $this->assertSame('<p>Jawaban cara mengajukan cuti tahunan</p>', $d['balasan']);
        $this->assertTrue($d['bisa_feedback']);
        $this->assertIsString($d['token_feedback']);
        $this->assertSame([], $d['saran']);

        $log = $this->db->table('log_chatbot')->get()->getResultArray();
        $this->assertCount(1, $log);
        $this->assertSame([(int) $log[0]['id_log'], 'bagaimana cara mengajukan cuti tahunan', $this->qCuti, 1], [$d['id_log'], $log[0]['pesan_user'], (int) $log[0]['top_1_id_qna'], (int) $log[0]['status_match']]);
        $this->assertSame(['id_log', 'pesan_user', 'top_1_id_qna', 'top_1_skor', 'top_3_kandidat', 'status_match', 'feedback', 'waktu_interaksi'], array_keys($log[0]), 'tanpa identitas');
        $this->assertSame($auditSebelum, $this->db->table('audit_logs')->countAllResults(), 'log_chatbot tidak masuk audit_logs');
    }

    public function testBalasTidakCocokFallbackDanSaran(): void
    {
        $d = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => '?!...']));

        $this->assertFalse($d['cocok']);
        $this->assertSame(ChatbotService::BALASAN_FALLBACK, $d['balasan']);
        $this->assertFalse($d['bisa_feedback']);
        $this->assertArrayNotHasKey('token_feedback', $d);
        $this->assertSame([$this->qSandi], array_column($d['saran'], 'id_qna'));
        $this->assertSame('0', (string) $this->db->table('log_chatbot')->get()->getRow('status_match'));
    }

    public function testJalurBubbleMengabaikanPesanKlien(): void
    {
        $panjang = $this->buatQna(str_repeat('pertanyaan panjang ', 20), $this->katCuti);
        $d       = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'teks dari klien', 'id_qna' => $panjang]));

        $this->assertSame([true, 1.0], [$d['cocok'], (float) $d['skor']]);
        $this->assertSame(str_repeat('pertanyaan panjang ', 20), $this->db->table('log_chatbot')->get()->getRow('pesan_user'), 'pesan_user = pertanyaan QnA (F8-48)');

        // id_qna QnA nonaktif / tidak ada → jalur ketikan dengan validasi.
        $nonaktif = $this->buatQna('qna nonaktif', $this->katCuti, [], 2);
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => '', 'id_qna' => $nonaktif])->assertStatus(422);
        $d2 = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cuti tahunan', 'id_qna' => 999999]));
        $this->assertNotSame(1.0, (float) $d2['skor']);
    }

    public function testValidasiPanjangPesan(): void
    {
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => '   '])->assertStatus(422);
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => str_repeat('a', 301)])->assertStatus(422);
        $this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post(self::URL . 'widget/balas', ['pesan' => str_repeat('é', 150) . str_repeat('😀', 150)])->assertStatus(200);
        $this->assertSame(1, $this->db->table('log_chatbot')->countAllResults(), '422 tidak menulis log');
    }

    public function testKorpusKosongTanpaLog(): void
    {
        $this->bersihkanChatbot();
        $d = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cuti']));

        $this->assertSame(['balasan' => 'Database QnA masih kosong.', 'cocok' => false, 'skor' => 0, 'id_log' => null, 'bisa_feedback' => false, 'saran' => []], $d);
        $this->assertSame(0, $this->db->table('log_chatbot')->countAllResults());
    }

    public function testStatusIndukDiKorpus(): void
    {
        // Tag status 10 yang masih berelasi tidak menambah skor (F8-39, catatan DBV N3).
        $tagHapus = $this->buatTag('lembur', 10);
        $this->db->table('qna_has_tag')->insert(['qna_id' => $this->qSandi, 'qna_tag_id' => $tagHapus]);
        $d = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'lembur']));
        $this->assertFalse($d['cocok']);
        $this->assertSame(0.0, (float) $d['skor']);

        // QnA aktif di kategori Tidak Aktif tetap terjawab lewat ketikan; topiknya tidak tampil sebagai bubble.
        $d2 = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'syarat pensiun dini']));
        $this->assertTrue($d2['cocok']);
        $this->assertNotContains('Pensiun', array_column($this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori')), 'judul'));
    }

    public function testFeedbackTokenSahDanDitimpa(): void
    {
        $d = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cara mengajukan cuti tahunan']));
        $b = ['id_log' => $d['id_log'], 'token' => $d['token_feedback']];

        $this->assertSame(['id_log' => $d['id_log'], 'nilai' => 1], $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', $b + ['nilai' => 1])));
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', $b + ['nilai' => -1])->assertStatus(200);
        $this->assertSame('-1', (string) $this->db->table('log_chatbot')->get()->getRow('feedback'));
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', $b + ['nilai' => 0])->assertStatus(422);
    }

    public function testFeedbackDitolak(): void
    {
        $d           = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cara mengajukan cuti tahunan']));
        $tok         = (string) $d['token_feedback'];
        [$exp, $sig] = explode('.', $tok);

        $kasus = [
            'akun lain'     => [Role::PTT, $tok],
            'diubah'        => [Role::PEGAWAI, $exp . '.' . strrev($sig)],
            'exp diubah'    => [Role::PEGAWAI, rtrim(strtr(base64_encode((string) (time() + 999999)), '+/', '-_'), '=') . '.' . $sig],
            'tidak terurai' => [Role::PEGAWAI, 'bukan-token'],
            'kosong'        => [Role::PEGAWAI, ''],
        ];

        foreach ($kasus as $nama => [$role, $token]) {
            $r = $this->asRole($role)->post(self::URL . 'widget/feedback', ['id_log' => $d['id_log'], 'nilai' => 1, 'token' => $token]);
            $r->assertStatus(403);
            $this->assertSame(ChatbotService::PESAN_SESI, json_decode($r->getJSON(), true)['message'], $nama);
        }

        $this->assertNull($this->db->table('log_chatbot')->get()->getRow('feedback'), 'baris log tidak berubah');

        // Kedaluwarsa: token dibuat 24 jam + 1 detik yang lalu.
        $c    = config(Chatbot::class);
        $lama = (new TokenFeedback($c, static fn (): int => time() - $c->masaTokenFeedback - 1))->buat((int) $d['id_log'], $this->idAkun(Role::PEGAWAI));
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $d['id_log'], 'nilai' => 1, 'token' => $lama])->assertStatus(403);

        // Log tidak cocok dengan token sah → 422.
        $tidakCocok = $this->buatLog($this->qCuti, 0);
        $tokTC      = (new TokenFeedback($c))->buat($tidakCocok, $this->idAkun(Role::PEGAWAI));
        $r          = $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $tidakCocok, 'nilai' => 1, 'token' => $tokTC]);
        $r->assertStatus(422);
        $this->assertSame(ChatbotService::PESAN_TIDAK_DINILAI, json_decode($r->getJSON(), true)['message']);
    }

    public function testKunciTidakTersedia(): void
    {
        foreach (['', 'terlalu-pendek'] as $kunci) {
            $this->pasangConfigChatbot($kunci);
            $d = $this->data($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cara mengajukan cuti tahunan']));

            $this->assertTrue($d['cocok']);
            $this->assertFalse($d['bisa_feedback']);
            $this->assertArrayNotHasKey('token_feedback', $d);

            $tok = (new TokenFeedback($this->pasangConfigChatbot()))->buat((int) $d['id_log'], $this->idAkun(Role::PEGAWAI));
            $this->pasangConfigChatbot($kunci);
            $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $d['id_log'], 'nilai' => 1, 'token' => $tok])->assertStatus(403);
        }
    }

    public function testThrottlePerKelompok(): void
    {
        $this->pasangConfigChatbot(self::KUNCI_UJI, static function (Chatbot $c): void {
            $c->throttleBalas    = 2;
            $c->throttleFeedback = 1;
            $c->throttleBaca     = 3;
        });

        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cuti'])->assertStatus(200);
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cuti'])->assertStatus(200);
        $r = $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cuti']);
        $r->assertStatus(429);
        $this->assertSame(ChatbotService::PESAN_THROTTLE, json_decode($r->getJSON(), true)['message']);
        $this->assertSame(2, $this->db->table('log_chatbot')->countAllResults(), '429 tidak menulis log');

        // Kuota baca gabungan (kategori + pertanyaan + populer), tidak terpengaruh balas.
        $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori')->assertStatus(200);
        $this->asRole(Role::PEGAWAI)->get(self::URL . "widget/kategori/{$this->katCuti}/pertanyaan")->assertStatus(200);
        $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/populer')->assertStatus(200);
        $this->asRole(Role::PEGAWAI)->get(self::URL . 'widget/kategori')->assertStatus(429);

        // Feedback: kelompok sendiri; akun lain tidak terpengaruh.
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => 1, 'nilai' => 1, 'token' => 'x'])->assertStatus(403);
        $this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => 1, 'nilai' => 1, 'token' => 'x'])->assertStatus(429);
        $this->asRole(Role::PTT)->post(self::URL . 'widget/balas', ['pesan' => 'cuti'])->assertStatus(200);
    }

    /**
     * @return mixed
     */
    private function data(TestResponse $r)
    {
        $r->assertStatus($r->response()->getStatusCode() === 201 ? 201 : 200);

        return json_decode($r->getJSON(), true)['data'];
    }

    private function idAkun(int $role): int
    {
        return (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole($role))->get()->getRow('id_pengguna');
    }
}
