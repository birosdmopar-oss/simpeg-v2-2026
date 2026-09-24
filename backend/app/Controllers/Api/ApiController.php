<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Exceptions\ApiException;
use App\Exceptions\BadRequestException;
use App\Exceptions\ValidationException;
use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base controller seluruh endpoint API.
 * Envelope response mengikuti ADR-001:
 *   sukses : {status: 'success', data}
 *   gagal  : {status: 'error', message, errors?}
 *
 * _remap() menangkap App\Exceptions\ApiException dari method mana pun dan mengubahnya ke envelope,
 * sehingga method controller tetap tipis (ADR-002) dan perilaku sama di feature test maupun runtime.
 */
abstract class ApiController extends BaseController
{
    /**
     * Cast parameter route numerik ke int. Matikan untuk controller yang parameternya kode string
     * (mis. master data: kode '01' tidak boleh berubah jadi 1).
     */
    protected bool $castNumericParams = true;

    /**
     * @param mixed ...$params parameter route (string) yang diteruskan ke method
     */
    public function _remap(string $method, ...$params): mixed
    {
        if (! method_exists($this, $method) || str_starts_with($method, '_')) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Parameter route selalu string; dengan strict_types, cast angka ke int agar cocok dengan signature (int $id).
        if ($this->castNumericParams) {
            $params = array_map(static fn ($p) => is_string($p) && ctype_digit($p) ? (int) $p : $p, $params);
        }

        try {
            return $this->{$method}(...$params);
        } catch (ApiException $e) {
            [$status, $body] = ApiExceptionHandler::toEnvelope($e);

            return $this->response->setStatusCode($status)->setJSON($body);
        }
    }

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

    /**
     * Body JSON request sebagai array (fallback ke form-data).
     * Body yang bukan JSON valid → BadRequestException (400), kecuali request memang dikirim sebagai form.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        try {
            $json = $this->request->getJSON(true);
        } catch (HTTPException) {
            // Body form-urlencoded juga gagal di-parse sebagai JSON; itu bukan error, ambil dari getPost().
            if (! $this->isFormRequest()) {
                throw new BadRequestException('Body JSON tidak valid.');
            }

            $json = null;
        }

        if (is_array($json)) {
            return $json;
        }

        /** @var array<string, mixed> $post */
        $post = $this->request->getPost();

        return $post;
    }

    private function isFormRequest(): bool
    {
        $contentType = strtolower($this->request->getHeaderLine('Content-Type'));

        return str_starts_with($contentType, 'application/x-www-form-urlencoded')
            || str_starts_with($contentType, 'multipart/form-data');
    }

    /**
     * Validasi payload dengan rules CI4; gagal → ValidationException (422, errors per-field).
     *
     * @param array<string, mixed>                     $data
     * @param array<string, array<string, mixed>|string> $rules
     *
     * @return array<string, mixed> data yang lolos validasi (hanya key di rules)
     */
    protected function validateOrFail(array $data, array $rules): array
    {
        $validation = service('validation', null, false);
        $validation->setRules($rules);

        if (! $validation->run($data)) {
            $errors = [];

            foreach ($validation->getErrors() as $field => $message) {
                $errors[$field] = [$message];
            }

            throw new ValidationException('Validasi gagal.', $errors);
        }

        return array_intersect_key($data, $rules);
    }
}
