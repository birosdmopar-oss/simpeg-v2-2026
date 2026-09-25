<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\MasterData\FaqRateModel;
use App\Models\MasterData\MasterModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;

/**
 * G-10 — FAQ untuk pegawai (DBV-002): baca (UL_ALL, sudah login) dan rating artikel (UL_PEGAWAI: role 2, 6, 7).
 * Kelola konten (role 1) memakai engine master generik (`faq-topic`, `faq-sub-topic`, `faq-article`).
 *
 * Aturan tampil (keputusan U3): hanya entri yang SELURUH rantainya status 1 (topik, sub topik, artikel). Status anak
 * tidak ditulis ulang saat induk dinonaktifkan/dihapus, sehingga memulihkan induk otomatis memunculkan anaknya lagi.
 * Tidak di-cache: perubahan admin langsung terlihat (MTC-014).
 *
 * Pencarian mengikuti legacy (L_faq.php:992): MATCH(title, content_stripped) AGAINST(? IN NATURAL LANGUAGE MODE)
 * dengan parameter terikat. Token < 3 huruf tidak terindeks FULLTEXT InnoDB, jadi kata kunci pendek (atau pencarian
 * FULLTEXT tanpa hasil) memakai fallback `title LIKE %q%` (wildcard di-escape).
 */
class FaqService
{
    /**
     * Role yang boleh memberi rating = UL_PEGAWAI legacy (keputusan U2): Pegawai, PTT, PPPK.
     */
    public const RATER_ROLES = [Role::PEGAWAI, Role::PTT, Role::PPPK];

    public const SEARCH_MAX_LENGTH   = 100;
    public const SEARCH_LIMIT        = 50;
    public const FULLTEXT_MIN_LENGTH = 3;
    public const SNIPPET_LENGTH      = 200;
    public const RELATED_LIMIT       = 5;
    public const REASON_MAX_BYTES    = 255;

    private BaseConnection $db;

    private ?FaqRateModel $rates = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * GET faq[?search=]: kata kunci kosong → pohon topik; selain itu → hasil pencarian.
     *
     * @return array<string, mixed>
     */
    public function browse(string $search = ''): array
    {
        $search = trim($search);

        if ($search === '') {
            return $this->tree();
        }

        // Byte non-UTF-8 (mis. ?search=%C3) lolos ke query (MySQL hanya memberi warning) lalu gagal saat `search`
        // dipantulkan ke respons JSON (500). Tolak lebih dulu sebagai input tidak valid.
        if (! mb_check_encoding($search, 'UTF-8')) {
            throw ValidationException::forField('search', 'Kata kunci pencarian tidak valid.');
        }

        if (mb_strlen($search) > self::SEARCH_MAX_LENGTH) {
            throw ValidationException::forField('search', 'Kata kunci pencarian maksimal ' . self::SEARCH_MAX_LENGTH . ' karakter.');
        }

        return $this->search($search);
    }

    /**
     * Pohon topik → sub topik → judul artikel aktif. Topik/sub topik tanpa artikel aktif tetap tampil (kosong).
     *
     * @return array{topics: list<array{id: int, nama: string, sub_topics: list<array{id: int, nama: string, articles: list<array{id: int, title: string}>}>}>}
     */
    public function tree(): array
    {
        /** @var list<array<string, mixed>> $topics */
        $topics = $this->activeOrdered('faq_topic', ['id_faq_topic', 'faq_topic'], 'faq_topic')->get()->getResultArray();

        /** @var list<array<string, mixed>> $subTopics */
        $subTopics = $this->activeOrdered('faq_sub_topic', ['id_faq_sub_topic', 'id_faq_topic', 'faq_sub_topic'], 'faq_sub_topic')->get()->getResultArray();

        /** @var list<array<string, mixed>> $articles */
        $articles = $this->activeOrdered('faq_article', ['id_faq_article', 'id_faq_sub_topic', 'title'], 'title')->get()->getResultArray();

        $articlesBySub = [];

        foreach ($articles as $article) {
            $articlesBySub[(int) $article['id_faq_sub_topic']][] = [
                'id'    => (int) $article['id_faq_article'],
                'title' => (string) $article['title'],
            ];
        }

        $subsByTopic = [];

        foreach ($subTopics as $sub) {
            $subsByTopic[(int) $sub['id_faq_topic']][] = [
                'id'       => (int) $sub['id_faq_sub_topic'],
                'nama'     => (string) $sub['faq_sub_topic'],
                'articles' => $articlesBySub[(int) $sub['id_faq_sub_topic']] ?? [],
            ];
        }

        return [
            'topics' => array_map(static fn (array $topic): array => [
                'id'         => (int) $topic['id_faq_topic'],
                'nama'       => (string) $topic['faq_topic'],
                'sub_topics' => $subsByTopic[(int) $topic['id_faq_topic']] ?? [],
            ], $topics),
        ];
    }

