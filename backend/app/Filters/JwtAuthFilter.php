<?php

declare(strict_types=1);

namespace App\Filters;

use App\Exceptions\AuthException;
use App\Libraries\Auth\AuthContext;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Jwt as JwtConfig;

/**
 * JwtAuthFilter (F0-06) — validasi access token, 401 kalau tidak ada/invalid/expired.
 * Sumber token: cookie httpOnly (utama, ADR-004) atau header Authorization: Bearer (fallback API client).
 * Claims yang valid disimpan ke service('authContext') untuk dipakai RoleFilter/Service/Model.
 *
 * MAKE-020 (ISSUE-044) [V2]: sesi akun yang wajib ganti password (claim `must_change_password`) hanya boleh memakai
 * route yang memasang argumen `jwt:ganti_password` (auth/me, auth/change-password, auth/logout; auth/refresh tanpa
 * filter jwt). Route lain → 403 `code` = `password_change_required`. Fail-closed: route baru otomatis ikut ditolak.
 *
 * CR-175: sesi dengan NIP basi (klaim `nip` tidak lagi dipegang akun `sub`, mis. sesudah koreksi NIP atau hapus pegawai)
 * → 401 generik, dicek sebelum `must_change_password`. Satu kueri tambahan hanya untuk token ber-NIP.
 */
class JwtAuthFilter implements FilterInterface
{
    /** Argumen filter (`jwt:ganti_password`) untuk route yang tetap boleh dipakai selama wajib ganti password. */
    public const ARG_GANTI_PASSWORD = 'ganti_password';

    public const CODE_PASSWORD_CHANGE_REQUIRED = 'password_change_required';

    /**
     * Body 401 generik — dipakai bersama filter ini dan jaring 1452 sesi NIP basi di `ApiController::_remap` (CR-185), supaya
     * klien tidak bisa membedakan asal penolakan.
     *
     * @var array{status: string, message: string}
     */
    public const BODY_UNAUTHORIZED = ['status' => 'error', 'message' => 'Unauthorized'];

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->unauthorized();
        }

        try {
            $claims = service('jwt')->verifyAccessToken($token);
        } catch (AuthException) {
            return $this->unauthorized();
        }

        service('authContext')->setClaims($claims);

        if (! $this->nipMasihSesuai()) {
            service('authContext')->clear();

            return $this->unauthorized();
        }

        if (service('authContext')->mustChangePassword() && ! in_array(self::ARG_GANTI_PASSWORD, (array) ($arguments ?? []), true)) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'status'  => 'error',
                    'code'    => self::CODE_PASSWORD_CHANGE_REQUIRED,
                    'message' => 'Password wajib diganti sebelum melanjutkan.',
                ]);
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }

    /**
     * CR-175: klaim `nip` tidak null → akun `sub` wajib masih memegang NIP itu (sesudah koreksi NIP / hapus pegawai,
     * access token lama membawa NIP lama sampai `exp`). Token tanpa NIP tidak menambah kueri. Dicek sebelum
     * `must_change_password` agar sesi basi selalu 401, bukan 403.
     */
    private function nipMasihSesuai(): bool
    {
        $auth = service('authContext');
        $nip  = $auth->nip();

        if ($nip === null) {
            return true;
        }

        $id = $auth->idPengguna();

        return $id !== null && model(PenggunaModel::class)->masihMemegangNip($id, $nip);
    }

    /**
     * CR-185: sesi request ini TERBUKTI basi — klaim `nip` ada, `sub` ada, dan akun itu kini tidak memegang NIP tersebut
     * (koreksi NIP / hapus pegawai yang commit SESUDAH filter ini lolos). Token tanpa klaim NIP / tanpa `sub` → false (tidak
     * terbukti). Dipanggil hanya di jalur galat (1452), jadi tidak menambah kueri request sukses.
     */
    public static function sesiNipTerbuktiBasi(AuthContext $auth): bool
    {
        $nip = $auth->nip();
        $id  = $auth->idPengguna();

        return $nip !== null && $id !== null && ! model(PenggunaModel::class)->masihMemegangNip($id, $nip);
    }

    private function extractToken(RequestInterface $request): ?string
    {
        if ($request instanceof IncomingRequest) {
            $cookie = $request->getCookie(config(JwtConfig::class)->accessCookie);

            if (is_string($cookie) && $cookie !== '') {
                return $cookie;
            }
        }

        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function unauthorized(): ResponseInterface
    {
        // Pesan generik — tidak membocorkan alasan detail (Tech Spec Bagian 9).
        return service('response')
            ->setStatusCode(401)
            ->setJSON(self::BODY_UNAUTHORIZED);
    }
}
