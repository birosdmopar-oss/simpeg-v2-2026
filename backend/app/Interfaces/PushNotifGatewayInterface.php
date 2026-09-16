<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak gateway push notification FCM (F0-17, ADR-015).
 * Dispatch WAJIB async lewat Queue (ADR-013) — consumer tidak memanggil send() langsung dari request user.
 * Implementasi: MockFcmAdapter (Fase 0-5), adapter real FCM (Fase 6).
 *
 * Token device yang invalid harus dilaporkan (invalid_tokens) agar consumer menghapusnya, bukan retry selamanya.
 */
interface PushNotifGatewayInterface
{
    /**
     * @param array<string, string> $data payload data tambahan (key-value string)
     *
     * @return array{status_code: string, message: string, message_id: string|null}
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): array;

    /**
     * @param list<string>          $deviceTokens
     * @param array<string, string> $data
     *
     * @return array{status_code: string, message: string, success_count: int, failure_count: int, invalid_tokens: list<string>}
     */
    public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array;
}
