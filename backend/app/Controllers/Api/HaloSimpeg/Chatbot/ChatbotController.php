<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Basis controller chatbot Vanya (MAKE-094): validasi id path, query, dan respons berkas xlsx. Kontrak: README folder ini.
 */
abstract class ChatbotController extends ApiController
{
    protected function idValid(int|string $id): int
    {
        $s = (string) $id;

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
            throw ValidationException::forField('id', 'ID tidak valid.');
        }

        return (int) $s;
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }

    protected function berkasXlsx(string $nama, string $isi): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $nama . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($isi);
    }

    protected function tanpaCache(ResponseInterface $r): ResponseInterface
    {
        return $r->setHeader('Cache-Control', 'private, no-store');
    }
}
