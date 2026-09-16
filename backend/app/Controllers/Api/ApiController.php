<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base controller seluruh endpoint API.
 * Envelope response mengikuti ADR-001:
 *   sukses : {status: 'success', data}
 *   gagal  : {status: 'error', message, errors?}
 */
abstract class ApiController extends BaseController
{
    protected function respondSuccess(mixed $data = null, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setJSON(['status' => 'success', 'data' => $data]);
    }

    /**
     * @param array<string, mixed>|null $errors
     */
    protected function respondError(string $message, int $status = 400, ?array $errors = null): ResponseInterface
    {
        $body = ['status' => 'error', 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }
}
