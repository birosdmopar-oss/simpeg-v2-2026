<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;
use Throwable;

/**
 * MAKE-058 — token SIMAPI (PRD Fase 6 F6-05, DBV-026 §13.2 A4; AC-F.5). Token opak `[A-Za-z0-9]` dibuat dari `random_int`,
 * disimpan sebagai SHA-256 heks huruf kecil di PK `simapi_token.token_hash` (token mentah tidak pernah disimpan atau
 * dicatat), satu baris per handshake, masa aktif {@see \Config\KlienLayanan::$tokenTtlDetik}.
 *
 * Terbit: dalam satu transaksi yang mengunci baris klien (`FOR UPDATE`) dan memeriksa ulang `status = 1` DAN sidik rahasia
 * (`PembangkitKredensial::sidik` dari `rahasia_hash`, dibawa filter lewat `KlienSaatIni`). Handshake yang berlomba dengan
 * `nonaktif`/`hapus` (status berubah) atau `rotasi` (rahasia berubah, status tetap 1) tidak menerbitkan token untuk klien yang
 * baru dicabut atau yang rahasianya sudah diganti (review S-3).
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
     * Terbitkan token baru untuk klien aktif (handshake). Token mentah dikembalikan SEKALI ke pemanggil.
     *
     * @return array{token: string, expired_at: string}
     *
     * @param string $sidikRahasia sidik rahasia yang diverifikasi pengesah pada request ini
     *
     * @throws KlienTidakAktif klien tidak ada, bukan status 1, atau rahasianya berubah sejak diverifikasi (rotasi saat handshake berjalan)
     */
    public function terbitkan(int $idKlien, string $sidikRahasia): array
    {
        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();

            throw new RuntimeException('Transaksi token SIMAPI tidak dapat dimulai.');
        }

        try {
            $sql   = $this->db->table('klien_layanan')->select('status, rahasia_hash')->where('id_klien', $idKlien)->limit(1)->getCompiledSelect() . ' FOR UPDATE';
            $klien = $this->db->query($sql)->getRowArray();

            if ($klien === null || (int) $klien['status'] !== PenyimpanKlien::STATUS_AKTIF || ! hash_equals(PembangkitKredensial::sidik((string) $klien['rahasia_hash']), $sidikRahasia)) {
                throw new KlienTidakAktif('Klien tidak aktif atau rahasia berubah; token tidak diterbitkan.');
            }

            $hasil = $this->sisipToken($idKlien);

            if ($this->db->transStatus() === false || $this->db->transCommit() === false) {
                throw new RuntimeException('Token SIMAPI gagal diterbitkan (transaksi).');
            }

            $this->db->resetTransStatus();

            return $hasil;
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }
    }

    /**
     * Token dari nilai header `Authorization` ("<skema> <token>"), atau null bila bentuknya tidak sah. Tanpa DB.
     */
    public function ekstrak(#[\SensitiveParameter] string $header): ?string
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
     * @return array{id_klien: int, jenis: int, ip_allowlist: string|null}|null klien pemilik token yang masih berlaku, atau null (tidak sah/kedaluwarsa/dicabut/klien nonaktif)
     */
    public function periksa(#[\SensitiveParameter] string $header): ?array
    {
        $token = $this->ekstrak($header);

        if ($token === null) {
            return null;
        }

        $hash  = PembangkitKredensial::hashToken($token);
        $baris = $this->db->table('simapi_token t')
            ->select('t.token_hash, t.id_klien, k.jenis, k.hak, k.ip_allowlist')
            ->join('klien_layanan k', 'k.id_klien = t.id_klien')
            ->where('t.token_hash', $hash)->where('t.status', 1)->where('t.expired_at > UTC_TIMESTAMP()', null, false)->where('k.status', 1)
            ->get()->getRowArray();

        if ($baris === null || ! hash_equals($hash, (string) $baris['token_hash'])) {
            return null;
        }

        if (! in_array(HakKlien::SIMAPI, HakKlien::dariKolom((string) $baris['hak']), true)) {
            return null;
        }

        return ['id_klien' => (int) $baris['id_klien'], 'jenis' => (int) $baris['jenis'], 'ip_allowlist' => $baris['ip_allowlist'] === null ? null : (string) $baris['ip_allowlist']];
    }

    /**
     * @return array{token: string, expired_at: string}
     */
    private function sisipToken(int $idKlien): array
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
                        $this->db->resetTransStatus();

                        continue;
                    }

                    throw new RuntimeException('Token SIMAPI gagal disimpan.');
                }

                return ['token' => $token, 'expired_at' => $kedaluwarsa];
            } catch (DatabaseException $e) {
                if ((int) $e->getCode() !== self::ER_DUP_ENTRY) {
                    throw $e;
                }

                $this->db->resetTransStatus();
            }
        }

        throw new RuntimeException('Token SIMAPI gagal diterbitkan (tabrakan hash berulang).');
    }
}
