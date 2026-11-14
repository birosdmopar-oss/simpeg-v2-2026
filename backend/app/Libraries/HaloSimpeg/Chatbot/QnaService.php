<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Html\HtmlSanitizer;
use App\Libraries\ListQuery;
use App\Models\AuditLogModel;
use App\Models\HaloSimpeg\Chatbot\QnaModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Chatbot;
use Throwable;

/**
 * Admin QnA chatbot (role 1; PRD Fase 8 §6.5 `qna*`, §7.10.4, F8-38/F8-41/F8-49; `dev:libraries/hr/L_chatbot.php:258-377`).
 *
 * - Jawaban HTML disanitasi saat simpan dengan whitelist FAQ (`htmlSanitizer`, BP8-14); kosong sesudah sanitasi → 422.
 * - Simpan QnA + set tag dalam satu transaksi; ubah = ganti seluruh set (A13). Relasi ditulis query builder dengan audit
 *   manual entitas `qna_has_tag` (before/after = daftar id tag) lewat model QnA.
 * - Status induk (F8-49): kategori baru wajib status 1; kategori lama status 2 boleh dipertahankan; kategori lama status 10
 *   yang tidak diganti → 422. Tag yang ditambahkan wajib status 1; tag lama status 2 yang tetap dikirim dipertahankan; tag
 *   lama status 10 diabaikan (relasinya tetap di DB, tidak tampil, tidak dibaca korpus).
 */
final class QnaService
{
    private const DIHAPUS = 10;

    public const PESAN_KATEGORI_DIHAPUS = 'Kategori QnA sudah dihapus. Pilih kategori lain.';

    private readonly BaseConnection $db;

    private readonly Chatbot $config;

