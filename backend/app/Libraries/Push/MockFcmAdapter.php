<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\PushPlatformInterface;

/**
 * Mock adapter FCM (F0-17). Payload push ditulis ke log lokal (writable/logs) — tidak ada koneksi jaringan.
 */
class MockFcmAdapter implements PushNotifGatewayInterface, PushPlatformInterface
{
    public const LOG_PREFIX = '[MockFcmAdapter]';

    /**
     * @var list<array<string, mixed>>
     */
    private array $sent = [];

    private ?int $platform = null;

    public function send(string $deviceToken, string $title, string $body, array $data = []): array
    {
        if ($deviceToken === '') {
            return ['status_code' => '400', 'message' => 'Device token kosong (mock)', 'message_id' => null];
        }

        $messageId = 'mock-msg-' . bin2hex(random_bytes(6));
        $payload   = ['token' => $deviceToken, 'title' => $title, 'body' => $body, 'data' => $data, 'message_id' => $messageId, 'platform' => $this->platform];

        $this->sent[] = $payload;

        log_message('info', self::LOG_PREFIX . ' push payload={payload}', [
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return ['status_code' => '200', 'message' => 'Terkirim (mock)', 'message_id' => $messageId];
    }

    public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        $success = 0;
        $invalid = [];

        foreach ($deviceTokens as $token) {
            $result = $this->send($token, $title, $body, $data);

            if ($result['status_code'] === '200') {
                $success++;
            } else {
                $invalid[] = $token;
            }
        }

        return [
            'status_code'    => '200',
            'message'        => sprintf('Multicast %d sukses, %d gagal (mock)', $success, count($invalid)),
            'success_count'  => $success,
            'failure_count'  => count($invalid),
            'invalid_tokens' => $invalid,
        ];
    }

    /**
     * Platform dicatat di kunci `platform` payload terkirim (hanya mock; `null` = tanpa platform) supaya test consumer bisa
     * memeriksa kanal tanpa mengubah `data`.
     */
    public function sendMulticastPlatform(int $platform, array $deviceTokens, string $title, string $body, array $data = []): array
    {
        $this->platform = $platform;

        try {
            return $this->sendMulticast($deviceTokens, $title, $body, $data);
        } finally {
            $this->platform = null;
        }
    }

    /**
     * Seluruh payload yang "terkirim" — untuk assert di test consumer.
     *
     * @return list<array<string, mixed>>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
