<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\ListQuery;
use App\Models\HaloSimpeg\Chatbot\QnaKategoriModel;
use App\Models\HaloSimpeg\Chatbot\QnaTagModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Chatbot;

/**
 * Admin kategori dan tag QnA chatbot (role 1; PRD Fase 8 §6.5, §7.10.4, F8-38/F8-40; `dev:libraries/hr/L_chatbot.php:46-214`).
 *
 * - Status 1 Aktif / 2 Tidak Aktif / 10 Dihapus; hapus = status 10 tanpa Pulihkan; baris status 10 = 404 dan tidak tampil.
 * - Judul unik di antara status 1/2: di-trim lalu dicek dengan perbandingan kolom `_ci` (huruf besar/kecil dan aksen
 *   diabaikan, sama dengan UNIQUE `judul_unik`, catatan DBV N2); balapan yang lolos cek → 1062 diterjemahkan ke 422 yang sama.
 * - Tulis lewat model beraudit (`audit_logs`).
 */
final class MasterChatbotService
{
    public const KATEGORI = 'kategori';

    public const TAG = 'tag';

    private const DIHAPUS = 10;

    private readonly BaseConnection $db;

    private readonly Chatbot $config;

    public function __construct(private readonly string $jenis, ?BaseConnection $db = null, ?Chatbot $config = null)
    {
        $this->db     = $db ?? db_connect();
        $this->config = $config ?? config(Chatbot::class);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, int|string>>, total: int, page: int, per_page: int}
     */
    public function daftar(array $query): array
    {
        $p       = ListQuery::from($query, ['status', 'q', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage($this->config->perHalamanBawaan, $this->config->perHalamanMaks);
        $status  = $p->string('status');
        $q       = $p->string('q');

        $saring = function (BaseBuilder $b) use ($status, $q): void {
            in_array($status, ['1', '2'], true) ? $b->where($this->kolomStatus(), (int) $status) : $b->whereIn($this->kolomStatus(), [1, 2]);

            if ($q !== '') {
                $b->like($this->kolomJudul(), ListQuery::likeLiteral($this->db, $q));
            }
        };

        $hitung = $this->db->table($this->tabel());
        $saring($hitung);
        $total = (int) $hitung->countAllResults();

        $b = $this->db->table($this->tabel())->select($this->kolom());
        $saring($b);
        $rows = $b->orderBy($this->pk(), $this->jenis === self::TAG ? 'DESC' : 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(fn (array $r): array => $this->baris($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return array<string, int|string>
     */
    public function detail(int $id): array
    {
        return $this->baris($this->ambil($id));
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, int|string>
     */
    public function buat(array $p): array
    {
        $judul = $this->judulValid($p['judul'] ?? null);
        $data  = [$this->kolomJudul() => $judul, $this->kolomStatus() => 1, 'created_at' => gmdate('Y-m-d H:i:s')];

        if ($this->jenis === self::KATEGORI) {
            $data['tampil_bubble'] = $this->flag($p['tampil_bubble'] ?? 0, 'tampil_bubble');
        }

        $this->wajibUnik($judul, null);
        $id = (int) $this->tulis(fn () => $this->model()->insert($data, true));

        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, int|string>
     */
    public function ubah(int $id, array $p): array
    {
        $this->ambil($id);
        $judul  = $this->judulValid($p['judul'] ?? null);
        $status = $this->statusValid($p['status'] ?? null);
        $data   = [$this->kolomJudul() => $judul, $this->kolomStatus() => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];

        if ($this->jenis === self::KATEGORI) {
            $data['tampil_bubble'] = $this->flag($p['tampil_bubble'] ?? 0, 'tampil_bubble');
        }

        $this->wajibUnik($judul, $id);
        $this->tulis(fn () => $this->model()->update($id, $data));

        return $this->detail($id);
    }

    /**
     * @return array<string, int|string>
     */
    public function setStatus(int $id, mixed $status): array
    {
        $this->ambil($id);
        $this->model()->update($id, [$this->kolomStatus() => $this->statusValid($status), 'updated_at' => gmdate('Y-m-d H:i:s')]);

        return $this->detail($id);
    }

    /** Hapus lunak (status 10). Relasi `qna_has_tag` dan `qna.id_qna_kategori_fk` tidak diubah (F8-38, F8-49). */
    public function hapus(int $id): void
    {
        $this->ambil($id);
        $this->model()->update($id, [$this->kolomStatus() => self::DIHAPUS, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->db->table($this->tabel())->select($this->kolom())->where($this->pk(), $id)->where($this->kolomStatus() . ' !=', self::DIHAPUS)->get()->getRowArray();

        if (! is_array($r)) {
            throw new NotFoundException($this->label() . ' tidak ditemukan.');
        }

        return $r;
    }

    private function judulValid(mixed $judul): string
    {
        $judul = is_string($judul) ? trim($judul) : '';
        $maks  = $this->jenis === self::KATEGORI ? 150 : 100;

        if ($judul === '') {
            throw ValidationException::forField('judul', 'Judul ' . strtolower($this->label()) . ' tidak boleh kosong!');
        }

        if (mb_strlen($judul) > $maks) {
            throw ValidationException::forField('judul', "Judul maksimal {$maks} karakter.");
        }

        return $judul;
    }

    private function wajibUnik(string $judul, ?int $kecuali): void
    {
        $b = $this->db->table($this->tabel())->where($this->kolomJudul(), $judul)->whereIn($this->kolomStatus(), [1, 2]);

        if ($kecuali !== null) {
            $b->where($this->pk() . ' !=', $kecuali);
        }

        if ($b->countAllResults() > 0) {
            throw $this->galatJudulDipakai();
        }
    }

    /**
     * Jalankan tulis; 1062 dari UNIQUE `judul_unik` (balapan) → 422 yang sama dengan cek aplikasi.
     */
    private function tulis(callable $tulis): mixed
    {
        try {
            return $tulis();
        } catch (DatabaseException $e) {
            if ($e->getCode() === 1062) {
                throw $this->galatJudulDipakai();
            }

            throw $e;
        }
    }

    private function galatJudulDipakai(): ValidationException
    {
        return ValidationException::forField('judul', 'Judul ' . strtolower($this->label()) . ' sudah dipakai.');
    }

    private function statusValid(mixed $status): int
    {
        if (! in_array($status, [1, 2, '1', '2'], true)) {
            throw ValidationException::forField('status', 'Status harus 1 (Aktif) atau 2 (Tidak Aktif).');
        }

        return (int) $status;
    }

    private function flag(mixed $v, string $field): int
    {
        if (! in_array($v, [0, 1, '0', '1', true, false], true)) {
            throw ValidationException::forField($field, 'Nilai harus 0 atau 1.');
        }

        return (int) $v;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, int|string>
     */
    private function baris(array $r): array
    {
        $out = ['id' => (int) $r[$this->pk()], 'judul' => (string) $r[$this->kolomJudul()], 'status' => (int) $r[$this->kolomStatus()]];

        if ($this->jenis === self::KATEGORI) {
            $out['tampil_bubble'] = (int) $r['tampil_bubble'];
        }

        return $out;
    }

    private function model(): QnaKategoriModel|QnaTagModel
    {
        return $this->jenis === self::KATEGORI ? new QnaKategoriModel($this->db) : new QnaTagModel($this->db);
    }

    /** Kolom yang dibaca (tanpa `judul_unik`, catatan DBV N4). */
    private function kolom(): string
    {
        return $this->jenis === self::KATEGORI
            ? 'id_qna_kategori, judul_kategori, status_qna_kategori_fk, tampil_bubble'
            : 'id_qna_tag, judul_tag, status_qna_tag_fk';
    }

    private function tabel(): string
    {
        return $this->jenis === self::KATEGORI ? 'qna_kategori' : 'qna_tag';
    }

    private function pk(): string
    {
        return $this->jenis === self::KATEGORI ? 'id_qna_kategori' : 'id_qna_tag';
    }

    private function kolomJudul(): string
    {
        return $this->jenis === self::KATEGORI ? 'judul_kategori' : 'judul_tag';
    }

    private function kolomStatus(): string
    {
        return $this->jenis === self::KATEGORI ? 'status_qna_kategori_fk' : 'status_qna_tag_fk';
    }

    private function label(): string
    {
        return $this->jenis === self::KATEGORI ? 'Kategori' : 'Tag';
    }
}
