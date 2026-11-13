<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use App\Exceptions\ForbiddenException;
use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Models\HaloSimpeg\Chatbot\LogChatbotModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Throttle\ThrottlerInterface;
use Config\Chatbot;
use Config\Services;

/**
 * Widget Vanya untuk pegawai (PRD Fase 8 §6.5 `widget/*`, §7.10.2, F8-35/36/44/48; padanan `dev:libraries/hr/L_chatbot.php:573-812`).
 * Role (2/6/7) ditegakkan filter route. Log anonim lewat {@see LogChatbotModel} (tanpa audit, BP8-15); isi pesan tidak pernah
 * ditulis ke log aplikasi.
 */
final class ChatbotService
{
    public const BALASAN_FALLBACK = 'Maaf, Vanya belum memahami pertanyaan Anda. Mungkin salah satu pertanyaan berikut yang Anda maksud?';

    public const BALASAN_KOSONG = 'Database QnA masih kosong.';

    public const PESAN_THROTTLE = 'Terlalu banyak permintaan ke Vanya. Coba lagi dalam satu menit.';

    public const PESAN_SESI = 'Sesi penilaian tidak ditemukan. Silakan ajukan pertanyaan lagi.';

    public const PESAN_TIDAK_DINILAI = 'Jawaban ini tidak dapat dinilai.';

    private readonly BaseConnection $db;

    private readonly Chatbot $config;

    private readonly TokenFeedback $token;

    public function __construct(?BaseConnection $db = null, ?Chatbot $config = null, ?TokenFeedback $token = null, private readonly ?ThrottlerInterface $throttler = null)
    {
        $this->db     = $db ?? db_connect();
        $this->config = $config ?? config(Chatbot::class);
        $this->token  = $token ?? new TokenFeedback($this->config);
    }

    /**
     * Bubble topik: kategori status 1, `tampil_bubble = 1`, punya ≥ 1 QnA status 1; urut id (`dev:LC:580-593`).
     *
     * @return list<array{id: int, judul: string}>
     */
    public function kategori(AuthContext $auth): array
    {
        $this->throttle($auth, 'baca', $this->config->throttleBaca);

        $rows = $this->db->table('qna_kategori k')->select('k.id_qna_kategori, k.judul_kategori')
            ->where('k.status_qna_kategori_fk', 1)->where('k.tampil_bubble', 1)
            ->where('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('qna') . ' q WHERE q.id_qna_kategori_fk = k.id_qna_kategori AND q.status_qna_fk = 1)', null, false)
            ->orderBy('k.id_qna_kategori', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id_qna_kategori'], 'judul' => (string) $r['judul_kategori']], $rows);
    }

    /**
     * Bubble pertanyaan satu kategori: unggulan dulu, lalu paling sering cocok, lalu id (`dev:LC:597-616`).
     *
     * @return list<array{id_qna: int, pertanyaan: string}>
     */
    public function pertanyaan(AuthContext $auth, string $idKategori): array
    {
        $this->throttle($auth, 'baca', $this->config->throttleBaca);

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $idKategori) !== 1) {
            return [];
        }

        $log  = $this->db->prefixTable('log_chatbot');
        $rows = $this->db->table('qna q')->select('q.id_qna, q.pertanyaan')
            ->join("(SELECT top_1_id_qna, COUNT(*) AS jml FROM {$log} WHERE status_match = 1 GROUP BY top_1_id_qna) l", 'l.top_1_id_qna = q.id_qna', 'left', false)
            ->where('q.status_qna_fk', 1)->where('q.id_qna_kategori_fk', (int) $idKategori)
            ->orderBy('q.is_fallback_option', 'DESC')->orderBy('COALESCE(l.jml, 0)', 'DESC', false)->orderBy('q.id_qna', 'ASC')
            ->limit($this->config->maksBubblePertanyaan)->get()->getResultArray();