    /**
     * Maks SEARCH_LIMIT artikel yang rantainya aktif. Snippet = kalimat pertama content_stripped (maks 200 karakter).
     *
     * @return array{results: list<array{id: int, title: string, topic: array{id: int, nama: string}, sub_topic: array{id: int, nama: string}, snippet: string}>, search: string}
     */
    public function search(string $search): array
    {
        $rows = mb_strlen($search) >= self::FULLTEXT_MIN_LENGTH ? $this->fulltextSearch($search) : [];

        if ($rows === []) {
            $rows = $this->titleSearch($search);
        }

        return [
            'results' => array_map(static fn (array $row): array => [
                'id'        => (int) $row['id_faq_article'],
                'title'     => (string) $row['title'],
                'topic'     => ['id' => (int) $row['id_faq_topic'], 'nama' => (string) $row['faq_topic']],
                'sub_topic' => ['id' => (int) $row['id_faq_sub_topic'], 'nama' => (string) $row['faq_sub_topic']],
                'snippet'   => self::snippet((string) $row['content_head']),
            ], $rows),
            'search' => $search,
        ];
    }

    /**
     * Detail artikel yang tampil + artikel terkait (≤5 artikel aktif lain di sub topik sama, id DESC seperti legacy
     * L_faq.php:1034) + status rating aktor.
     *
     * @return array<string, mixed>
     */
    public function article(string $id, ?string $nip, ?int $role): array
    {
        $article = $this->visibleArticle($id);
        $rating  = $nip !== null && $nip !== '' ? $this->rates()->findRating((int) $article['id_faq_article'], $nip) : null;

        /** @var list<array<string, mixed>> $related */
        $related = $this->db->table('faq_article')
            ->select(['id_faq_article', 'title'])
            ->where('status', MasterModel::STATUS_ACTIVE)
            ->where('id_faq_sub_topic', (int) $article['id_faq_sub_topic'])
            ->where('id_faq_article !=', (int) $article['id_faq_article'])
            ->orderBy('id_faq_article', 'DESC')
            ->limit(self::RELATED_LIMIT)
            ->get()
            ->getResultArray();

        return [
            'id'        => (int) $article['id_faq_article'],
            'title'     => (string) $article['title'],
            'content'   => (string) $article['content'],
            'topic'     => ['id' => (int) $article['id_faq_topic'], 'nama' => (string) $article['faq_topic']],
            'sub_topic' => ['id' => (int) $article['id_faq_sub_topic'], 'nama' => (string) $article['faq_sub_topic']],
            // Baris hasil impor legacy yang belum pernah diubah punya updated_at NULL: pakai waktu dibuat.
            'updated_at' => $article['updated_at'] ?? $article['created_at'],
            'related'    => array_map(static fn (array $r): array => ['id' => (int) $r['id_faq_article'], 'title' => (string) $r['title']], $related),
            'rating'     => [
                'can_rate' => $rating === null && $this->canRate($role),
                'rated'    => $rating !== null,
                'rate'     => $rating === null ? null : (int) $rating['rate'],
            ],
        ];
    }

