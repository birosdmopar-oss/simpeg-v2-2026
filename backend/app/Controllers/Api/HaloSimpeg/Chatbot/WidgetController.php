<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\ChatbotService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Widget Vanya untuk role 2/6/7 (F8-30): bubble topik/pertanyaan, populer, balas, feedback. Aturan di {@see ChatbotService}.
 */
final class WidgetController extends ChatbotController
{
    public function kategori(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new ChatbotService())->kategori(service('authContext'))));
    }

    public function pertanyaan(int|string $id): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new ChatbotService())->pertanyaan(service('authContext'), (string) $id)));
    }

    public function populer(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new ChatbotService())->populer(service('authContext'))));
    }

    public function balas(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new ChatbotService())->balas(service('authContext'), $this->payload())));
    }

    public function feedback(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new ChatbotService())->feedback(service('authContext'), $this->payload())));
    }
}