        return self::bubble($rows);
    }

    /**
     * @return list<array{id_qna: int, pertanyaan: string}>
     */
    public function populer(AuthContext $auth): array
    {
        $this->throttle($auth, 'baca', $this->config->throttleBaca);

        return $this->daftarPopuler();
    }

    /**
     * Jawab satu pesan (jalur bubble atau ketikan) dan tulis tepat satu baris log (kecuali korpus kosong).
     *
     * @param array<string, mixed> $p
     *
     * @return array{balasan: string, cocok: bool, skor: float, id_log: int|null, bisa_feedback: bool, token_feedback?: string, saran: list<array{id_qna: int, pertanyaan: string}>}
     */
    public function balas(AuthContext $auth, array $p): array
    {
        $this->throttle($auth, 'balas', $this->config->throttleBalas);

        $korpus = $this->korpus();
        $bubble = $this->qnaBubble($p['id_qna'] ?? null, $korpus);
        $pesan  = '';

        if ($bubble === null) {
            $pesan = is_string($p['pesan'] ?? null) ? trim($p['pesan']) : '';
            $n     = mb_strlen($pesan);

            if ($n < 1 || $n > $this->config->maksPesan) {
                throw ValidationException::forField('pesan', 'Pesan wajib diisi (maks ' . $this->config->maksPesan . ' karakter).');
            }
        }

        if ($korpus === []) {
            return ['balasan' => self::BALASAN_KOSONG, 'cocok' => false, 'skor' => 0.0, 'id_log' => null, 'bisa_feedback' => false, 'saran' => []];
        }

        if ($bubble !== null) {
            $pesanLog = $bubble['pertanyaan'];
            $cocok    = true;
            $teratas  = ['id_qna' => $bubble['id_qna'], 'skor' => 1.0];
            $kandidat = [['id_qna' => $bubble['id_qna'], 'skor' => 1.0, 'skor_akhir' => 1.0]];
        } else {
            $pesanLog = $pesan;
            $hasil    = (new MesinJawab($this->config))->rangking($pesanLog, array_map(
                static fn (array $q): array => ['id_qna' => $q['id_qna'], 'teks' => $q['teks']],
                $korpus,
            ), $this->rekapFeedback());

            $cocok    = $hasil !== null && $hasil['cocok'];
            $teratas  = ['id_qna' => $hasil['teratas']['id_qna'] ?? 0, 'skor' => $hasil['teratas']['skor'] ?? 0.0];
            $kandidat = $hasil['kandidat'] ?? [];
        }

        $skor  = round($teratas['skor'], 4);
        $idLog = (int) (new LogChatbotModel($this->db))->insert([
            'pesan_user'      => $pesanLog,
            'top_1_id_qna'    => $teratas['id_qna'],
            'top_1_skor'      => min(1.0, $skor),
            'top_3_kandidat'  => json_encode($kandidat, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'status_match'    => $cocok ? 1 : 0,
            'waktu_interaksi' => gmdate('Y-m-d H:i:s'),
        ], true);

        $jawaban = '';

        foreach ($korpus as $q) {
            if ($q['id_qna'] === $teratas['id_qna']) {
                $jawaban = $q['jawaban'];
            }
        }

        $hasilBalas = [
            'balasan'       => $cocok ? $jawaban : self::BALASAN_FALLBACK,
            'cocok'         => $cocok,
            'skor'          => $skor,
            'id_log'        => $idLog,
            'bisa_feedback' => false,
            'saran'         => $cocok ? [] : $this->daftarPopuler(),
        ];

        if ($cocok) {
            $token = $this->token->buat($idLog, (int) $auth->idPengguna());

            if ($token === null) {
                log_message('warning', '[chatbot] kunci token feedback tidak tersedia (chatbot.kunciTokenFeedback < 32 byte); penilaian dimatikan.');
            } else {
                $hasilBalas['bisa_feedback']  = true;
                $hasilBalas['token_feedback'] = $token;
            }
        }

        return $hasilBalas;
    }

    /**
     * Simpan 👍/👎 untuk log cocok milik akun ini (F8-35; `dev:LC:774-812`). Nilai boleh ditimpa selama token berlaku.
     *
     * @param array<string, mixed> $p
     *
     * @return array{id_log: int, nilai: int}
     */
    public function feedback(AuthContext $auth, array $p): array
    {
        $this->throttle($auth, 'feedback', $this->config->throttleFeedback);

        $idLog = (string) (is_scalar($p['id_log'] ?? null) ? $p['id_log'] : '');
        $nilai = $p['nilai'] ?? null;

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $idLog) !== 1) {
            throw ValidationException::forField('id_log', 'Data penilaian tidak valid.');
        }

        if (! in_array($nilai, [1, -1, '1', '-1'], true)) {
            throw ValidationException::forField('nilai', 'Data penilaian tidak valid.');
        }

        if (! $this->token->sah($p['token'] ?? null, (int) $idLog, (int) $auth->idPengguna())) {
            throw new ForbiddenException(self::PESAN_SESI);
        }

        $model = new LogChatbotModel($this->db);
        $log   = $model->where('id_log', (int) $idLog)->where('status_match', 1)->first();

        if ($log === null) {
            throw new ValidationException(self::PESAN_TIDAK_DINILAI);
        }

        $model->update((int) $idLog, ['feedback' => (int) $nilai]);

        return ['id_log' => (int) $idLog, 'nilai' => (int) $nilai];
    }

    /**
     * Korpus = QnA status 1 dengan nama tag status 1 (F8-39, catatan DBV N3), urut id menaik. Teks = pertanyaan + spasi + tag.
     *
     * @return list<array{id_qna: int, pertanyaan: string, jawaban: string, teks: string}>
     */
    private function korpus(): array
    {
        $qna = $this->db->table('qna')->select('id_qna, pertanyaan, jawaban')->where('status_qna_fk', 1)->orderBy('id_qna', 'ASC')->get()->getResultArray();

        if ($qna === []) {
            return [];
        }

        $tag = [];

        foreach ($this->db->table('qna_has_tag h')->select('h.qna_id, t.judul_tag')
            ->join('qna_tag t', 't.id_qna_tag = h.qna_tag_id')->where('t.status_qna_tag_fk', 1)
            ->orderBy('h.qna_id', 'ASC')->orderBy('h.qna_tag_id', 'ASC')->get()->getResultArray() as $r) {
            $tag[(int) $r['qna_id']][] = (string) $r['judul_tag'];
        }

        return array_map(static fn (array $q): array => [
            'id_qna'     => (int) $q['id_qna'],
            'pertanyaan' => (string) $q['pertanyaan'],
            'jawaban'    => (string) $q['jawaban'],
            'teks'       => $q['pertanyaan'] . ' ' . implode(' ', $tag[(int) $q['id_qna']] ?? []),
        ], $qna);
    }

    /**
     * @param list<array{id_qna: int, pertanyaan: string, jawaban: string, teks: string}> $korpus
     *
     * @return array{id_qna: int, pertanyaan: string}|null QnA korpus yang dipilih dari bubble, atau null (jalur ketikan)
     */
    private function qnaBubble(mixed $idQna, array $korpus): ?array
    {
        $id = is_int($idQna) ? (string) $idQna : (is_string($idQna) ? $idQna : '');

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $id) !== 1) {
            return null;
        }

        foreach ($korpus as $q) {
            if ($q['id_qna'] === (int) $id) {
                return ['id_qna' => $q['id_qna'], 'pertanyaan' => $q['pertanyaan']];
            }
        }

        return null;
    }

    /**
     * Rekap 👍/👎 per QnA teratas dari log cocok (`dev:LC:635-646`).
     *
     * @return array<int, array{up: int, down: int}>
     */
    private function rekapFeedback(): array
    {
        $rekap = [];

        foreach ($this->db->table('log_chatbot')->select('top_1_id_qna, SUM(feedback = 1) AS up, SUM(feedback = -1) AS down', false)
            ->where('status_match', 1)->where('feedback IS NOT NULL', null, false)->groupBy('top_1_id_qna')->get()->getResultArray() as $r) {
            $rekap[(int) $r['top_1_id_qna']] = ['up' => (int) $r['up'], 'down' => (int) $r['down']];
        }

        return $rekap;
    }

    /**
     * @return list<array{id_qna: int, pertanyaan: string}>
     */
    private function daftarPopuler(): array
    {
        $rows = $this->db->table('qna')->select('id_qna, pertanyaan')->where('status_qna_fk', 1)->where('is_fallback_option', 1)
            ->orderBy('id_qna', 'DESC')->limit($this->config->jumlahPopuler)->get()->getResultArray();

        return self::bubble($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id_qna: int, pertanyaan: string}>
     */
    private static function bubble(array $rows): array
    {
        return array_map(static fn (array $r): array => ['id_qna' => (int) $r['id_qna'], 'pertanyaan' => (string) $r['pertanyaan']], $rows);
    }

    private function throttle(AuthContext $auth, string $kelompok, int $batas): void
    {
        $key = 'chatbot_' . $kelompok . '_' . ($auth->idPengguna() ?? 0);

        if (! ($this->throttler ?? Services::throttler())->check($key, $batas, MINUTE, 1)) {
            throw new TooManyRequestsException(self::PESAN_THROTTLE);
        }
    }
}
