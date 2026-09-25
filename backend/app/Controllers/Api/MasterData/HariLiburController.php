<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * G-08 — Hari Libur (DBV-003/CR-010). Legacy: hr/presensi/holiday (Presensi.php, L_presensi.php). Logika di
 * App\Libraries\MasterData\HariLiburService (controller tipis, ADR-002).
 *
 *   GET    api/v1/hari-libur?tahun=&search=&status=&page=&per_page=  (role 1/4/5/8; role 4/5/8 hanya status 1)
 *   GET    api/v1/hari-libur/{id}                                     (role 1/4/5/8; role 4/5/8 hanya status 1)
 *   POST   api/v1/hari-libur          { tgl_mulai, tgl_akhir, id_jenis_libur, nama_libur, keterangan?, status? } → 201
 *   PUT    api/v1/hari-libur/{id}     (parsial, field sama)                                                  (role 1)
 *   PATCH  api/v1/hari-libur/{id}/status { status: '1'|'2' }  (juga memulihkan status 10)                   (role 1)
 *   DELETE api/v1/hari-libur/{id}     soft delete → status 10                                               (role 1)
 * 422: tanggal tidak valid, selesai < mulai, jenis tidak ada/non-aktif, rentang bentrok dengan hari libur lain
 * berstatus apa pun. 409: penulisan lain sedang berjalan (named lock) — coba lagi.
 *
 * Master `jenis-libur` = endpoint master generik (role 1; dropdown `master/jenis-libur/options` UL_ALL).
 */
class HariLiburController extends BaseMasterController
{
    protected array $entities = ['jenis-libur'];

    public function liburIndex(): ResponseInterface
    {
        /** @var array<string, mixed> $query */
        $query = $this->request->getGet() ?? [];

        return $this->respondSuccess(service('hariLiburService')->list($query, service('authContext')->role()));
    }

    public function liburShow(string $id): ResponseInterface
    {
        return $this->respondSuccess(service('hariLiburService')->get($id, service('authContext')->role()));
    }

    public function liburCreate(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), $this->liburRules(true));

        return $this->respondSuccess(service('hariLiburService')->create($data), 201);
    }

    public function liburUpdate(string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), $this->liburRules(false));

        return $this->respondSuccess(service('hariLiburService')->update($id, $data));
    }

    public function liburStatus(string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'status' => [
                'rules'  => 'required|in_list[1,2]',
                'errors' => ['required' => 'Status wajib diisi.', 'in_list' => "Status hanya boleh '1' (aktif) atau '2' (tidak aktif)."],
            ],
        ]);

        return $this->respondSuccess(service('hariLiburService')->setStatus($id, (string) $data['status']));
    }

    /**
     * Soft delete (status 10); tidak pernah hard delete. Pulihkan lewat PATCH status.
     */
    public function liburDelete(string $id): ResponseInterface
    {
        return $this->respondSuccess(['deleted' => true, 'soft_delete' => true, 'item' => service('hariLiburService')->delete($id)]);
    }

    /**
     * Validasi bentuk. `valid_date` CI4 menerima `2026-1-1`, jadi format YYYY-MM-DD dipaksa regex dulu (koneksi
     * strictOn=false menyimpan tanggal tidak valid sebagai 0000-00-00). Aturan bisnis (tahun 1900-2100, rentang,
     * jenis aktif, overlap) di HariLiburService.
     *
     * @return array<string, array<string, mixed>>
     */
    private function liburRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'if_exist|required';
        $rules    = [];

        foreach (['tgl_mulai' => 'Tanggal mulai', 'tgl_akhir' => 'Tanggal selesai'] as $field => $label) {
            $rules[$field] = [
                'rules'  => "{$required}|regex_match[/^[0-9]{4}-[0-9]{2}-[0-9]{2}\\z/]|valid_date[Y-m-d]",
                'errors' => [
                    'required'    => "{$label} wajib diisi.",
                    'regex_match' => "{$label} harus tanggal dengan format YYYY-MM-DD.",
                    'valid_date'  => "{$label} tidak valid.",
                ],
            ];
        }

        $rules['id_jenis_libur'] = [
            'rules'  => "{$required}|max_length[3]",
            'errors' => ['required' => 'Jenis libur wajib dipilih.', 'max_length' => 'Jenis Libur tidak ditemukan.'],
        ];
        $rules['nama_libur'] = [
            'rules'  => "{$required}|string|max_length[100]",
            'errors' => [
                'required'   => 'Nama libur wajib diisi.',
                'string'     => 'Nama libur harus teks.',
                'max_length' => 'Nama libur maksimal 100 karakter.',
            ],
        ];
        $rules['keterangan'] = [
            'rules'  => ($creating ? 'permit_empty' : 'if_exist|permit_empty') . '|string|max_byte_length[65535]',
            'errors' => ['string' => 'Keterangan harus teks.', 'max_byte_length' => 'Keterangan maksimal 65.535 byte.'],
        ];
        $rules['status'] = [
            'rules'  => $creating ? 'permit_empty|in_list[1,2]' : 'if_exist|in_list[1,2]',
            'errors' => ['in_list' => "Status hanya boleh '1' (aktif) atau '2' (tidak aktif)."],
        ];

        return $rules;
    }
}
