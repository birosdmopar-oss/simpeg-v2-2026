<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;

/**
 * Pendaftaran token FCM web/desktop (PRD Fase 6 §7.4, F6-13; MAKE-066) — pengganti `Local::register_fb_token`
 * [K `L:4224-4289`] yang dipanggil SPA setiap halaman dimuat setelah login dan izin notifikasi diberikan.
 *
 * - Kunci PER PEMILIK (keputusan Horii 03-11-2026, DBV-Q8 ikut legacy `fb_token.id_user + fcm_token`): (`id_pengguna`,
 *   `token_hash`) dengan `platform = 1`. Token sama milik akun lain tidak disentuh.
 * - Baru → INSERT dengan `refresh_date` = sekarang + 1 minggu; sudah ada → `refresh_date` diperpanjang 1 minggu hanya bila
 *   sudah lewat [K :4254-4261]. `nip` selalu NULL untuk token web (DBV-027 T2 opsi a: pemilik token web = akun saja,
 *   CHECK pemilik); penerima "pegawai" di kanal web dicari lewat `pengguna.nip` → `id_pengguna`.
 * - Pendaftaran bersamaan (dua tab) → 1062 UNIQUE (`id_pengguna`, `token_hash`) ditangkap menjadi `sudah_terdaftar` (AC-04.5,
 *   review MAKE-066 P3), bukan 500.
 * - [V2] review MAKE-066 P4: maks {@see self::MAKS_TOKEN_WEB} token web per akun (yang terlama dibuang saat token baru masuk) dan
 *   throttle {@see self::THROTTLE_KAPASITAS} POST per {@see self::THROTTLE_DETIK} detik per akun (429).
 * - Lepas (logout, [V2] — legacy tidak punya): hapus baris token itu milik akun ini saja.
 * - Isi token tidak pernah dikembalikan/dicatat; stempel waktu UTC.
 */
final class TokenFcmService
{
    public const PLATFORM_WEB = 1;

    public const PLATFORM_ANDROID = 2;

    public const PERPANJANG_DETIK = 7 * 86400;

    private const PANJANG_MAKS = 4096;

    public const MAKS_TOKEN_WEB = 10;

    public const THROTTLE_KAPASITAS = 20;

    public const THROTTLE_DETIK = 60;

    private const ER_DUP_ENTRY = 1062;

    /**
     * @param Closure(): void|null $sebelumSisip seam test: dijalankan tepat sebelum INSERT (simulasi permintaan bersamaan)
     */
    public function __construct(private readonly BaseConnection $db, private readonly ?Closure $sebelumSisip = null)
    {
    }

    /**
     * @param array<string, mixed> $masukan `{fcm_token}`
     *
     * @return array{status: 'baru'|'diperpanjang'|'sudah_terdaftar'}
     */
    public function daftar(AuthContext $auth, #[\SensitiveParameter] array $masukan): array
    {
        $idPengguna = $this->idPengguna($auth);
        $token      = $this->token($masukan);

        if (! service('throttler')->check(md5('fcm-token|' . $idPengguna), self::THROTTLE_KAPASITAS, self::THROTTLE_DETIK)) {
            throw new TooManyRequestsException();
        }

        $hash     = hash('sha256', $token);
        $sekarang = time();
        $nanti    = gmdate('Y-m-d H:i:s', $sekarang + self::PERPANJANG_DETIK);

        $ada = $this->db->table('firebase_token')->select('id_firebase_token, refresh_date')
            ->where(['id_pengguna' => $idPengguna, 'token_hash' => $hash])->get(1)->getRowArray();

        if ($ada === null) {
            if ($this->sebelumSisip !== null) {
                ($this->sebelumSisip)();
            }

            try {
                $ok = $this->db->table('firebase_token')->insert([
                    'nip'          => null,
                    'id_pengguna'  => $idPengguna,
                    'platform'     => self::PLATFORM_WEB,
                    'fcm_token'    => $token,
                    'token_hash'   => $hash,
                    'refresh_date' => $nanti,
                    'created_at'   => gmdate('Y-m-d H:i:s', $sekarang),
                    'updated_at'   => gmdate('Y-m-d H:i:s', $sekarang),
                ]);
            } catch (DatabaseException $e) {
                if ((int) $e->getCode() === self::ER_DUP_ENTRY) {
                    return ['status' => 'sudah_terdaftar'];
                }

                throw $e;
            }

            if ($ok === false) {
                if ((int) ($this->db->error()['code'] ?? 0) === self::ER_DUP_ENTRY) {
                    return ['status' => 'sudah_terdaftar'];
                }

                throw new RuntimeException('Token notifikasi gagal disimpan.');
            }

            $this->pangkasTokenWeb($idPengguna);

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
    public function lepas(AuthContext $auth, #[\SensitiveParameter] array $masukan): array
    {
        $idPengguna = $this->idPengguna($auth);
        $hash       = hash('sha256', $this->token($masukan));

        $this->db->table('firebase_token')->where(['id_pengguna' => $idPengguna, 'token_hash' => $hash, 'platform' => self::PLATFORM_WEB])->delete();

        return ['dihapus' => $this->db->affectedRows()];
    }

    /**
     * Sisakan {@see self::MAKS_TOKEN_WEB} token web terbaru milik akun (review MAKE-066 P4).
     */
    private function pangkasTokenWeb(int $idPengguna): void
    {
        $lama = array_map('intval', array_column($this->db->table('firebase_token')->select('id_firebase_token')
            ->where(['id_pengguna' => $idPengguna, 'platform' => self::PLATFORM_WEB])
            ->orderBy('id_firebase_token', 'DESC')->limit(1000, self::MAKS_TOKEN_WEB)->get()->getResultArray(), 'id_firebase_token'));

        if ($lama !== []) {
            $this->db->table('firebase_token')->whereIn('id_firebase_token', $lama)->delete();
        }
    }

    private function idPengguna(AuthContext $auth): int
    {
        return $auth->idPengguna() ?? throw new RuntimeException('Akun tidak dikenali.');
    }

    /**
     * @param array<string, mixed> $masukan
     */
    private function token(#[\SensitiveParameter] array $masukan): string
    {
        $token = $masukan['fcm_token'] ?? null;

        if (! is_string($token) || trim($token) === '' || strlen($token) > self::PANJANG_MAKS || preg_match('/\s/', trim($token)) === 1) {
            throw ValidationException::forField('fcm_token', 'Token notifikasi tidak valid.');
        }

        return trim($token);
    }
}
