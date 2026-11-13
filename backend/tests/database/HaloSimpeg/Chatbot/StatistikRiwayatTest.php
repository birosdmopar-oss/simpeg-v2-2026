<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\HaloSimpeg\Chatbot\StatistikChatbot;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;

/**
 * Statistik dan riwayat chatbot (MAKE-094; PRD Fase 8 §7.10.5, F8-42, AC-E10.17, AC-E10.18). Hari = WIB → rentang UTC.
 *
 * @internal
 */
final class StatistikRiwayatTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use ChatbotFixtureTrait;
    use FeatureTestTrait;

    protected $seed = AuthSeeder::class;

    private int $q1;

    private int $q2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangConfigChatbot();
        $this->pasangThrottlerChatbot();
        $kat      = $this->buatKategori('K');
        $this->q1 = $this->buatQna('Pertanyaan satu', $kat);
        $this->q2 = $this->buatQna('Pertanyaan dua', $kat);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    public function testStatistikSesuaiFixture(): void
    {
        // 13-11-2026 WIB = 2026-11-12 17:00:00 .. 2026-11-13 16:59:59 UTC.
        $this->buatLog($this->q1, 1, 1, '2026-11-12 17:00:00', 'cara cuti', 0.8);   // 00:00 WIB hari ini
        $this->buatLog($this->q1, 1, -1, '2026-11-13 16:59:00', 'cara cuti', 0.6);  // 23:59 WIB hari ini
        $this->buatLog($this->q2, 1, -1, '2026-11-12 16:59:00', '<b>halo</b>', 0.5); // 23:59 WIB kemarin
        $this->buatLog($this->q2, 1, -1, '2026-11-10 01:00:00', 'x', 0.3);
        $this->buatLog(999999, 1, null, '2026-11-10 01:00:00', 'x', 0.4);         // QnA sudah tidak ada
        $this->buatLog($this->q1, 0, null, '2026-11-10 01:00:00', 'x', 0.1);

        $s = (new StatistikChatbot($this->db, null, static fn (): string => '2026-11-13'))->statistik();

        $this->assertSame(['total_chat' => 6, 'chat_hari_ini' => 2, 'rata_skor' => 0.45, 'rata_skor_cocok' => 0.52], $s['kpi']);
        $this->assertSame(['membantu' => 1, 'tidak_membantu' => 3, 'total' => 4, 'persen_puas' => 25.0], $s['kepuasan']);
        $this->assertSame([['pesan_user' => 'x', 'jumlah' => 3], ['pesan_user' => 'cara cuti', 'jumlah' => 2], ['pesan_user' => '<b>halo</b>', 'jumlah' => 1]], $s['top_pesan']);
        $this->assertSame([
            ['id_qna' => $this->q1, 'pertanyaan' => 'Pertanyaan satu', 'jumlah' => 2],
            ['id_qna' => $this->q2, 'pertanyaan' => 'Pertanyaan dua', 'jumlah' => 2],
            ['id_qna' => 999999, 'pertanyaan' => null, 'jumlah' => 1],
        ], $s['top_qna_cocok']);
        $this->assertSame([
            ['id_qna' => $this->q2, 'pertanyaan' => 'Pertanyaan dua', 'jumlah' => 2],
            ['id_qna' => $this->q1, 'pertanyaan' => 'Pertanyaan satu', 'jumlah' => 1],
        ], $s['top_qna_negatif'], 'hanya 👎 > 0, urut 👎 menurun');
    }

    public function testStatistikKosong(): void
    {
        $s = $this->data('statistik');

        $this->assertSame(['total_chat' => 0, 'chat_hari_ini' => 0, 'rata_skor' => 0, 'rata_skor_cocok' => 0], $s['kpi']);
        $this->assertSame(['membantu' => 0, 'tidak_membantu' => 0, 'total' => 0, 'persen_puas' => 0], $s['kepuasan']);
        $this->assertSame([[], [], []], [$s['top_pesan'], $s['top_qna_cocok'], $s['top_qna_negatif']]);
    }

    public function testRiwayatFilterHariWibDanPaginasi(): void
    {
        $a = $this->buatLog($this->q1, 1, 1, '2026-11-12 17:00:00', 'awal hari');       // 13-11 00:00 WIB
        $b = $this->buatLog($this->q1, 0, null, '2026-11-13 16:59:00', '<i>akhir</i>'); // 13-11 23:59 WIB
        $this->buatLog($this->q2, 1, -1, '2026-11-12 16:30:00', 'kemarin 23:30');       // 12-11 23:30 WIB
        $this->buatLog(999999, 0, null, '2026-11-13 17:00:00', 'besok');                 // 14-11 00:00 WIB

        $d = $this->data('riwayat?dari=2026-11-13&sampai=2026-11-13');
        $this->assertSame([2, [$b, $a]], [$d['total'], array_column($d['items'], 'id_log')]);
        $this->assertSame([
            'id_log' => $b, 'waktu' => '2026-11-13T23:59:00+07:00', 'pesan_user' => '<i>akhir</i>', 'pertanyaan_teratas' => 'Pertanyaan satu',
            'skor'   => 0.5, 'cocok' => false, 'feedback' => null,
        ], $d['items'][0]);

        $this->assertSame(['kemarin 23:30'], array_column($this->data('riwayat?sampai=2026-11-12')['items'], 'pesan_user'));
        $this->assertSame(['besok'], array_column($this->data('riwayat?dari=2026-11-14')['items'], 'pesan_user'));
        $this->assertNull($this->data('riwayat?dari=2026-11-14')['items'][0]['pertanyaan_teratas']);
        $this->assertSame([$a], array_column($this->data('riwayat?feedback=1')['items'], 'id_log'));
        $this->assertSame(2, $this->data('riwayat?feedback=0')['total']);
        $this->assertSame(2, $this->data('riwayat?cocok=1')['total']);

        $hal = $this->data('riwayat?per_page=1&page=2');
        $this->assertSame([4, 2, 1, 1], [$hal['total'], $hal['page'], $hal['per_page'], count($hal['items'])]);

        foreach (['riwayat?dari=13-11-2026', 'riwayat?cocok=2', 'riwayat?feedback=5', 'riwayat?dari[]=x'] as $salah) {
            $this->asRole(Role::SUPER_ADMIN)->get('api/v1/halo-simpeg/chatbot/' . $salah)->assertStatus(422);
        }
    }

    public function testTanggalTidakValid(): void
    {
        $this->expectException(ValidationException::class);
        (new StatistikChatbot($this->db))->riwayat(['sampai' => '2026-02-30']);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $path): array
    {
        $r = $this->asRole(Role::SUPER_ADMIN)->get('api/v1/halo-simpeg/chatbot/' . $path);
        $r->assertStatus(200);

        return json_decode($r->getJSON(), true)['data'];
    }
}
