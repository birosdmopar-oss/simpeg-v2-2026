<?php

declare(strict_types=1);

namespace Tests\Support;

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

        return service('jwt')->issueTokenPair(AuthService::claimsFor($user));
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
