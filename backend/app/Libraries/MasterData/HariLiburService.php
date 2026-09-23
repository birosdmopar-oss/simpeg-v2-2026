<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\CacheService;
use App\Models\MasterData\HariLiburModel;
use CodeIgniter\Database\BaseConnection;

/**
 * G-08 — Master hari libur. Tidak memakai engine generik karena punya dua aturan khusus (DoD G-08):
 *
 *  1. `tgl_mulai` <= `tgl_akhir`.
 *  2. Rentang tanggal TIDAK BOLEH overlap dengan hari libur lain yang sudah terdaftar
 *     (dua rentang overlap bila `tgl_mulai <= akhir_lain` DAN `tgl_akhir >= mulai_lain`).
 *
 * Hari libur non-aktif (status '0', hasil "hapus") tidak ikut dicek overlap — supaya tanggal bekas entri yang
 * dihapus bisa dipakai lagi. Hapus = soft delete (status '0'), sama dengan master lain (G-TC #2).
 * Dipakai kalkulasi hari kerja Tukin Fase 5, jadi cache hari libur di-invalidate setiap penulisan.
 */
class HariLiburService
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX     = 100;

    private BaseConnection $db;

    public function __construct(
        private HariLiburModel $model,
        private CacheService $cache,
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array<string, mixed> $filters search, status, id_jenis_libur, tahun, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $filters = []): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($filters['per_page'] ?? self::PER_PAGE_DEFAULT)));

        $builder = $this->db->table('hari_libur');

        if (isset($filters['status']) && in_array((string) $filters['status'], ['0', '1'], true)) {
            $builder->where('status', (string) $filters['status']);
        }

        if (! empty($filters['id_jenis_libur'])) {
            $builder->where('id_jenis_libur', (string) $filters['id_jenis_libur']);
        }

        if (! empty($filters['tahun'])) {
            $tahun = (int) $filters['tahun'];
            $builder->where('tgl_akhir >=', sprintf('%04d-01-01', $tahun))->where('tgl_mulai <=', sprintf('%04d-12-31', $tahun));
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);
            $builder->groupStart()->like('nama', $search)->orLike('keterangan', $search)->groupEnd();
        }

        $total = (clone $builder)->countAllResults();

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy('tgl_mulai', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        return $this->findOrFail($id);
    }

    /**
     * Hari libur aktif dalam satu rentang tanggal — dipakai kalkulasi hari kerja (Fase 5).
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $from, string $to): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('hari_libur')
            ->where('status', HariLiburModel::STATUS_ACTIVE)
            ->where('tgl_mulai <=', $to)
            ->where('tgl_akhir >=', $from)
            ->orderBy('tgl_mulai', 'ASC')
            ->get()
            ->getResultArray();

        return $rows;
    }

    /**
     * @param array<string, mixed> $data id_jenis_libur, nama, tgl_mulai, tgl_akhir, keterangan?, status?
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $row = $this->validated($data);
        $this->assertJenisLiburUsable((string) $row['id_jenis_libur']);
        $this->assertNoOverlap((string) $row['tgl_mulai'], (string) $row['tgl_akhir']);

        $this->model->insert($row);
        $this->invalidate();

        return $this->findOrFail((int) $this->model->getInsertID());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $current = $this->findOrFail($id);
        $merged  = array_merge($current, array_intersect_key($data, array_flip(HariLiburModel::EDITABLE)));
        $row     = $this->validated($merged);

        if ($row['id_jenis_libur'] !== $current['id_jenis_libur']) {
            $this->assertJenisLiburUsable((string) $row['id_jenis_libur']);
        }

        $this->assertNoOverlap((string) $row['tgl_mulai'], (string) $row['tgl_akhir'], $id);

        $this->model->update($id, $row);
        $this->invalidate();

        return $this->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(int $id, string $status): array
    {
        $current = $this->findOrFail($id);
        $status  = $status === HariLiburModel::STATUS_INACTIVE ? HariLiburModel::STATUS_INACTIVE : HariLiburModel::STATUS_ACTIVE;

        if ((string) $current['status'] !== $status) {
            // Mengaktifkan kembali: rentangnya harus tetap bebas overlap dengan hari libur aktif lain.
            if ($status === HariLiburModel::STATUS_ACTIVE) {
                $this->assertNoOverlap((string) $current['tgl_mulai'], (string) $current['tgl_akhir'], $id);
            }

            $this->model->update($id, ['status' => $status]);
            $this->invalidate();
        }

        return $this->findOrFail($id);
    }

    /**
     * Soft delete (status '0'), audit event 'delete' — sama dengan master lain.
     *
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $current = $this->findOrFail($id);

        if ((string) $current['status'] !== HariLiburModel::STATUS_INACTIVE) {
            $this->model->softDelete((string) $id);
            $this->invalidate();
        }

        return $this->findOrFail($id);
    }

    // ------------------------------------------------------------------
    // Validasi
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $mulai = trim((string) ($data['tgl_mulai'] ?? ''));
        $akhir = trim((string) ($data['tgl_akhir'] ?? ''));

        if ($mulai === '' || $akhir === '') {
            throw new ValidationException('Tanggal mulai dan tanggal akhir wajib diisi.', [
                'tgl_mulai' => $mulai === '' ? ['Tanggal mulai wajib diisi.'] : [],
                'tgl_akhir' => $akhir === '' ? ['Tanggal akhir wajib diisi.'] : [],
            ]);
        }

        if ($akhir < $mulai) {
            throw ValidationException::forField('tgl_akhir', 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.');
        }

        $status = (string) ($data['status'] ?? HariLiburModel::STATUS_ACTIVE);

        return [
            'id_jenis_libur' => trim((string) ($data['id_jenis_libur'] ?? '')),
            'nama'           => trim((string) preg_replace('/\s+/u', ' ', (string) ($data['nama'] ?? ''))),
            'tgl_mulai'      => $mulai,
            'tgl_akhir'      => $akhir,
            'keterangan'     => ($data['keterangan'] ?? '') === '' ? null : trim((string) $data['keterangan']),
            'status'         => $status === HariLiburModel::STATUS_INACTIVE ? HariLiburModel::STATUS_INACTIVE : HariLiburModel::STATUS_ACTIVE,
        ];
    }

    /**
     * Dua rentang [a1,a2] dan [b1,b2] overlap bila a1 <= b2 dan a2 >= b1.
     */
    private function assertNoOverlap(string $mulai, string $akhir, ?int $exceptId = null): void
    {
        $builder = $this->db->table('hari_libur')
            ->where('status', HariLiburModel::STATUS_ACTIVE)
            ->where('tgl_mulai <=', $akhir)
            ->where('tgl_akhir >=', $mulai);

        if ($exceptId !== null) {
            $builder->where('id_hari_libur !=', $exceptId);
        }

        /** @var array<string, mixed>|null $conflict */
        $conflict = $builder->orderBy('tgl_mulai', 'ASC')->get()->getRowArray();

        if ($conflict !== null) {
            throw ValidationException::forField(
                'tgl_mulai',
                sprintf(
                    'Rentang tanggal bertabrakan dengan hari libur "%s" (%s s.d. %s).',
                    $conflict['nama'],
                    $conflict['tgl_mulai'],
                    $conflict['tgl_akhir'],
                ),
            );
        }
    }

    private function assertJenisLiburUsable(string $idJenisLibur): void
    {
        /** @var array<string, mixed>|null $jenis */
        $jenis = $this->db->table('jenis_libur')->where('id_jenis_libur', $idJenisLibur)->get()->getRowArray();

        if ($jenis === null) {
            throw ValidationException::forField('id_jenis_libur', 'Jenis hari libur tidak ditemukan.');
        }

        if ((string) $jenis['status'] !== HariLiburModel::STATUS_ACTIVE) {
            throw ValidationException::forField('id_jenis_libur', "Jenis hari libur {$jenis['nama_jenis_libur']} sedang non-aktif.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(int $id): array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table('hari_libur')->where('id_hari_libur', $id)->get()->getRowArray();

        if ($row === null) {
            throw new NotFoundException("Hari libur {$id} tidak ditemukan.");
        }

        return $row;
    }

    private function invalidate(): void
    {
        $this->cache->invalidateMatching('hari_libur_*');
    }
}
