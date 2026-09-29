<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Constants\Role;
use App\Libraries\Auth\AuthService;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\TestResponse;
use Config\Jwt as JwtConfig;
use Config\Services;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * Helper feature test Modul A. Dipakai bersama DatabaseTestTrait + FeatureTestTrait.
 */
trait AuthTestTrait
{
    /**
     * Terbitkan pasangan token untuk NIP seed (tanpa lewat endpoint login).
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}
     */
    protected function issueTokensFor(string $nip): array
    {
        $user = (new PenggunaModel())->withDeleted()->where('nip', $nip)->first();

        if (! is_array($user)) {
            throw new \RuntimeException("Akun {$nip} tidak ada di seed");
        }

        return $this->issueTokensForUser($user);
    }

    /**
     * Claims sesi (format DBV-010: sub = id_pengguna) untuk akun seed ber-NIP.
     *
     * @return array<string, mixed>
     */
    protected function claimsForNip(string $nip): array
    {
        $user = (new PenggunaModel())->withDeleted()->where('nip', $nip)->first();

        if (! is_array($user)) {
            throw new \RuntimeException("Akun {$nip} tidak ada di seed");
        }

        return AuthService::claimsFor($user);
    }

    /**
     * Terbitkan pasangan token untuk row pengguna apa pun (termasuk akun tanpa NIP, DBV-010).
     *
     * @param array<string, mixed> $user
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}
     */
    protected function issueTokensForUser(array $user): array
    {
        return service('jwt')->issueTokenPair(AuthService::claimsFor($user));
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return $this
     */
    protected function asUser(array $user): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($user)['access_token']]);
    }

    /**
     * Akun tanpa NIP (role 1/3/4/5/8, K2) dengan password AuthSeeder::PASSWORD (Argon2id). Test yang memakainya WAJIB
     * memanggil bersihkanDataDbv010() di tearDown(): down() migration DBV-010 menolak akun tanpa NIP.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed> row pengguna
     */
    protected function buatAkunTanpaNip(string $username, int $role = Role::SUPER_ADMIN, array $overrides = []): array
    {
        $now  = date('Y-m-d H:i:s');
        $data = array_merge([
            'nip'        => null,
            'username'   => $username,
            'name'       => 'Akun ' . $username,
            'email'      => null,
            'password'   => password_hash(AuthSeeder::PASSWORD, PASSWORD_ARGON2ID),
            'user_level' => $role,
            'id_unit'    => 'U01',
            'id_satker'  => 'S01',
            'status'     => '1',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $this->db->table('pengguna')->insert($data);

        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $this->db->insertID())->get()->getRowArray();

        return $row;
    }

    /**
     * Bersihkan data yang tidak bisa disimpan skema auth sebelum DBV-010, agar regress (down()) test berikutnya tidak
     * ditolak: audit berpelaku akun tanpa NIP, akun tanpa NIP / username > 30 / NIP > 20 (hapus fisik).
     */
    protected function bersihkanDataDbv010(): void
    {
        $this->db->table('audit_logs')->where('id_pengguna_actor IS NOT NULL', null, false)->where('nip_actor', null)->delete();
        $this->db->table('audit_logs')->where('CHAR_LENGTH(nip_actor) >', 20, false)->delete();
        $this->db->table('pengguna')
            ->groupStart()
            ->where('nip', null)
            ->orWhere('CHAR_LENGTH(username) >', 30, false)
            ->orWhere('CHAR_LENGTH(nip) >', 20, false)
            ->groupEnd()
            ->delete();
    }

    /**
     * @return $this
     */
    protected function asNip(string $nip): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensFor($nip)['access_token']]);
    }

    /**
     * @return $this
     */
    protected function asRole(int $role): static
    {
        return $this->asNip(AuthSeeder::nipForRole($role));
    }

    /**
     * @return $this
     */
    protected function withBearer(string $accessToken): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken]);
    }

    protected function setRefreshCookie(?string $token): void
    {
        $name = config(JwtConfig::class)->refreshCookie;

        if ($token === null) {
            service('superglobals')->unsetCookie($name);
        } else {
            service('superglobals')->setCookie($name, $token);
        }
    }

    protected function clearAuthState(): void
    {
        // Service shared (response, auth services) bocor antar request/test: bangun ulang agar cookie/config segar.
        foreach ([
            'response', 'authContext', 'jwt', 'captchaVerifier', 'passwordVerifier', 'lockoutService',
            'authService', 'passwordService', 'resetPasswordService', 'resetTokenNotifier', 'userService', 'accountProvisioner',
        ] as $name) {
            Services::resetSingle($name);
        }

        service('authContext')->clear();
        $cfg = config(JwtConfig::class);
        service('superglobals')->unsetCookie($cfg->accessCookie);
        service('superglobals')->unsetCookie($cfg->refreshCookie);
    }

    /**
     * POST JSON ke endpoint login.
     */
    protected function login(string $username, string $password, string $captcha = 'ok'): TestResponse
    {
        return $this->withBodyFormat('json')->post('api/v1/auth/login', [
            'username'      => $username,
            'password'      => $password,
            'captcha_token' => $captcha,
        ]);
    }

    /**
     * POST JSON ke endpoint lupa password (captcha wajib, pola login).
     */
    protected function forgotPassword(string $username, string $captcha = 'ok'): TestResponse
    {
        return $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', [
            'username'      => $username,
            'captcha_token' => $captcha,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(TestResponse $result): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Nilai cookie dari response (null kalau tidak ada).
     */
    protected function responseCookie(TestResponse $result, string $name): ?string
    {
        $response = $result->response();

        if (! $response->hasCookie($name)) {
            return null;
        }

        return $response->getCookie($name)->getValue();
    }
}
