<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * G-09 — Web Config (DBV-006/CR-030). Legacy: hr/master/c_web (C_web.php, Lm_web.php; `userAuth(['1'])`). Logika di
 * App\Libraries\MasterData\WebConfigService (controller tipis, ADR-002). Semua endpoint role 1 (Matriks Modul G).
 *
 *   GET    api/v1/web-config                 seluruh key katalog (+ key tak dikenal hasil impor)
 *   GET    api/v1/web-config/{config_name}   satu key
 *   PUT    api/v1/web-config/{config_name}   { config_value, remark? } → simpan (tambah baris bila belum ada: 201)
 *   DELETE api/v1/web-config/{config_name}   hapus baris → nilai kembali ke bawaan katalog
 *
 * `config_name` legacy boleh mengandung `/` (mis. `TL1/PSW1`), jadi route memakai (:any) dan segmen digabung lagi di
 * sini. 404: key tidak ada di katalog maupun tabel. 422: nilai tidak sesuai tipe (`errors.config_value`).
 * Tidak ada endpoint baca publik (legacy `services/s_dm/p_web_config` tanpa auth tidak dipindahkan).
 */
class WebConfigController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(): ResponseInterface
    {
        return $this->respondSuccess(service('webConfigService')->list());
    }

    public function show(string ...$segments): ResponseInterface
    {
        return $this->respondSuccess(service('webConfigService')->get(implode('/', $segments)));
    }

    public function update(string ...$segments): ResponseInterface
    {
        $result = service('webConfigService')->set(implode('/', $segments), $this->payload());

        return $this->respondSuccess($result['item'], $result['created'] ? 201 : 200);
    }

    public function delete(string ...$segments): ResponseInterface
    {
        return $this->respondSuccess(['deleted' => true, 'item' => service('webConfigService')->delete(implode('/', $segments))]);
    }
}
