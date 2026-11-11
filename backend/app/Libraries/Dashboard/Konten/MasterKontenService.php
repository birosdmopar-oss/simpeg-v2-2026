<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;

/**
 * Kelola master sederhana konten (PRD Fase 7 v2.0 Q7-07/Q7-11): kategori berita (`allow_ap`) dan jenis DIBO. Tulis = role 1;
 * baca daftar kelola = role 1 (semua status) dan role 3 hanya untuk kategori berita (yang aktif dan `allow_ap = 1`, sebagai
 * pilihan form). Status 1 aktif / 2 nonaktif / 10 dihapus (hapus lunak, bisa dipulihkan); nama unik di antara baris aktif
 * (UNIQUE DB tidak bisa bersyarat status, DBV-032-Q4). Urutan: naik/turun menomori ulang 1..n baris yang tidak dihapus.
 * Audit otomatis lewat model (Q7-16).
 */
final class MasterKontenService
{
    private const NAMA_MAKS = 150;

    private readonly BaseConnection $db;

    public function __construct(private readonly JenisMaster $jenis, ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return list<array{id: int, nama: string, urutan: int, status: int, allow_ap: int}>
     */
    public function daftar(AuthContext $auth): array
    {
        $role = (int) $auth->role();
        $b    = $this->db->table($this->jenis->tabel())->orderBy('order', 'ASC')->orderBy($this->jenis->kolomId(), 'ASC');

        if ($role !== Role::SUPER_ADMIN) {
            if ($role !== Role::ADMIN_SATKER || ! $this->jenis->punyaAllowAp()) {
                throw new ApiException('Anda tidak berhak melihat daftar ini.', 403);
            }

            $b->where('status', 1)->where('allow_ap', 1);
        }

        return array_map(fn (array $r): array => $this->baris($r), $b->get()->getResultArray());
    }

    /**
     * Pilihan baca (semua role): baris aktif saja, urut `order`.
     *
     * @return list<array{id: int, nama: string}>
     */
    public function aktif(): array
    {
        $pk   = $this->jenis->kolomId();
        $rows = $this->db->table($this->jenis->tabel())->select($pk . ', ' . $this->jenis->kolomNama())->where('status', 1)->orderBy('order', 'ASC')->orderBy($pk, 'ASC')->get()->getResultArray();

        return array_map(fn (array $r): array => ['id' => (int) $r[$pk], 'nama' => (string) $r[$this->jenis->kolomNama()]], $rows);
    }

    /**
     * @return array{id: int, nama: string, urutan: int, status: int, allow_ap: int}
     */
    public function simpan(?int $id, mixed $nama, mixed $allowAp, int $aktor): array
    {
        $nama = is_string($nama) ? trim($nama) : '';

        if ($nama === '' || mb_strlen($nama) > self::NAMA_MAKS) {
            throw ValidationException::forField('nama', 'Nama wajib diisi (maks ' . self::NAMA_MAKS . ' karakter).');
        }

        $kol = $this->jenis->kolomNama();
        $pk  = $this->jenis->kolomId();
        $ada = $this->db->table($this->jenis->tabel())->where($kol, $nama)->where('status', 1);

        if ($id !== null) {
            $ada->where("{$pk} !=", $id);
        }

        if ($id !== null) {
            $lama = $this->ambil($id);

            if ((int) $lama['status'] === 10) {
                throw new ApiException('Data yang dihapus harus dipulihkan dulu sebelum diubah.', 409);
            }
        }

        if ($ada->countAllResults() > 0) {
            throw ValidationException::forField('nama', 'Nama sudah dipakai.');
        }

        $data = [$kol => $nama];

        if ($this->jenis->punyaAllowAp()) {
            $data['allow_ap'] = in_array($allowAp, [1, '1', true], true) ? 1 : 2; // DBV-032: 1 = role 3 boleh, 2 = tidak
        }

        $model = $this->jenis->model();

        if ($id === null) {
            $maks = (int) ($this->db->table($this->jenis->tabel())->selectMax('order', 'm')->get()->getRow()->m ?? 0);
            $id   = (int) $model->insert($data + ['order' => $maks + 1, 'status' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'created_by' => $aktor, 'updated_by' => $aktor], true);
        } else {
            $model->update($id, $data + ['updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $aktor]);
        }

        return $this->baris($this->ambil($id));
    }

    /**
     * Aktifkan (1), nonaktifkan (2), pulihkan (1 dari 10). Mengaktifkan nama yang sudah dipakai baris aktif lain → 422.
     */
    public function setStatus(int $id, mixed $status, int $aktor): void
    {
        if (! in_array((string) $status, ['1', '2'], true)) {
            throw ValidationException::forField('status', 'Status harus 1 atau 2.');
        }

        $r = $this->ambil($id);

        if ((string) $status === '1' && (int) $r['status'] !== 1) {
            $bentrok = $this->db->table($this->jenis->tabel())->where($this->jenis->kolomNama(), $r[$this->jenis->kolomNama()])->where('status', 1)->where($this->jenis->kolomId() . ' !=', $id)->countAllResults();

            if ($bentrok > 0) {
                throw ValidationException::forField('nama', 'Nama sudah dipakai data aktif lain.');
            }
        }

        $this->jenis->model()->update($id, ['status' => (int) $status, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $aktor]);
    }

    public function geser(int $id, mixed $arah, int $aktor): void
    {
        if (! in_array($arah, ['naik', 'turun'], true)) {
            throw ValidationException::forField('arah', 'Arah harus naik atau turun.');
        }

        $this->ambil($id);
        $pk  = $this->jenis->kolomId();
        $ids = array_map('intval', array_column(
            $this->db->table($this->jenis->tabel())->select($pk)->where('status !=', 10)->orderBy('order', 'ASC')->orderBy($pk, 'ASC')->get()->getResultArray(),
            $pk,
        ));

        $idx = array_search($id, $ids, true);
        $tgt = $arah === 'naik' ? (int) $idx - 1 : (int) $idx + 1;

        if ($idx === false || ! isset($ids[$tgt])) {
            return;
        }

        [$ids[$idx], $ids[$tgt]] = [$ids[$tgt], $ids[$idx]];
        $model                   = $this->jenis->model();

        $this->db->transStart();

        foreach ($ids as $pos => $kid) {
            $model->update($kid, ['order' => $pos + 1, 'updated_by' => $aktor]);
        }

        $this->db->transComplete();
    }

    /**
     * Hapus lunak (status 10). Ditolak bila masih dipakai (FK RESTRICT dihormati lewat pemeriksaan aplikasi).
     */
    public function hapus(int $id, int $aktor): void
    {
        $this->ambil($id);

        if ($this->dipakai($id)) {
            throw new ApiException($this->jenis->label() . ' masih dipakai dan tidak dapat dihapus. Nonaktifkan saja.', 409);
        }

        $this->jenis->model()->update($id, ['status' => 10, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $aktor]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->db->table($this->jenis->tabel())->where($this->jenis->kolomId(), $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw new NotFoundException($this->jenis->label() . ' tidak ditemukan.');
        }

        return $r;
    }

    private function dipakai(int $id): bool
    {
        return match ($this->jenis) {
            JenisMaster::BeritaKategori => $this->db->table('news')->where('id_news_category', $id)->countAllResults() > 0,
            JenisMaster::DiboJenis      => $this->db->table('dibo')->where('id_jenis_dibo', $id)->where('status !=', 10)->countAllResults() > 0,
        };
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{id: int, nama: string, urutan: int, status: int, allow_ap: int}
     */
    private function baris(array $r): array
    {
        return [
            'id'       => (int) $r[$this->jenis->kolomId()],
            'nama'     => (string) $r[$this->jenis->kolomNama()],
            'urutan'   => (int) $r['order'],
            'status'   => (int) $r['status'],
            'allow_ap' => (int) ($r['allow_ap'] ?? 2),
        ];
    }
}
