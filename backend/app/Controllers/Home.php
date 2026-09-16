<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Healthcheck (F0-01). GET / dan GET /api/v1/health mengembalikan HTTP 200 envelope sukses.
 */
class Home extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response->setJSON([
            'status' => 'success',
            'data'   => [
                'app'         => 'SIMPEG v2 API',
                'environment' => ENVIRONMENT,
                'time'        => date(DATE_ATOM),
            ],
        ]);
    }
}
