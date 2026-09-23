<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * G-08 — Master Hari Libur (+ master jenis libur lewat engine generik).
 * Legacy: hr/presensi/holiday. Role: CRUD = 1; daftar hari libur aktif (`calendar`) = UL_ALL, dipakai kalender
 * presensi & kalkulasi hari kerja Fase 5.
 *
 * GET    api/v1/master/hari-libur?search=&status=&id_jenis_libur=&tahun=&page=&per_page=
 * GET    api/v1/master/hari-libur/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD   (UL_ALL, hanya yang aktif)
 * POST   api/v1/master/hari-libur           { id_jenis_libur, nama, tgl_mulai, tgl_akhir, keterangan?, status? } → 201
 * GET    api/v1/master/hari-libur/{id}
 * PUT    api/v1/master/hari-libur/{id}
 * PATCH  api/v1/master/hari-libur/{id}/status  { status: '0'|'1' }
 * DELETE api/v1/master/hari-libur/{id}       (soft delete → status '0')
 *
 * Validasi khusus (DoD G-08, di HariLiburService): tgl_mulai <= tgl_akhir dan rentang tidak boleh overlap
 * dengan hari libur aktif lain.
 */
class HariLiburController extends BaseMasterController
{
    protected array $entities = ['jenis-libur'];

    public function liburIndex(): ResponseInterface
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->request->getGet();

        return $this->respondSuccess(service('hariLiburService')->list($filters));
    }

    public function liburShow(string $id): ResponseInterface
    {
        return $this->respondSuccess(service('hariLiburService')->get((int) $id));
    }

    /**
     * Hari libur aktif dalam rentang tanggal (default: tahun berjalan).
     */
    public function calendar(): ResponseInterface
    {
        $data = $this->validateOrFail($this->request->getGet(), [
            'from' => 'permit_empty|valid_date[Y-m-d]',
            'to'   => 'permit_empty|valid_date[Y-m-d]',
        ]);

        $from = (string) ($data['from'] ?? date('Y') . '-01-01');
        $to   = (string) ($data['to'] ?? date('Y') . '-12-31');

        return $this->respondSuccess(service('hariLiburService')->between($from, $to));
    }

    public function liburCreate(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), $this->liburRules(true));

        return $this->respondSuccess(service('hariLiburService')->create($data), 201);
    }

    public function liburUpdate(string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), $this->liburRules(false));

        return $this->respondSuccess(service('hariLiburService')->update((int) $id, $data));
    }

    public function liburSetStatus(string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), ['status' => 'required|in_list[0,1]']);

        return $this->respondSuccess(service('hariLiburService')->setStatus((int) $id, (string) $data['status']));
    }

    public function liburDelete(string $id): ResponseInterface
    {
        $row = service('hariLiburService')->delete((int) $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => true, 'item' => $row]);
    }

    /**
     * @return array<string, string>
     */
    private function liburRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'if_exist|required';

        return [
            'id_jenis_libur' => "{$required}|max_length[5]",
            'nama'           => "{$required}|string|max_length[150]",
            'tgl_mulai'      => "{$required}|valid_date[Y-m-d]",
            'tgl_akhir'      => "{$required}|valid_date[Y-m-d]",
            'keterangan'     => 'permit_empty|string|max_length[255]',
            'status'         => 'permit_empty|in_list[0,1]',
        ];
    }
}
