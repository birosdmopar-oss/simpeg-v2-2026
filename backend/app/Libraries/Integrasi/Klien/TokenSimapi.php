<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;

/**
 * MAKE-058 — token SIMAPI (PRD Fase 6 F6-05, DBV-026 §13.2 A4; AC-F.5). Token opak `[A-Za-z0-9]` dibuat dari `random_int`,
 * disimpan sebagai SHA-256 heks huruf kecil di PK `simapi_token.token_hash` (token mentah tidak pernah disimpan atau
 * dicatat), satu baris per handshake, masa aktif {@see \Config\KlienLayanan::$tokenTtlDetik}.
 *
 * Validasi: nilai header `Authorization` dipecah spasi dan token = ELEMEN KEDUA [K `AE:30-47`] (skema bebas); bentuk token
 * (panjang tetap + karakter) dan panjang header dibatasi SEBELUM di-hash; pencarian lewat PK `token_hash`, lalu
 * `status = 1 AND expired_at > UTC_TIMESTAMP()`, klien `status = 1` dan berhak `simapi`; hash dibandingkan lagi dengan
 * `hash_equals`.
 */
final class TokenSimapi
{
    private const ER_DUP_ENTRY = 1062;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly int $panjang = 30,
        private readonly int $ttlDetik = 3600,
        private readonly int $headerMaks = 512,
    ) {
    }

    /**
     * Terbitkan token baru untuk klien (handshake). Token mentah dikembalikan SEKALI ke pemanggil.
     *
     * @return array{token: string, expired_at: string}
     */
    public function terbitkan(int $idKlien): array
    {
        $kedaluwarsa = gmdate('Y-m-d H:i:s', time() + $this->ttlDetik);

        for ($percobaan = 0; $percobaan < 3; $percobaan++) {
            $token = PembangkitKredensial::token($this->panjang);

            try {
                $ok = $this->db->table('simapi_token')->insert([
                    'token_hash' => PembangkitKredensial::hashToken($token),
                    'id_klien'   => $idKlien,
                    'status'     => 1,
                    'expired_at' => $kedaluwarsa,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);

                if ($ok === false) {
                    // Koneksi tanpa DBDebug: insert mengembalikan false alih-alih melempar.
                    if ((int) ($this->db->error()['code'] ?? 0) === self::ER_DUP_ENTRY) {
                        continue;
                    }

                    throw new RuntimeException('Token SIMAPI gagal disimpan.');
                }

                return ['token' => $token, 'expired_at' => $kedaluwarsa];
            } catch (DatabaseException $e) {
                if ((int) $e->getCode() !== self::ER_DUP_ENTRY) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Token SIMAPI gagal diterbitkan (tabrakan hash berulang).');
    }

    /**
     * Token dari nilai header `Authorization` ("<skema> <token>"), atau null bila bentuknya tidak sah. Tanpa DB.
     */
    public function ekstrak(string $header): ?string
    {
        if ($header === '' || strlen($header) > $this->headerMaks) {
            return null;
        }

        $bagian = preg_split('/\s+/', trim($header));

        if ($bagian === false || count($bagian) < 2) {
            return null;
        }

        $token = $bagian[1];

        return strlen($token) === $this->panjang && preg_match('/^[A-Za-z0-9]+$/', $token) === 1 ? $token : null;
    }

    /**
     * @return array{id_klien: int, jenis: int}|null klien pemilik token yang masih berlaku, atau null (tidak sah/kedaluwarsa/dicabut)
     */
    public function periksa(string $header): ?array
    {
        $token = $this->ekstrak($header);

        if ($token === null) {
            return null;
        }

        $hash  = PembangkitKredensial::hashToken($token);
        $baris = $this->db->table('simapi_token t')
            ->select('t.token_hash, t.id_klien, k.jenis, k.hak')
            ->join('klien_layanan k', 'k.id_klien = t.id_klien')
            ->where('t.token_hash', $hash)->where('t.status', 1)->where('t.expired_at > UTC_TIMESTAMP()', null, false)->where('k.status', 1)
            ->get()->getRowArray();

        if ($baris === null || ! hash_equals($hash, (string) $baris['token_hash'])) {
            return null;
        }

        if (! in_array(HakKlien::SIMAPI, HakKlien::dariKolom((string) $baris['hak']), true)) {
            return null;
        }

        return ['id_klien' => (int) $baris['id_klien'], 'jenis' => (int) $baris['jenis']];
    }
}
