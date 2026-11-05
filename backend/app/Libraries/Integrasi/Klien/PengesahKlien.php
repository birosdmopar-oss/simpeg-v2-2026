<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use CodeIgniter\Database\BaseConnection;

/**
 * MAKE-058 — pengesah klien layanan (HTTP Basic, PRD Fase 6 §7.0.1; AC-F.1..F.3). Urutan: cari username → SELALU
 * `password_verify` (hash tiruan KONSTAN bila username tak dikenal, supaya waktu respons tidak membedakan) → status 1 →
 * allowlist IP → hak. Semua kegagalan dilaporkan ke pemanggil sebagai SATU alasan internal (untuk log); respons ke klien
 * seragam "Authentication failed". Rahasia tidak pernah dicatat.
 */
final class PengesahKlien
{
    public const DITERIMA    = 'diterima';
    public const TIDAK_SAH   = 'tidak_sah';      // username tak dikenal atau rahasia salah
    public const TIDAK_AKTIF = 'tidak_aktif';    // status 2/10
    public const IP_DITOLAK  = 'ip_ditolak';
    public const HAK_KURANG  = 'hak_kurang';

    /**
     * Hash tiruan KONSTAN (rahasia acak yang dibuang) dengan parameter bawaan yang sama dengan
     * {@see PembangkitKredensial::hashRahasia()}: `password_verify` untuk username tak dikenal memakan waktu sama dengan klien
     * nyata, tanpa membuat hash baru per request (review MAKE-058 P-1). Test memastikan parameternya tidak menyimpang dari
     * bawaan PHP.
     */
    public const HASH_TIRUAN_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$SHNRRzlCUTFhOEJJUzV6Qw$sIv1ujycvQFV9MLWSC2QsjdVsc0woDt27B2lStzFD4g';

    public const HASH_TIRUAN_BCRYPT = '$2y$12$Rhgsm6FMezOti0zcDkNoOeEHyO6AnrMAXfuTi64z9XstzLxkR9IEW';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{hasil: string, id_klien: int|null, jenis: int|null}
     */
    public function periksa(#[\SensitiveParameter] string $username, #[\SensitiveParameter] string $rahasia, #[\SensitiveParameter] string $ip, string $hak): array
    {
        $klien = $username === '' ? null : $this->db->table('klien_layanan')
            ->select('id_klien, rahasia_hash, jenis, hak, ip_allowlist, status')
            ->where('username', $username)->limit(1)->get()->getRowArray();

        $cocok = password_verify($rahasia, $klien === null ? self::hashTiruan() : (string) $klien['rahasia_hash']);

        if ($klien === null || ! $cocok) {
            return ['hasil' => self::TIDAK_SAH, 'id_klien' => $klien === null ? null : (int) $klien['id_klien'], 'jenis' => null];
        }

        $id    = (int) $klien['id_klien'];
        $jenis = (int) $klien['jenis'];

        if ((int) $klien['status'] !== PenyimpanKlien::STATUS_AKTIF) {
            return ['hasil' => self::TIDAK_AKTIF, 'id_klien' => $id, 'jenis' => null];
        }

        if (! AllowlistIp::cocok($ip, $klien['ip_allowlist'] === null ? null : (string) $klien['ip_allowlist'])) {
            return ['hasil' => self::IP_DITOLAK, 'id_klien' => $id, 'jenis' => null];
        }

        if (! in_array($hak, HakKlien::dariKolom((string) $klien['hak']), true)) {
            return ['hasil' => self::HAK_KURANG, 'id_klien' => $id, 'jenis' => null];
        }

        return ['hasil' => self::DITERIMA, 'id_klien' => $id, 'jenis' => $jenis];
    }

    public static function hashTiruan(): string
    {
        return defined('PASSWORD_ARGON2ID') ? self::HASH_TIRUAN_ARGON2ID : self::HASH_TIRUAN_BCRYPT;
    }
}
