<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\MasterChatbotService;

/**
 * Admin kategori QnA chatbot (role 1).
 */
final class KategoriController extends MasterController
{
    protected function jenis(): string
    {
        return MasterChatbotService::KATEGORI;
    }
}
