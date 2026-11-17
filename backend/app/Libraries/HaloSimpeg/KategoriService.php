<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Exceptions\ApiException;
use App\Libraries\ListQuery;
use App\Models\HaloSimpeg\QuestionCategoryModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Master kategori pertanyaan Halo Simpeg (E-02, MAKE-070; PRD Fase 8 v1.3 §6.1, §6.1b `Kategori`/`KategoriOpsi`, §7.2;
 * [K `LS:220-430`, `SC:164-287`]).
 *
 * - Nama wajib, ≤ 250 code point, unik di aplikasi (trim; perbandingan kolom `_ci`) [K `LS:392-424`] — DBV-028 Q3 (tanpa UNIQUE).
 * - Hapus fisik [K `LS:363-365`]; v2 menolak 422 `kategori_dipakai` bila dipakai `disiplin_ticket` atau `officer_category`
 *   (FK RESTRICT, B-12). `bisa_hapus` dihitung dari aturan yang sama.
 * - Urutan daftar dan opsi: `created_at` naik [K `LS:221`, `:931`], pemutus seri `id` naik (DBV-028 N3).
 */
final class KategoriService extends MasterHaloSimpeg
{
    public const PESAN_DUPLIKAT = 'Kategori sudah terdaftar.';

    public const PESAN_DIPAKAI = 'Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini.';

    private const KOLOM = 'qc.id_question_category, qc.question_category, qc.status, qc.created_at, qc.updated_at';

