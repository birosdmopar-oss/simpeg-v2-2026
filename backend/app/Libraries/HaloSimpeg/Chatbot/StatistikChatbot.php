<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\ListQuery;
use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Chatbot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Statistik, riwayat, dan ekspor tag chatbot (role 1; PRD Fase 8 §7.10.5, F8-42, F8-47; `dev:libraries/hr/L_chatbot.php:216-252`,
 * `:502-567`). Hari dan filter tanggal = hari kalender WIB yang dikonversi ke rentang UTC atas `waktu_interaksi` (tanpa `DATE()`,
 * A8, indeks `idx_log_waktu`). Isi pesan pengguna dikirim sebagai teks polos (FE menampilkan ter-escape).
 */
final class StatistikChatbot
{
    private const TOP = 10;

    private readonly BaseConnection $db;

    private readonly Chatbot $config;

    /** @var (callable(): string)|null tanggal WIB hari ini `Y-m-d` (dapat disuntik test) */
    private $hariIni;

    public function __construct(?BaseConnection $db = null, ?Chatbot $config = null, ?callable $hariIni = null)
    {
        $this->db      = $db ?? db_connect();
        $this->config  = $config ?? config(Chatbot::class);
        $this->hariIni = $hariIni;
    }

    /**
     * @return array<string, mixed>
     */
    public function statistik(): array
    {
        $hari  = $this->hariIni !== null ? ($this->hariIni)() : (new DateTimeImmutable('now', new DateTimeZone(TanggalBisnis::ZONA)))->format('Y-m-d');
        $total = $this->db->table('log_chatbot')->countAllResults();
        $hariN = $this->db->table('log_chatbot')->where('waktu_interaksi >=', StempelUtc::awalHari($hari))->where('waktu_interaksi <', StempelUtc::awalHariBerikut($hari))->countAllResults();
        $rata  = $this->db->table('log_chatbot')->selectAvg('top_1_skor', 'r')->get()->getRow('r');
        $rataC = $this->db->table('log_chatbot')->selectAvg('top_1_skor', 'r')->where('status_match', 1)->get()->getRow('r');
        $puas  = $this->db->table('log_chatbot')->select('SUM(feedback = 1) AS up, SUM(feedback = -1) AS down', false)->where('status_match', 1)->get()->getRowArray() ?? [];
        $up    = (int) ($puas['up'] ?? 0);
        $down  = (int) ($puas['down'] ?? 0);
        $n     = $up + $down;

        $topPesan = $this->db->table('log_chatbot')->select('pesan_user, COUNT(*) AS jumlah')->groupBy('pesan_user')
            ->orderBy('jumlah', 'DESC')->orderBy('pesan_user', 'ASC')->limit(self::TOP)->get()->getResultArray();
        $topCocok = $this->db->table('log_chatbot')->select('top_1_id_qna, COUNT(*) AS jumlah')->where('status_match', 1)->groupBy('top_1_id_qna')
            ->orderBy('jumlah', 'DESC')->orderBy('top_1_id_qna', 'ASC')->limit(self::TOP)->get()->getResultArray();
        $topNeg = $this->db->table('log_chatbot')->select('top_1_id_qna, SUM(feedback = -1) AS jumlah', false)->where('status_match', 1)->groupBy('top_1_id_qna')
            ->having('SUM(feedback = -1) >', 0, false)->orderBy('jumlah', 'DESC')->orderBy('top_1_id_qna', 'ASC')->limit(self::TOP)->get()->getResultArray();

        $pertanyaan = $this->pertanyaan(array_merge(array_column($topCocok, 'top_1_id_qna'), array_column($topNeg, 'top_1_id_qna')));
        $qna        = static fn (array $r): array => ['id_qna' => (int) $r['top_1_id_qna'], 'pertanyaan' => $pertanyaan[(int) $r['top_1_id_qna']] ?? null, 'jumlah' => (int) $r['jumlah']];

        return [
            'kpi' => [
                'total_chat'      => $total,
                'chat_hari_ini'   => $hariN,
                'rata_skor'       => round((float) ($rata ?? 0), 4),
                'rata_skor_cocok' => round((float) ($rataC ?? 0), 4),
            ],
            'kepuasan' => [
                'membantu'       => $up,
                'tidak_membantu' => $down,
                'total'          => $n,
                'persen_puas'    => $n > 0 ? round($up / $n * 100, 1) : 0,
            ],
            'top_pesan'       => array_map(static fn (array $r): array => ['pesan_user' => (string) $r['pesan_user'], 'jumlah' => (int) $r['jumlah']], $topPesan),
            'top_qna_cocok'   => array_map($qna, $topCocok),
            'top_qna_negatif' => array_map($qna, $topNeg),
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function riwayat(array $query): array
    {
        $p       = ListQuery::from($query, ['dari', 'sampai', 'cocok', 'feedback', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage($this->config->perHalamanBawaan, $this->config->perHalamanMaks);
        $dari    = $p->string('dari');
        $sampai  = $p->string('sampai');
        $cocok   = $p->string('cocok');
        $fb      = $p->string('feedback');

        foreach (['dari' => $dari, 'sampai' => $sampai] as $k => $v) {
            if ($v !== '' && ! TanggalBisnis::valid($v)) {
                throw ValidationException::forField($k, 'Tanggal tidak valid (format YYYY-MM-DD).');
            }
        }

        if ($cocok !== '' && ! in_array($cocok, ['0', '1'], true)) {
            throw ValidationException::forField('cocok', 'Nilai harus 0 atau 1.');
        }

        if ($fb !== '' && ! in_array($fb, ['1', '-1', '0'], true)) {
            throw ValidationException::forField('feedback', 'Nilai harus 1, -1, atau 0.');
        }

        $saring = static function (BaseBuilder $b) use ($dari, $sampai, $cocok, $fb): void {
            if ($dari !== '') {
                $b->where('waktu_interaksi >=', StempelUtc::awalHari($dari));
            }

            if ($sampai !== '') {
                $b->where('waktu_interaksi <', StempelUtc::awalHariBerikut($sampai));
            }

            if ($cocok !== '') {
                $b->where('status_match', (int) $cocok);
            }

            if ($fb === '0') {
                $b->where('feedback', null);
            } elseif ($fb !== '') {
                $b->where('feedback', (int) $fb);
            }
        };

        $hitung = $this->db->table('log_chatbot');
        $saring($hitung);
        $total = (int) $hitung->countAllResults();

        $b = $this->db->table('log_chatbot')->select('id_log, waktu_interaksi, pesan_user, top_1_id_qna, top_1_skor, status_match, feedback');
        $saring($b);
        $rows = $b->orderBy('waktu_interaksi', 'DESC')->orderBy('id_log', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        $pertanyaan = $this->pertanyaan(array_column($rows, 'top_1_id_qna'));

        return ['items' => array_map(static fn (array $r): array => [
            'id_log'             => (int) $r['id_log'],
            'waktu'              => StempelUtc::keIsoWib((string) $r['waktu_interaksi']),
            'pesan_user'         => (string) $r['pesan_user'],
            'pertanyaan_teratas' => $pertanyaan[(int) $r['top_1_id_qna']] ?? null,
            'skor'               => round((float) $r['top_1_skor'], 4),
            'cocok'              => (int) $r['status_match'] === 1,
            'feedback'           => $r['feedback'] === null ? null : (int) $r['feedback'],
        ], $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Ekspor daftar tag status 1/2 (F8-47): kolom No, Tag, Status; nama berkas waktu WIB.
     *
     * @return array{nama: string, isi: string}
     */
    public function eksporTag(mixed $format): array
    {
        if ($format !== 'xlsx') {
            throw ValidationException::forField('format', 'Format ekspor hanya xlsx.');
        }

        $rows  = $this->db->table('qna_tag')->select('judul_tag, status_qna_tag_fk')->whereIn('status_qna_tag_fk', [1, 2])->orderBy('id_qna_tag', 'DESC')->get()->getResultArray();
        $baris = [];

        foreach ($rows as $i => $r) {
            $baris[] = [(string) ($i + 1), (string) $r['judul_tag'], (int) $r['status_qna_tag_fk'] === 1 ? 'Aktif' : 'Tidak Aktif'];
        }

        $waktu = (new DateTimeImmutable('now', new DateTimeZone(TanggalBisnis::ZONA)))->format('Ymd_His');

        return ['nama' => "Export_Tag_QnA_{$waktu}.xlsx", 'isi' => EksporTabel::xlsx(['No', 'Tag', 'Status'], $baris, 'Tag QnA')];
    }

    /**
     * Pertanyaan QnA yang masih ada (status apa pun); yang tidak ada → tidak ada di peta (FE menampilkan `#id`).
     *
     * @param list<mixed> $ids
     *
     * @return array<int, string>
     */
    private function pertanyaan(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->db->table('qna')->select('id_qna, pertanyaan')->whereIn('id_qna', $ids)->get()->getResultArray() as $r) {
            $out[(int) $r['id_qna']] = (string) $r['pertanyaan'];
        }

        return $out;
    }
}
