<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * G-10 — FAQ (pilot DBV-002). Legacy: hr/faq/* (baca) dan hr/faq/admin/* (kelola konten, role 1).
 *
 * Kelola konten (role 1) = endpoint master generik: `faq-topic`, `faq-sub-topic`, `faq-article`
 * (lihat UmumController untuk daftar route). Isi artikel disanitasi server (HTMLPurifier) saat tulis. Dropdown
 * `master/{faq-topic|faq-sub-topic|faq-article}/options` juga hanya role 1 (publicOptions false): options tidak
 * menyaring rantai status.
 *
 * Baca & rating (logika di App\Libraries\MasterData\FaqService):
 *   GET  api/v1/faq                 (UL_ALL)  → { topics: [{ id, nama, sub_topics: [{ id, nama, articles: [{ id, title }] }] }] }
 *   GET  api/v1/faq?search=q        (UL_ALL)  → { results: [{ id, title, topic, sub_topic, snippet }], search }
 *   GET  api/v1/faq/{id}            (UL_ALL)  → { id, title, content, topic, sub_topic, updated_at, related, rating }
 *   POST api/v1/faq/{id}/rate       (2, 6, 7) { rate: 1|2, reason? } → 201 { rated: true, rate }
 * Hanya entri yang seluruh rantainya (topik, sub topik, artikel) aktif yang tampil; selain itu 404.
 */
class FaqController extends BaseMasterController
{
    protected array $entities = ['faq-topic', 'faq-sub-topic', 'faq-article'];

    public function publicIndex(): ResponseInterface
    {
        $search = $this->request->getGet('search');

        if ($search !== null && ! is_string($search)) {
            throw ValidationException::forField('search', 'Kata kunci pencarian harus teks.');
        }

        return $this->respondSuccess(service('faqService')->browse($search ?? ''));
    }

    public function publicShow(string $id): ResponseInterface
    {
        $auth = service('authContext');

        return $this->respondSuccess(service('faqService')->article($id, $auth->nip(), $auth->role()));
    }

    public function rate(string $id): ResponseInterface
    {
        $payload = $this->payload();
        $data    = $this->validateOrFail($payload, [
            'rate' => [
                'rules'  => 'required|in_list[1,2]',
                'errors' => [
                    'required' => 'Penilaian wajib diisi.',
                    'in_list'  => 'Penilaian hanya boleh 1 (Membantu) atau 2 (Kurang Membantu).',
                ],
            ],
        ]);
        $auth = service('authContext');

        return $this->respondSuccess(
            service('faqService')->rate($id, $auth->nip(), $auth->role(), (int) $data['rate'], $payload['reason'] ?? null),
            201,
        );
    }
}
