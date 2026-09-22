<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Controllers\Api\ApiController;
use App\Libraries\MasterData\MasterDefinition;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET api/v1/master/meta (role 1) — daftar master yang tersedia + metadata form/tabel untuk halaman
 * Master Data generik di frontend. Sumber: Config\MasterData (satu sumber kebenaran).
 */
class MetaController extends ApiController
{
    public function index(): ResponseInterface
    {
        $items = array_map(
            static fn (MasterDefinition $d): array => $d->toMeta(),
            array_values(service('masterRegistry')->all()),
        );

        return $this->respondSuccess($items);
    }
}
