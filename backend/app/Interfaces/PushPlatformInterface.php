<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak tambahan gateway push per platform (PRD Fase 6 §7.4 F6-13, MAKE-066) — BP-1: kontrak
 * {@see PushNotifGatewayInterface} tidak diubah, kebutuhan baru = interface baru. Kanal legacy punya bentuk pesan berbeda:
 *
 * - web/desktop (`fb_token`, [K `Cron::process_queue_fcm_desktop` C:1138]): notifikasi + ikon, `data.link` dibuka service
 *   worker saat diklik [K `html/firebase-messaging-sw.js`];
 * - Android (`firebase_token`, [K `Cron::process_queue_andr_notif` C:987]): prioritas tinggi, suara bawaan,
 *   `click_action` = judul.
 *
 * Kembalian sama dengan {@see PushNotifGatewayInterface::sendMulticast()}.
 */
interface PushPlatformInterface
{
    public const WEB = 1;

    public const ANDROID = 2;

    /**
     * @param int                   $platform     {@see self::WEB} | {@see self::ANDROID}
     * @param list<string>          $deviceTokens
     * @param array<string, string> $data
     *
     * @return array{status_code: string, message: string, success_count: int, failure_count: int, invalid_tokens: list<string>}
     */
    public function sendMulticastPlatform(int $platform, array $deviceTokens, string $title, string $body, array $data = []): array;
}
