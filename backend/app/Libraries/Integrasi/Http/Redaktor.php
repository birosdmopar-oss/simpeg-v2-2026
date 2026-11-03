<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

/**
 * Redaksi teks sebelum masuk log/DB/respons (BP-5, AC-K.1): nilai parameter/kunci JSON/header sensitif, token Bearer/Basic,
 * nilai literal tambahan (mis. passphrase & NIK BSrE, F6-17 — juga bentuk ter-encode URL dan JSON `\/`), dan angka 18
 * digit (bentuk NIP; body galat layanan luar bisa memuat NIP) diganti `***`. Teks hasil dipotong maksimal
 * {@see self::MAKS} karakter.
 */
final class Redaktor
{
    public const MAKS = 1000;

    public const SENSOR = '***';

    /** Nama kunci (huruf kecil) yang nilainya selalu disensor, di query string, form, JSON, maupun header. */
    public const KUNCI_SENSITIF = [
        'passphrase', 'password', 'pass', 'pwd', 'secret', 'client_secret', 'token', 'access_token', 'refresh_token',
        'id_token', 'authorization', 'auth', 'nik', 'otp', 'totp', 'api_key', 'apikey', 'x-api-key', 'private_key',
        'username', 'client_id',
    ];

    /**
     * @param list<string> $rahasia nilai literal tambahan (kosong diabaikan; nilai < 4 karakter tidak disensor literal agar
     *                              tidak merusak teks umum — kunci sensitif tetap tersensor)
     */
    public function teks(string $teks, #[\SensitiveParameter] array $rahasia = []): string
    {
        foreach ($rahasia as $nilai) {
            if (mb_strlen($nilai) >= 4) {
                $json = substr((string) json_encode($nilai, JSON_UNESCAPED_UNICODE), 1, -1);
                $teks = str_replace(array_values(array_unique([$nilai, $json, rawurlencode($nilai), urlencode($nilai)])), self::SENSOR, $teks);
            }
        }

        $kunci = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), self::KUNCI_SENSITIF));

        // query string / form: kunci=nilai
        $teks = (string) preg_replace('/((?:^|[?&;\s])(?:' . $kunci . ')=)[^&\s"\']*/i', '$1' . self::SENSOR, $teks);
        // JSON: "kunci": "nilai" | "kunci": angka
        $teks = (string) preg_replace('/("(?:' . $kunci . ')"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|[^,}\]\s]+)/i', '$1"' . self::SENSOR . '"', $teks);
        // header: Kunci: nilai
        $teks = (string) preg_replace('/(^|\n)((?:' . $kunci . ')\s*:\s*)[^\r\n]*/i', '$1$2' . self::SENSOR, $teks);
        // skema token di mana pun
        $teks = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=\\\\]+/i', '$1 ' . self::SENSOR, $teks);
        // bentuk NIP (18 digit) — jangan bocor lewat body galat
        $teks = (string) preg_replace('/(?<!\d)\d{18}(?!\d)/', self::SENSOR, $teks);

        return mb_strlen($teks) > self::MAKS ? mb_substr($teks, 0, self::MAKS) : $teks;
    }
}