    /**
     * Simpan penilaian pegawai untuk artikel yang tampil. Satu kali per artikel per pegawai, tidak bisa diubah.
     * rate 1 (Membantu) → reason NULL; rate 2 (Kurang Membantu) → reason wajib (trim, maks 255 byte).
     *
     * @return array{rated: true, rate: int}
     */
    public function rate(string $id, ?string $nip, ?int $role, int $rate, mixed $reason): array
    {
        // Lapis kedua; lapis utama filter role di routing.
        if ($nip === null || $nip === '' || ! $this->canRate($role)) {
            throw new ForbiddenException();
        }

        $articleId = (int) $this->visibleArticle($id)['id_faq_article'];

        if ($this->rates()->findRating($articleId, $nip) !== null) {
            throw self::alreadyRated();
        }

        $reason = $rate === FaqRateModel::RATE_NOT_HELPFUL ? $this->normalizeReason($reason) : null;

        try {
            $this->rates()->record([
                'id_faq_article' => $articleId,
                'nip'            => $nip,
                'rate'           => $rate,
                'reason'         => $reason,
                'created_at'     => Time::now()->toDateTimeString(),
                'created_by'     => $this->actorId($nip),
            ]);
        } catch (DatabaseException $e) {
            // Balapan dua permintaan: PK (id_faq_article, nip) menolak yang kedua.
            if ($e->getCode() === 1062) {
                throw self::alreadyRated();
            }

            throw $e;
        }

        return ['rated' => true, 'rate' => $rate];
    }

    public function canRate(?int $role): bool
    {
        return $role !== null && in_array($role, self::RATER_ROLES, true);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Artikel yang rantainya (artikel, sub topik, topik) status 1. Id non-kanonik ('06', '1e0') atau tersembunyi → 404.
     *
     * @return array<string, mixed>
     */
    private function visibleArticle(string $id): array
    {
        if (preg_match('/^[1-9][0-9]{0,9}\z/', $id) !== 1) {
            throw new NotFoundException('Artikel FAQ tidak ditemukan.');
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->chainBuilder()
            ->select('a.id_faq_article, a.title, a.content, a.created_at, a.updated_at, s.id_faq_sub_topic, s.faq_sub_topic, t.id_faq_topic, t.faq_topic')
            ->where('a.id_faq_article', (int) $id)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new NotFoundException('Artikel FAQ tidak ditemukan.');
        }

        return $row;
    }

    /**
     * faq_article a ⨝ faq_sub_topic s ⨝ faq_topic t dengan seluruh rantai status 1.
     */
    private function chainBuilder(): BaseBuilder
    {
        return $this->db->table('faq_article a')
            ->join('faq_sub_topic s', 's.id_faq_sub_topic = a.id_faq_sub_topic')
            ->join('faq_topic t', 't.id_faq_topic = s.id_faq_topic')
            ->where('a.status', MasterModel::STATUS_ACTIVE)
            ->where('s.status', MasterModel::STATUS_ACTIVE)
            ->where('t.status', MasterModel::STATUS_ACTIVE);
    }

    /**
     * @param list<string> $columns
     */
    private function activeOrdered(string $table, array $columns, string $nameColumn): BaseBuilder
    {
        return $this->db->table($table)
            ->select($columns)
            ->where('status', MasterModel::STATUS_ACTIVE)
            ->orderBy('order', 'ASC')
            ->orderBy($nameColumn, 'ASC');
    }

    /**
     * FULLTEXT legacy dengan parameter terikat, urut relevansi lalu id terbaru. Tabel ber-prefix (DBPrefix).
     *
     * @return list<array<string, mixed>>
     */
    private function fulltextSearch(string $search): array
    {
        $article  = $this->db->escapeIdentifiers($this->db->prefixTable('faq_article'));
        $subTopic = $this->db->escapeIdentifiers($this->db->prefixTable('faq_sub_topic'));
        $topic    = $this->db->escapeIdentifiers($this->db->prefixTable('faq_topic'));
        $active   = (int) MasterModel::STATUS_ACTIVE;
        $match    = 'MATCH(a.`title`, a.`content_stripped`) AGAINST(? IN NATURAL LANGUAGE MODE)';

        $sql = "SELECT a.`id_faq_article`, a.`title`, SUBSTRING(a.`content_stripped`, 1, 1000) AS `content_head`,
                    s.`id_faq_sub_topic`, s.`faq_sub_topic`, t.`id_faq_topic`, t.`faq_topic`, {$match} AS `relevance`
                FROM {$article} a
                JOIN {$subTopic} s ON s.`id_faq_sub_topic` = a.`id_faq_sub_topic`
                JOIN {$topic} t ON t.`id_faq_topic` = s.`id_faq_topic`
                WHERE a.`status` = {$active} AND s.`status` = {$active} AND t.`status` = {$active} AND {$match}
                ORDER BY `relevance` DESC, a.`id_faq_article` DESC
                LIMIT " . self::SEARCH_LIMIT;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, [$search, $search])->getResultArray();

        return $rows;
    }

    /**
     * Fallback judul: LIKE %q% lewat Query Builder. Builder CI4 menambahkan ESCAPE '!' tetapi tidak meng-escape
     * wildcard di nilai, jadi `!`, `%`, dan `_` dari kata kunci di-escape di sini agar dicari sebagai karakter biasa
     * (tanda kutip tetap di-escape oleh binding builder).
     *
     * @return list<array<string, mixed>>
     */
    private function titleSearch(string $search): array
    {
        $escapeChar = $this->db->likeEscapeChar;
        $literal    = str_replace([$escapeChar, '%', '_'], [$escapeChar . $escapeChar, $escapeChar . '%', $escapeChar . '_'], $search);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->chainBuilder()
            ->select('a.id_faq_article, a.title, s.id_faq_sub_topic, s.faq_sub_topic, t.id_faq_topic, t.faq_topic')
            ->select('SUBSTRING(a.content_stripped, 1, 1000) AS content_head', false)
            ->like('a.title', $literal)
            ->orderBy('a.id_faq_article', 'DESC')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->getResultArray();

        return $rows;
    }

    /**
     * Kalimat pertama (sampai . ! ? : yang diikuti spasi/akhir teks, seperti preview legacy L_faq.php:998), spasi
     * dirapatkan, entity HTML didekode, maks SNIPPET_LENGTH karakter.
     */
    private static function snippet(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (preg_match('/^.*?[.!?:](?=\s|$)/u', $text, $m) === 1) {
            $text = $m[0];
        }

        if (mb_strlen($text) > self::SNIPPET_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::SNIPPET_LENGTH - 1)) . '…';
        }