    /**
     * `GET kategori` (role 1): semua status, `Halaman<Kategori>`, filter `status` (0/1) dan `q` (nama).
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(array $query): array
    {
        $p       = $this->listQuery($query, ['status', 'q']);
        $status  = $this->filterStatus($p);
        $q       = $p->string('q');
        $page    = $p->page();
        $perPage = $this->perHalaman($p);

        $saring = function (BaseBuilder $b) use ($status, $q): BaseBuilder {
            if ($status !== null) {
                $b->where('qc.status', $status);
            }

            if ($q !== '') {
                $b->like('qc.question_category', ListQuery::likeLiteral($this->db, $q), 'both', null, true);
            }

            return $b;
        };

        $total = (int) $saring($this->db->table('question_category qc'))->countAllResults();
        $rows  = $saring($this->kueri())
            ->orderBy('qc.created_at', 'ASC')->orderBy('qc.id_question_category', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(fn (array $r): array => $this->baris($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * `GET kategori/opsi` (role 1–7): kategori status 1 untuk form tiket [K `LS:931`].
     *
     * @return list<array{id: int, nama: string}>
     */
    public function opsi(): array
    {
        $rows = $this->db->table('question_category')->select('id_question_category, question_category')
            ->where('status', self::STATUS_AKTIF)
            ->orderBy('created_at', 'ASC')->orderBy('id_question_category', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id_question_category'], 'nama' => (string) $r['question_category']], $rows);
    }

    /**
     * @param array<string, mixed> $in `question_category`, `status?` (bawaan 1)
     *
     * @return array<string, mixed>
     */
    public function buat(array $in): array
    {
        $data = $this->validasi($in, null);
        $now  = self::sekarang();
        $id   = (int) (new QuestionCategoryModel($this->db))->insert($data + ['created_at' => $now, 'updated_at' => $now], true);

        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $in `question_category`, `status?` (bawaan nilai lama)
     *
     * @return array<string, mixed>
     */
    public function ubah(int $id, array $in): array
    {
        $lama = $this->ambil($id);
        $data = $this->validasi($in, $lama);
        (new QuestionCategoryModel($this->db))->update($id, $data + ['updated_at' => self::sekarang()]);

        return $this->detail($id);
    }

    /**
     * `PATCH kategori/{id}/status` (K-A1): status sama → tanpa tulis/audit; `peringatan` selalu null untuk kategori.
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, mixed $status): array
    {
        $lama = $this->ambil($id);
        $baru = self::statusDari($status) ?? throw self::galatStatus();

        if ($baru !== (int) $lama['status']) {
            (new QuestionCategoryModel($this->db))->update($id, ['status' => $baru, 'updated_at' => self::sekarang()]);
        }

        return $this->detail($id) + ['peringatan' => null];
    }

    /**
     * @return array{id: int}
     */
    public function hapus(int $id): array
    {
        $this->ambil($id);

        // Cek di dalam transaksi sesudah baris dikunci (M2); balapan yang lolos (FK RESTRICT 1451) → 422 yang sama.
        $this->dalamTransaksi(function () use ($id): void {
            $this->kunci('question_category', 'id_question_category', [$id]);

            // Satu sumber dengan `bisa_hapus` (dipakai tiket atau petugas).
            if (! $this->baris($this->ambil($id))['bisa_hapus']) {
                throw self::galatKode('kategori_dipakai', self::PESAN_DIPAKAI);
            }

            (new QuestionCategoryModel($this->db))->delete($id);
        }, static fn (DatabaseException $e): ?ApiException => $e->getCode() === 1451 ? self::galatKode('kategori_dipakai', self::PESAN_DIPAKAI) : null);

        return ['id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        return $this->baris($this->ambil($id));
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $in
     * @param array<string, mixed>|null $lama
     *
     * @return array{question_category: string, status: int}
     */
    private function validasi(array $in, ?array $lama): array
    {
        $errors = [];
        $nama   = self::teksDari($in, 'question_category', $errors);
        $status = array_key_exists('status', $in) ? self::statusDari($in['status']) : ($lama === null ? self::STATUS_AKTIF : (int) $lama['status']);

        if (isset($errors['question_category'])) {
            // Bukan teks (M4).
        } elseif ($nama === '') {
            $errors['question_category'] = 'Nama kategori wajib diisi.';
        } elseif (mb_strlen($nama) > $this->config->maksNamaKategori) {
            $errors['question_category'] = "Nama kategori maksimal {$this->config->maksNamaKategori} karakter.";
        }

        if ($status === null) {
            $errors['status'] = self::PESAN_STATUS;
        }

        self::lemparBilaGalat($errors);

        $dup = $this->db->table('question_category')->where('question_category', $nama);

        if ($lama !== null) {
            $dup->where('id_question_category !=', (int) $lama['id_question_category']);
        }

        if ($dup->countAllResults() > 0) {
            throw self::galatKode('kategori_duplikat', self::PESAN_DUPLIKAT, 'question_category');
        }

        return ['question_category' => $nama, 'status' => (int) $status];
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->kueri()->where('qc.id_question_category', $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw self::tidakDitemukan();
        }

        return $r;
    }

    /** Kolom baris + `jumlah_petugas` dan penanda dipakai (satu kueri, tanpa N+1). */
    private function kueri(): BaseBuilder
    {
        $petugas = $this->db->prefixTable('officer_category');
        $tiket   = $this->db->prefixTable('disiplin_ticket');

        return $this->db->table('question_category qc')->select(self::KOLOM)
            ->select("(SELECT COUNT(*) FROM {$petugas} oc WHERE oc.id_question_category = qc.id_question_category) AS jumlah_petugas", false)
            ->select("EXISTS (SELECT 1 FROM {$tiket} t WHERE t.id_question_category = qc.id_question_category) AS dipakai_tiket", false);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function baris(array $r): array
    {
        $jumlah = (int) $r['jumlah_petugas'];

        return [
            'id'             => (int) $r['id_question_category'],
            'nama'           => (string) $r['question_category'],
            'status'         => (int) $r['status'],
            'jumlah_petugas' => $jumlah,
            'bisa_hapus'     => $jumlah === 0 && (int) $r['dipakai_tiket'] === 0,
            'dibuat_pada'    => self::iso($r['created_at']),
            'diubah_pada'    => self::iso($r['updated_at']),
        ];
    }
}
