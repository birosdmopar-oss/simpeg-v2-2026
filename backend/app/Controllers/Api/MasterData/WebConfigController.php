<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Controllers\Api\ApiController;
use App\Models\MasterData\WebConfigModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * G-09 — Web Config (key-value bertipe). Legacy: hr/master/c_web/* (role 1).
 *
 * GET    api/v1/master/web-config?search=&tipe_data=
 * GET    api/v1/master/web-config/{config_name}
 * POST   api/v1/master/web-config    { config_name, config_value, tipe_data?, keterangan? } → 201
 * PUT    api/v1/master/web-config/{config_name}   { config_value?, tipe_data?, keterangan? }  (key tidak bisa diubah)
 * DELETE api/v1/master/web-config/{config_name}   (hard delete — web_config tidak punya kolom status)
 *
 * Nilai divalidasi sesuai `tipe_data` (integer/decimal/time/boolean/string/text) dan dikembalikan ter-cast di
 * `value_casted`. Perubahan langsung ter-reflect (cache di-invalidate); regression test bersama kalkulasi
 * tukin/uang makan menyusul di Fase 5 sesuai DoD.
 */
class WebConfigController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(): ResponseInterface
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->request->getGet();

        return $this->respondSuccess(service('webConfigService')->list($filters));
    }

    public function show(string $name): ResponseInterface
    {
        return $this->respondSuccess(service('webConfigService')->get($name));
    }

    public function create(): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'config_name'  => 'required|string|max_length[100]',
            'config_value' => 'required',
            'tipe_data'    => 'permit_empty|in_list[' . implode(',', WebConfigModel::TYPES) . ']',
            'keterangan'   => 'permit_empty|string|max_length[255]',
        ]);

        return $this->respondSuccess(service('webConfigService')->create($data), 201);
    }

    public function update(string $name): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'config_value' => 'if_exist|required',
            'tipe_data'    => 'if_exist|required|in_list[' . implode(',', WebConfigModel::TYPES) . ']',
            'keterangan'   => 'permit_empty|string|max_length[255]',
        ]);

        return $this->respondSuccess(service('webConfigService')->update($name, $data));
    }

    public function delete(string $name): ResponseInterface
    {
        service('webConfigService')->delete($name);

        return $this->respondSuccess(['deleted' => true]);
    }
}
