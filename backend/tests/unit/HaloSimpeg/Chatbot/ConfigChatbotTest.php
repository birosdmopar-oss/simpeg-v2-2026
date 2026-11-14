<?php

declare(strict_types=1);

namespace Tests\Unit\HaloSimpeg\Chatbot;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Chatbot;

/**
 * Nilai bawaan `Config\Chatbot` = keputusan PRD Fase 8 F8-34/F8-35/F8-36/F8-37 (review CR W-10b). Mengubah angka ini = keputusan
 * baru, bukan perapian.
 *
 * @internal
 */
final class ConfigChatbotTest extends CIUnitTestCase
{
    public function testNilaiBawaan(): void
    {
        $c = new Chatbot();

        $this->assertSame(
            [0.25, 0.10, 5, 6, 3, 3, 300, 20, 30, 60, 86400, 2 * 1024 * 1024, 1000],
            [$c->ambangCocok, $c->bobotFeedback, $c->smoothingFeedback, $c->maksBubblePertanyaan, $c->jumlahPopuler, $c->kandidatDicatat, $c->maksPesan,
                $c->throttleBalas, $c->throttleFeedback, $c->throttleBaca, $c->masaTokenFeedback, $c->imporMaksByte, $c->imporMaksBaris],
        );
    }
}
