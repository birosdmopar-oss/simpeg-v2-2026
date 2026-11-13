<?php

declare(strict_types=1);

namespace Tests\Support\HaloSimpeg\Chatbot;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Chatbot;
use Config\Services;

/**
 * Data SINTETIS chatbot untuk test MAKE-094 (bukan isi QnA dev, F8-46): kategori, tag, QnA, relasi, log; config chatbot dengan
 * kunci token uji; throttler MockCache. Tulis langsung lewat query builder (tanpa audit) agar test admin bisa memeriksa
 * `audit_logs` hanya dari aksi yang diuji.
 */
trait ChatbotFixtureTrait
{
    /** Kunci uji (bukan rahasia), ≥ 32 byte. */
    protected const KUNCI_UJI = 'kunci-uji-token-feedback-vanya-0123456789';

    protected function pasangConfigChatbot(string $kunci = self::KUNCI_UJI, ?callable $ubah = null): Chatbot
    {
        $c                     = new Chatbot();
        $c->kunciTokenFeedback = $kunci;

        if ($ubah !== null) {
            $ubah($c);
        }

        Factories::injectMock('config', Chatbot::class, $c);

        return $c;
    }

    protected function pasangThrottlerChatbot(): Throttler
    {
        $t = new Throttler(new MockCache());
        $t->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $t);

        return $t;
    }

    protected function buatKategori(string $judul, int $status = 1, int $bubble = 0): int
    {
        $this->db->table('qna_kategori')->insert(['judul_kategori' => $judul, 'status_qna_kategori_fk' => $status, 'tampil_bubble' => $bubble]);

        return (int) $this->db->insertID();
    }

    protected function buatTag(string $judul, int $status = 1): int
    {
        $this->db->table('qna_tag')->insert(['judul_tag' => $judul, 'status_qna_tag_fk' => $status]);

        return (int) $this->db->insertID();
    }

    /**
     * @param list<int> $tag
     */
    protected function buatQna(string $pertanyaan, ?int $kategori, array $tag = [], int $status = 1, int $unggulan = 0, string $jawaban = ''): int
    {
        $this->db->table('qna')->insert([
            'pertanyaan'         => $pertanyaan,
            'jawaban'            => $jawaban !== '' ? $jawaban : '<p>Jawaban ' . $pertanyaan . '</p>',
            'id_qna_kategori_fk' => $kategori,
            'status_qna_fk'      => $status,
            'is_fallback_option' => $unggulan,
        ]);
        $id = (int) $this->db->insertID();

        foreach ($tag as $t) {
            $this->db->table('qna_has_tag')->insert(['qna_id' => $id, 'qna_tag_id' => $t]);
        }

        return $id;
    }

    protected function buatLog(int $qna, int $cocok = 1, ?int $feedback = null, string $waktu = '2026-11-13 01:00:00', string $pesan = 'pesan sintetis', float $skor = 0.5): int
    {
        $this->db->table('log_chatbot')->insert([
            'pesan_user'      => $pesan,
            'top_1_id_qna'    => $qna,
            'top_1_skor'      => $skor,
            'top_3_kandidat'  => '[]',
            'status_match'    => $cocok,
            'feedback'        => $feedback,
            'waktu_interaksi' => $waktu,
        ]);

        return (int) $this->db->insertID();
    }

    protected function bersihkanChatbot(): void
    {
        foreach (['log_chatbot', 'qna_has_tag', 'qna', 'qna_tag', 'qna_kategori'] as $t) {
            $this->db->table($t)->where('1 = 1', null, false)->delete();
        }
    }
}