        return $text;
    }

    private function normalizeReason(mixed $reason): string
    {
        if ($reason !== null && ! is_string($reason)) {
            throw ValidationException::forField('reason', 'Alasan harus teks.');
        }

        // Byte non-UTF-8 (hanya bisa lewat form-urlencoded) terpotong/kosong diam-diam di koneksi strictOn=false, atau
        // gagal ditulis (1366) di koneksi strict.
        if ($reason !== null && ! mb_check_encoding($reason, 'UTF-8')) {
            throw ValidationException::forField('reason', 'Alasan tidak valid.');
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::forField('reason', 'Alasan wajib diisi bila artikel kurang membantu.');
        }

        // TINYTEXT = 255 BYTE (bukan karakter); koneksi strictOn=false akan memotong diam-diam.
        if (strlen($reason) > self::REASON_MAX_BYTES) {
            throw ValidationException::forField('reason', 'Alasan maksimal ' . self::REASON_MAX_BYTES . ' byte.');
        }

        return $reason;
    }

    private static function alreadyRated(): ValidationException
    {
        return ValidationException::forField('rate', 'Artikel ini sudah Anda nilai.');
    }

    /**
     * id_pengguna aktor untuk kolom audit legacy created_by (null bila akun tidak ditemukan).
     */
    private function actorId(string $nip): ?int
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table('pengguna')->select('id_pengguna')->where('nip', $nip)->get()->getRowArray();

        return $row === null ? null : (int) $row['id_pengguna'];
    }

    private function rates(): FaqRateModel
    {
        return $this->rates ??= new FaqRateModel($this->db);
    }
}
