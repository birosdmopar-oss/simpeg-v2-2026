<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\MasterChatbotService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Basis admin kategori/tag QnA chatbot (role 1, F8-32). Aturan di {@see MasterChatbotService}; subclass hanya menentukan jenis.
 */
abstract class MasterController extends ChatbotController
{
    abstract protected function jenis(): string;

    public function index(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->daftar($this->query())));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->buat($this->payload()), 201);
    }

    public function show(int|string $id): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->detail($this->idValid($id))));
    }

    public function update(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah($this->idValid($id), $this->payload()));
    }

    public function status(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->setStatus($this->idValid($id), $this->payload()['status'] ?? null));
    }

    public function delete(int|string $id): ResponseInterface
    {
        $this->service()->hapus($this->idValid($id));

        return $this->respondSuccess(null);
    }

    private function service(): MasterChatbotService
    {
        return new MasterChatbotService($this->jenis());
    }
}
