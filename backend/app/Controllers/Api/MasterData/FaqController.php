<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Models\MasterData\MasterModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * G-10 — FAQ. Legacy: hr/faq/* (view publik untuk yang sudah login) dan hr/faq/admin/* (kelola konten, role 1).
 *
 * Kelola konten (role 1) memakai endpoint generik master: `faq-topic`, `faq-sub-topic`, `faq-article`.
 * View untuk pegawai (UL_ALL, hanya entri aktif/published):
 *   GET api/v1/faq            ?search=  → topik → sub topik → artikel (judul saja) yang published
 *   GET api/v1/faq/{id_article}         → satu artikel lengkap (404 kalau tidak published)
 *
 * BELUM: rating artikel (`faq_rate`) — DDL legacy belum tersedia dan FK `nip` butuh tabel `pegawai` (Fase 3).
 * Lihat backend/docs/progress/02-MasterData.md.
 */
class FaqController extends BaseMasterController
{
    protected array $entities = ['faq-topic', 'faq-sub-topic', 'faq-article'];

    /**
     * Daftar FAQ published, tersusun topik → sub topik → artikel. Terbuka untuk semua role yang login.
     */
    public function publicIndex(): ResponseInterface
    {
        $search = trim((string) ($this->request->getGet('search') ?? ''));
        $db     = db_connect();

        $articleBuilder = $db->table('faq_article')
            ->select(['id_article', 'id_sub_topic', 'judul', 'order'])
            ->where('status', MasterModel::STATUS_ACTIVE);

        if ($search !== '') {
            $articleBuilder->groupStart()->like('judul', $search)->orLike('isi', $search)->groupEnd();
        }

        /** @var list<array<string, mixed>> $articles */
        $articles = $articleBuilder->orderBy('order', 'ASC')->orderBy('judul', 'ASC')->get()->getResultArray();

        /** @var list<array<string, mixed>> $subTopics */
        $subTopics = $db->table('faq_sub_topic')
            ->where('status', MasterModel::STATUS_ACTIVE)
            ->orderBy('order', 'ASC')
            ->orderBy('nama_sub_topic', 'ASC')
            ->get()
            ->getResultArray();

        /** @var list<array<string, mixed>> $topics */
        $topics = $db->table('faq_topic')
            ->where('status', MasterModel::STATUS_ACTIVE)
            ->orderBy('order', 'ASC')
            ->orderBy('nama_topic', 'ASC')
            ->get()
            ->getResultArray();

        $result = [];

        foreach ($topics as $topic) {
            $subs = [];

            foreach ($subTopics as $sub) {
                if ((string) $sub['id_topic'] !== (string) $topic['id_topic']) {
                    continue;
                }

                $items = array_values(array_filter(
                    $articles,
                    static fn (array $a): bool => (string) $a['id_sub_topic'] === (string) $sub['id_sub_topic'],
                ));

                if ($items === [] && $search !== '') {
                    continue;
                }

                $subs[] = [
                    'id_sub_topic'   => $sub['id_sub_topic'],
                    'nama_sub_topic' => $sub['nama_sub_topic'],
                    'articles'       => array_map(static fn (array $a): array => [
                        'id_article' => $a['id_article'],
                        'judul'      => $a['judul'],
                    ], $items),
                ];
            }

            if ($subs === [] && $search !== '') {
                continue;
            }

            $result[] = [
                'id_topic'   => $topic['id_topic'],
                'nama_topic' => $topic['nama_topic'],
                'sub_topics' => $subs,
            ];
        }

        return $this->respondSuccess($result);
    }

    /**
     * Satu artikel published. Artikel/sub topik/topik non-aktif → 404 (tidak bocor ke pegawai).
     */
    public function publicShow(string $idArticle): ResponseInterface
    {
        $db = db_connect();

        /** @var array<string, mixed>|null $row */
        $row = $db->table('faq_article a')
            ->select('a.id_article, a.judul, a.isi, a.id_sub_topic, s.nama_sub_topic, t.id_topic, t.nama_topic')
            ->join('faq_sub_topic s', 's.id_sub_topic = a.id_sub_topic')
            ->join('faq_topic t', 't.id_topic = s.id_topic')
            ->where('a.id_article', $idArticle)
            ->where('a.status', MasterModel::STATUS_ACTIVE)
            ->where('s.status', MasterModel::STATUS_ACTIVE)
            ->where('t.status', MasterModel::STATUS_ACTIVE)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return $this->respondError('Artikel FAQ tidak ditemukan.', 404);
        }

        return $this->respondSuccess($row);
    }
}
