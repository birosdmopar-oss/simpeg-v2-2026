<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\StatistikChatbot;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Statistik dan riwayat chatbot (role 1, F8-42). Aturan di {@see StatistikChatbot}.
 */
final class StatistikController extends ChatbotController
{
    public function statistik(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new StatistikChatbot())->statistik()));
    }

    public function riwayat(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new StatistikChatbot())->riwayat($this->query())));
    }
}
