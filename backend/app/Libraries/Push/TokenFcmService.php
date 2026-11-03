<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Pendaftaran token FCM web/desktop (PRD Fase 6 §7.4, F6-13; MAKE-066) — pengganti `Local::register_fb_token`
 * [K `L:4224-4289`] yang dipanggil SPA setiap halaman dimuat setelah login dan izin notifikasi diberikan.
 *
 * - Kunci PER PEMILIK (keputusan Horii 03-11-2026, DBV-Q8 ikut legacy `fb_token.id_user + fcm_token`): (`id_pengguna`,
 *   `token_hash`) dengan `platform = 1`. Token sama milik akun lain tidak disentuh.
 * - Baru → INSERT dengan `refresh_date` = sekarang + 1 minggu; sudah ada → `refresh_date` diperpanjang 1 minggu hanya bila
 *   sudah lewat [K :4254-4261]. `nip` diisi NIP akun (bila ada) supaya penerima "pegawai" ditemukan per NIP.
 * - Lepas (logout, [V2] — legacy tidak punya): hapus baris token itu milik akun ini saja.
 * - Isi token tidak pernah dikembalikan/dicatat; stempel waktu UTC.
 */
final class TokenFcmService
{
    public const PLATFORM_WEB = 1;

    public const PLATFORM_ANDROID = 2;

    public const PERPANJANG_DETIK = 7 * 86400;

    private const PANJANG_MAKS = 4096;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $masukan `{fcm_token}`
     *
     * @return array{status: 'baru'|'diperpanjang'|'sudah_terdaftar'}
     */
    public function daftar(AuthContext $auth, array $masukan): array
    {
        $idPengguna = $this->idPengguna($auth);
        $token      = $this->token($masukan);
        $hash       = hash('sha256', $token);
        $sekarang   = time();
        $nanti      = gmdate('Y-m-d H:i:s', $sekarang + self::PERPANJANG_DETIK);

        $ada = $this->db->table('firebase_token')->select('id_firebase_token, refresh_date')
            ->where(['id_pengguna' => $idPengguna, 'token_hash' => $hash])->get(1)->getRowArray();

        if ($ada === null) {
            $this->db->table('firebase_token')->insert([
                'nip'          => $auth->nip(),
                'id_pengguna'  => $idPengguna,
                'platform'     => self::PLATFORM_WEB,
                'fcm_token'    => $token,
                'token_hash'   => $hash,
                'refresh_date' => $nanti,
                'created_at'   => gmdate('Y-m-d H:i:s', $sekarang),
                'updated_at'   => gmdate('Y-m-d H:i:s', $sekarang),
            ]);

            return ['status' => 'baru'];
        }

        $kedaluwarsa = $ada['refresh_date'] === null || strtotime($ada['refresh_date'] . ' UTC') < $sekarang;

        if (! $kedaluwarsa) {
            return ['status' => 'sudah_terdaftar'];
        }

        $this->db->table('firebase_token')->where('id_firebase_token', $ada['id_firebase_token'])
            ->update(['refresh_date' => $nanti, 'updated_at' => gmdate('Y-m-d H:i:s', $sekarang)]);

        return ['status' => 'diperpanjang'];
    }

    /**
     * @param array<string, mixed> $masukan `{fcm_token}`
     *
     * @return array{dihapus: int}
     */
    public function lepas(AuthContext $auth, array $masukan): array
    {
        $idPengguna = $this->idPengguna($auth);
        $hash       = hash('sha256', $this->token($masukan));

        $this->db->table('firebase_token')->where(['id_pengguna' => $idPengguna, 'token_hash' => $hash, 'platform' => self::PLATFORM_WEB])->delete();

        return ['dihapus' => $this->db->affectedRows()];
    }

    private function idPengguna(AuthContext $auth): int
    {
        return $auth->idPengguna() ?? throw new RuntimeException('Akun tidak dikenali.');
    }

    /**
     * @param array<string, mixed> $masukan
     */
    private function token(array $masukan): string
    {
        $token = $masukan['fcm_token'] ?? null;

        if (! is_string($token) || trim($token) === '' || strlen($token) > self::PANJANG_MAKS || preg_match('/\s/', trim($token)) === 1) {
            throw ValidationException::forField('fcm_token', 'Token notifikasi tidak valid.');
        }

        return trim($token);
    }
}
