<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\MasterChatbotService;
use App\Libraries\HaloSimpeg\Chatbot\StatistikChatbot;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin tag QnA chatbot (role 1) + ekspor xlsx daftar tag (F8-47).
 */
final class TagController extends MasterController
{
    public function ekspor(): ResponseInterface
    {
        $hasil = (new StatistikChatbot())->eksporTag($this->request->getGet('format'));

        return $this->berkasXlsx($hasil['nama'], $hasil['isi']);
    }

    protected function jenis(): string
    {
        return MasterChatbotService::TAG;
    }
}
