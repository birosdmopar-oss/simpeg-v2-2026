<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\PushPlatformInterface;
use CodeIgniter\Database\BaseConnection;

/**
 * Pengirim push bersama job antrean `push` (PRD Fase 6 §7.4, MAKE-066): token dari {@see PenerimaTokenFcm} dikelompokkan per
 * platform lalu dikirim lewat gateway (bentuk pesan per kanal bila gateway mendukung {@see PushPlatformInterface}).
 * Token yang dilaporkan tidak sah dihapus menurut `token_hash` + `platform` (semua pemilik; token yang ditolak FCM tidak sah
 * untuk siapa pun — CR-099 S3; legacy tidak membersihkan, B-11), sehingga tidak di-retry selamanya. Isi token tidak pernah
 * dicatat.
 */
final class PengirimPushFcm
{
    public function __construct(private readonly PushNotifGatewayInterface $gateway, private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<array{id: int, token: string, platform: int}> $penerima
     * @param array<string, string>                              $data
     *
     * @return array{sukses: int, gagal: int, dihapus: int, pesan: string}
     */
    public function kirim(array $penerima, string $judul, string $isi, array $data = []): array
    {
        $hasil = ['sukses' => 0, 'gagal' => 0, 'dihapus' => 0, 'pesan' => ''];
        $grup  = [];

        foreach ($penerima as $p) {
            $grup[$p['platform']][$p['token']] = $p['id'];
        }

        ksort($grup);

        foreach ($grup as $platform => $idPerToken) {
            $tokens = array_map('strval', array_keys($idPerToken));
            $r      = $this->gateway instanceof PushPlatformInterface
                ? $this->gateway->sendMulticastPlatform($platform, $tokens, $judul, $isi, $data)
                : $this->gateway->sendMulticast($tokens, $judul, $isi, $data);

            $hasil['sukses'] += $r['success_count'];
            $hasil['gagal'] += $r['failure_count'];
            $hasil['pesan'] = $r['message'];

            $hash = array_values(array_unique(array_map(static fn (string $t): string => hash('sha256', $t), array_values(array_intersect($r['invalid_tokens'], $tokens)))));

            if ($hash !== []) {
                $this->db->table('firebase_token')->where('platform', $platform)->whereIn('token_hash', $hash)->delete();
                $hasil['dihapus'] += $this->db->affectedRows();
            }
        }

        return $hasil;
    }
}