    public function __construct(?BaseConnection $db = null, ?Chatbot $config = null, private readonly ?HtmlSanitizer $sanitizer = null)
    {
        $this->db     = $db ?? db_connect();
        $this->config = $config ?? config(Chatbot::class);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(array $query): array
    {
        $p        = ListQuery::from($query, ['status', 'kategori', 'tag', 'q', 'page', 'per_page']);
        $page     = $p->page();
        $perPage  = $p->perPage($this->config->perHalamanBawaan, $this->config->perHalamanMaks);
        $status   = $p->string('status');
        $kategori = $p->string('kategori');
        $tag      = $p->string('tag');
        $q        = $p->string('q');

        foreach (['kategori' => $kategori, 'tag' => $tag] as $k => $v) {
            if ($v !== '' && preg_match('/^[1-9][0-9]{0,9}\z/', $v) !== 1) {
                throw ValidationException::forField($k, 'ID tidak valid.');
            }
        }

        $saring = function (BaseBuilder $b) use ($status, $kategori, $tag, $q): void {
            in_array($status, ['1', '2'], true) ? $b->where('status_qna_fk', (int) $status) : $b->whereIn('status_qna_fk', [1, 2]);

            if ($kategori !== '') {
                $b->where('id_qna_kategori_fk', (int) $kategori);
            }

            if ($tag !== '') {
                $b->whereIn('id_qna', static fn (BaseBuilder $s) => $s->select('qna_id')->from('qna_has_tag')->where('qna_tag_id', (int) $tag));
            }

            if ($q !== '') {
                $b->like('pertanyaan', ListQuery::likeLiteral($this->db, $q));
            }
        };

        $hitung = $this->db->table('qna');
        $saring($hitung);
        $total = (int) $hitung->countAllResults();

        $b = $this->db->table('qna')->select('id_qna, pertanyaan, id_qna_kategori_fk, status_qna_fk, is_fallback_option');
        $saring($b);
        $rows = $b->orderBy('id_qna', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => $this->lengkapi($rows, false), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        return $this->lengkapi([$this->ambil($id)], true)[0];
    }

    /**
     * Pilihan form: kategori dan tag status 1, urut judul.
     *
     * @return array{kategori: list<array{id: int, judul: string}>, tag: list<array{id: int, judul: string}>}
     */
    public function opsi(): array
    {
        $kat = $this->db->table('qna_kategori')->select('id_qna_kategori AS id, judul_kategori AS judul')->where('status_qna_kategori_fk', 1)->orderBy('judul_kategori', 'ASC')->get()->getResultArray();
        $tag = $this->db->table('qna_tag')->select('id_qna_tag AS id, judul_tag AS judul')->where('status_qna_tag_fk', 1)->orderBy('judul_tag', 'ASC')->get()->getResultArray();
        $map = static fn (array $r): array => ['id' => (int) $r['id'], 'judul' => (string) $r['judul']];

        return ['kategori' => array_map($map, $kat), 'tag' => array_map($map, $tag)];
    }

    /**
     * Tambah (`$id` null) atau ubah QnA + set tag atomik.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    public function simpan(?int $id, array $p): array
    {
        $lama       = $id !== null ? $this->ambil($id) : null;
        $tagLama    = $id !== null ? $this->tagTerpasang($id) : [];
        $pertanyaan = is_string($p['pertanyaan'] ?? null) ? trim($p['pertanyaan']) : '';
        $jawaban    = $this->sanitizer()->sanitize(is_string($p['jawaban'] ?? null) ? $p['jawaban'] : '');
        $errors     = [];

        if ($pertanyaan === '') {
            $errors['pertanyaan'] = 'Pertanyaan tidak boleh kosong!';
        } elseif (($p1 = BatasQna::pertanyaan($pertanyaan)) !== null) {
            $errors['pertanyaan'] = $p1;
        }

        if (! HtmlSanitizer::hasVisibleContent($jawaban)) {
            $errors['jawaban'] = 'Jawaban tidak boleh kosong!';
        } elseif (($p2 = BatasQna::jawaban($jawaban)) !== null) {
            $errors['jawaban'] = $p2;
        }

        $kategori = $this->kategoriValid($p['id_kategori'] ?? null, $lama, $errors);
        $tag      = $this->tagValid($p['tag'] ?? [], $tagLama, $errors);
        $fallback = in_array($p['is_fallback_option'] ?? 0, [0, 1, '0', '1', true, false], true) ? (int) ($p['is_fallback_option'] ?? 0) : null;
        $status   = in_array($p['status'] ?? 1, [1, 2, '1', '2'], true) ? (int) ($p['status'] ?? 1) : null;

        if ($fallback === null) {
            $errors['is_fallback_option'] = 'Nilai harus 0 atau 1.';
        }

        if ($status === null) {
            $errors['status'] = 'Status harus 1 (Aktif) atau 2 (Tidak Aktif).';
        }

        if ($errors !== []) {
            throw new ValidationException((string) reset($errors), array_map(static fn (string $m): array => [$m], $errors));
        }

        $sekarang = gmdate('Y-m-d H:i:s');
        $data     = [
            'pertanyaan'         => $pertanyaan,
            'jawaban'            => $jawaban,
            'id_qna_kategori_fk' => $kategori,
            'is_fallback_option' => $fallback,
            'status_qna_fk'      => $status,
        ];
        $model = new QnaModel($this->db);

        $this->db->transException(true)->transStart();

        try {
            if ($id === null) {
                $id = (int) $model->insert($data + ['created_at' => $sekarang, 'updated_at' => $sekarang], true);
            } else {
                $model->update($id, $data + ['updated_at' => $sekarang]);
            }

            $this->gantiTag($model, $id, $tagLama, $tag);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return $this->detail($id);
    }

    /**
     * Aktifkan/Nonaktifkan tanpa menyentuh jawaban dan relasi tag (§6.5).
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, mixed $status): array
    {
        $this->ambil($id);

        if (! in_array($status, [1, 2, '1', '2'], true)) {
            throw ValidationException::forField('status', 'Status harus 1 (Aktif) atau 2 (Tidak Aktif).');
        }

        (new QnaModel($this->db))->update($id, ['status_qna_fk' => (int) $status, 'updated_at' => gmdate('Y-m-d H:i:s')]);

        return $this->detail($id);
    }

    public function hapus(int $id): void
    {
        $this->ambil($id);
        (new QnaModel($this->db))->update($id, ['status_qna_fk' => self::DIHAPUS, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Ganti set tag (hapus relasi yang dilepas, sisip yang baru) + audit manual. Relasi tag status 10 tidak disentuh.
     *
     * @param array<int, int> $lama id tag terpasang => status
     * @param list<int>       $baru id tag status 1/2 yang dipertahankan/ditambah
     */
    private function gantiTag(QnaModel $model, int $id, array $lama, array $baru): void
    {
        $terlihat = array_keys(array_filter($lama, static fn (int $s): bool => $s !== self::DIHAPUS));
        $lepas    = array_values(array_diff($terlihat, $baru));
        $tambah   = array_values(array_diff($baru, array_keys($lama)));

        if ($lepas !== []) {
            $this->db->table('qna_has_tag')->where('qna_id', $id)->whereIn('qna_tag_id', $lepas)->delete();
        }

        foreach ($tambah as $t) {
            $this->db->table('qna_has_tag')->insert(['qna_id' => $id, 'qna_tag_id' => $t]);
        }

        if ($lepas !== [] || $tambah !== []) {
            $sebelum = array_keys($lama);
            $sesudah = array_values(array_unique([...array_diff($sebelum, $lepas), ...$tambah]));
            sort($sebelum);
            sort($sesudah);
            $model->writeAudit(
                $sebelum === [] ? AuditLogModel::EVENT_CREATE : AuditLogModel::EVENT_UPDATE,
                $id,
                $sebelum === [] ? null : ['qna_id' => $id, 'qna_tag_id' => $sebelum],
                ['qna_id' => $id, 'qna_tag_id' => $sesudah],
                'qna_has_tag',
            );
        }
    }

    /**
     * @param array<string, mixed>|null $lama
     * @param array<string, string>     $errors
     */
    private function kategoriValid(mixed $idKategori, ?array $lama, array &$errors): ?int
    {
        $s = is_int($idKategori) ? (string) $idKategori : (is_string($idKategori) ? trim($idKategori) : '');

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
            $errors['id_kategori'] = 'Kategori wajib dipilih!';

            return null;
        }

        $id     = (int) $s;
        $status = $this->db->table('qna_kategori')->select('status_qna_kategori_fk')->where('id_qna_kategori', $id)->get()->getRow('status_qna_kategori_fk');
        $tetap  = $lama !== null && (int) $lama['id_qna_kategori_fk'] === $id;

        if ($status === null) {
            $errors['id_kategori'] = 'Kategori tidak ditemukan.';
        } elseif ((int) $status === self::DIHAPUS) {
            $errors['id_kategori'] = self::PESAN_KATEGORI_DIHAPUS;
        } elseif ((int) $status !== 1 && ! $tetap) {
            $errors['id_kategori'] = 'Kategori tidak aktif.';
        }

        return $id;
    }

    /**
     * @param array<int, int>       $lama
     * @param array<string, string> $errors
     *
     * @return list<int>
     */
    private function tagValid(mixed $tag, array $lama, array &$errors): array
    {
        if (! is_array($tag)) {
            $errors['tag'] = 'Tag harus berupa daftar.';

            return [];
        }

        $ids = [];

        foreach ($tag as $t) {
            $s = is_int($t) ? (string) $t : (is_string($t) ? $t : '');

            if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
                $errors['tag'] = 'Tag tidak aktif atau tidak ditemukan.';

                return [];
            }

            $ids[(int) $s] = true;
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return [];
        }

        $status = [];

        foreach ($this->db->table('qna_tag')->select('id_qna_tag, status_qna_tag_fk')->whereIn('id_qna_tag', $ids)->get()->getResultArray() as $r) {
            $status[(int) $r['id_qna_tag']] = (int) $r['status_qna_tag_fk'];
        }

        $hasil = [];

        foreach ($ids as $t) {
            $st = $status[$t] ?? null;

            if (isset($lama[$t]) && $lama[$t] === self::DIHAPUS) {
                continue; // tag lama status 10: diabaikan (F8-49b)
            }

            if ($st === 1 || ($st === 2 && isset($lama[$t]))) {
                $hasil[] = $t;

                continue;
            }

            $errors['tag'] = 'Tag tidak aktif atau tidak ditemukan.';

            return [];
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->db->table('qna')->select('id_qna, pertanyaan, jawaban, id_qna_kategori_fk, status_qna_fk, is_fallback_option')
            ->where('id_qna', $id)->where('status_qna_fk !=', self::DIHAPUS)->get()->getRowArray();

        if (! is_array($r)) {
            throw new NotFoundException('QnA tidak ditemukan.');
        }

        return $r;
    }

    /**
     * @return array<int, int> id tag terpasang (semua status) => status
     */
    private function tagTerpasang(int $id): array
    {
        $out = [];

        foreach ($this->db->table('qna_has_tag h')->select('h.qna_tag_id, t.status_qna_tag_fk')->join('qna_tag t', 't.id_qna_tag = h.qna_tag_id')
            ->where('h.qna_id', $id)->get()->getResultArray() as $r) {
            $out[(int) $r['qna_tag_id']] = (int) $r['status_qna_tag_fk'];
        }

        return $out;
    }

    /**
     * Tambah kategori ringkas dan tag (status 1/2; status 10 tidak dikirim).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function lengkapi(array $rows, bool $denganJawaban): array
    {
        if ($rows === []) {
            return [];
        }

        $ids   = array_map(static fn (array $r): int => (int) $r['id_qna'], $rows);
        $katId = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['id_qna_kategori_fk'], $rows))));
        $kat   = [];

        if ($katId !== []) {
            foreach ($this->db->table('qna_kategori')->select('id_qna_kategori, judul_kategori, status_qna_kategori_fk')->whereIn('id_qna_kategori', $katId)->get()->getResultArray() as $k) {
                $kat[(int) $k['id_qna_kategori']] = ['id' => (int) $k['id_qna_kategori'], 'judul' => (string) $k['judul_kategori'], 'status' => (int) $k['status_qna_kategori_fk']];
            }
        }

        $tag = [];

        foreach ($this->db->table('qna_has_tag h')->select('h.qna_id, t.id_qna_tag, t.judul_tag, t.status_qna_tag_fk')->join('qna_tag t', 't.id_qna_tag = h.qna_tag_id')
            ->whereIn('h.qna_id', $ids)->whereIn('t.status_qna_tag_fk', [1, 2])->orderBy('t.judul_tag', 'ASC')->get()->getResultArray() as $t) {
            $tag[(int) $t['qna_id']][] = ['id' => (int) $t['id_qna_tag'], 'judul' => (string) $t['judul_tag'], 'status' => (int) $t['status_qna_tag_fk']];
        }

        return array_map(static function (array $r) use ($kat, $tag, $denganJawaban): array {
            $out = [
                'id'                 => (int) $r['id_qna'],
                'pertanyaan'         => (string) $r['pertanyaan'],
                'is_fallback_option' => (int) $r['is_fallback_option'],
                'status'             => (int) $r['status_qna_fk'],
                'kategori'           => $kat[(int) $r['id_qna_kategori_fk']] ?? null,
                'tag'                => $tag[(int) $r['id_qna']] ?? [],
            ];

            if ($denganJawaban) {
                $out['jawaban'] = (string) $r['jawaban'];
            }

            return $out;
        }, $rows);
    }

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ?? service('htmlSanitizer');
    }
}
