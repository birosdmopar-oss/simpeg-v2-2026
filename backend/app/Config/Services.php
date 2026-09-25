<?php

declare(strict_types=1);

namespace Config;

use App\Interfaces\CaptchaVerifierInterface;
use App\Interfaces\EsignGatewayInterface;
use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\ResetTokenNotifierInterface;
use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\AuthService;
use App\Libraries\Auth\JwtService;
use App\Libraries\Auth\LockoutService;
use App\Libraries\Auth\LogResetTokenNotifier;
use App\Libraries\Auth\MockCaptchaVerifier;
use App\Libraries\Auth\MockResetTokenNotifier;
use App\Libraries\Auth\PasswordService;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Auth\ResetPasswordService;
use App\Libraries\Auth\TurnstileVerifier;
use App\Libraries\Auth\UserService;
use App\Libraries\CacheService;
use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Html\HtmlSanitizer;
use App\Libraries\MasterData\FaqService;
use App\Libraries\MasterData\MasterRegistry;
use App\Libraries\MasterData\MasterService;
use App\Libraries\Push\MockFcmAdapter;
use App\Libraries\Siasn\MockSiasnAdapter;
use App\Models\AuditLogModel;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\LoginAttemptModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Config\BaseService;
use CodeIgniter\Exceptions\ConfigException;
use RuntimeException;

/**
 * Service container SIMPEG v2.
 *
 * Adapter integrasi eksternal di-resolve di sini berdasarkan Config\Integrations::$*['driver']
 * (ADR-015: business logic bergantung ke interface, implementasi konkret di-inject).
 * Fase 0 hanya menyediakan driver 'mock'; driver real ditambahkan di Fase 6 tanpa mengubah consumer.
 */
class Services extends BaseService
{
    public static function authContext(bool $getShared = true): AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new AuthContext();
    }

    public static function jwt(bool $getShared = true): JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }

        return new JwtService(config(Jwt::class));
    }

    // ------------------------------------------------------------------
    // Modul A — Autentikasi & Akun (Fase 1)
    // ------------------------------------------------------------------

    public static function captchaVerifier(bool $getShared = true): CaptchaVerifierInterface
    {
        if ($getShared) {
            return static::getSharedInstance('captchaVerifier');
        }

        $config = config(Auth::class);

        return match ($config->captchaDriver) {
            'mock'  => new MockCaptchaVerifier(),
            default => new TurnstileVerifier($config),
        };
    }

    public static function passwordVerifier(bool $getShared = true): PasswordVerifier
    {
        if ($getShared) {
            return static::getSharedInstance('passwordVerifier');
        }

        return new PasswordVerifier(new PenggunaModel(), config(Auth::class));
    }

    public static function lockoutService(bool $getShared = true): LockoutService
    {
        if ($getShared) {
            return static::getSharedInstance('lockoutService');
        }

        return new LockoutService(new LoginAttemptModel(), config(Auth::class));
    }

    public static function authService(bool $getShared = true): AuthService
    {
        if ($getShared) {
            return static::getSharedInstance('authService');
        }

        return new AuthService(
            new PenggunaModel(),
            static::passwordVerifier(),
            static::lockoutService(),
            static::captchaVerifier(),
            static::jwt(),
            new AuditLogModel(),
        );
    }

    public static function passwordService(bool $getShared = true): PasswordService
    {
        if ($getShared) {
            return static::getSharedInstance('passwordService');
        }

        return new PasswordService(new PenggunaModel(), static::passwordVerifier(), static::jwt());
    }

    public static function resetPasswordService(bool $getShared = true): ResetPasswordService
    {
        if ($getShared) {
            return static::getSharedInstance('resetPasswordService');
        }

        // Notifier sengaja tidak di-resolve di sini: driver yang ditolak (mis. log di production) hanya menggagalkan
        // forgot-password, bukan reset-password.
        return new ResetPasswordService(
            new PenggunaModel(),
            new ForgotAttemptModel(),
            static::passwordVerifier(),
            static::passwordService(),
            static::jwt(),
            config(Auth::class),
            captcha: static::captchaVerifier(),
        );
    }

    /**
     * Kanal pengiriman tautan reset password (A-07, ISSUE-006) dari Config\Auth::$resetTokenNotifier.
     * Driver email (K3) belum ada — menunggu akun SMTP.
     */
    public static function resetTokenNotifier(bool $getShared = true): ResetTokenNotifierInterface
    {
        if ($getShared) {
            return static::getSharedInstance('resetTokenNotifier');
        }

        $driver = config(Auth::class)->resetTokenNotifier;

        return match ($driver) {
            'mock'  => new MockResetTokenNotifier(),
            'log'   => new LogResetTokenNotifier(),
            default => throw new ConfigException("Driver auth.resetTokenNotifier '{$driver}' belum tersedia (driver email menunggu akun SMTP)."),
        };
    }

    public static function userService(bool $getShared = true): UserService
    {
        if ($getShared) {
            return static::getSharedInstance('userService');
        }

        return new UserService(new PenggunaModel(), static::passwordVerifier(), static::jwt());
    }

    public static function accountProvisioner(bool $getShared = true): AccountProvisioner
    {
        if ($getShared) {
            return static::getSharedInstance('accountProvisioner');
        }

        return new AccountProvisioner(new PenggunaModel(), static::passwordVerifier());
    }

    // ------------------------------------------------------------------
    // Modul G — Master Data (Fase 2)
    // ------------------------------------------------------------------

    public static function masterRegistry(bool $getShared = true): MasterRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('masterRegistry');
        }

        return new MasterRegistry(config(MasterData::class));
    }

    public static function masterService(bool $getShared = true): MasterService
    {
        if ($getShared) {
            return static::getSharedInstance('masterService');
        }

        return new MasterService(static::masterRegistry(), static::cacheService());
    }

    /**
     * G-10 — baca FAQ (UL_ALL) + rating artikel (UL_PEGAWAI). CRUD admin FAQ tetap lewat masterService.
     */
    public static function faqService(bool $getShared = true): FaqService
    {
        if ($getShared) {
            return static::getSharedInstance('faqService');
        }

        return new FaqService();
    }

    /**
     * Sanitasi HTML konten admin (HTMLPurifier whitelist, DBV-002). Shared: definisi purifier dibangun sekali.
     */
    public static function htmlSanitizer(bool $getShared = true): HtmlSanitizer
    {
        if ($getShared) {
            return static::getSharedInstance('htmlSanitizer');
        }

        return new HtmlSanitizer();
    }

    // ------------------------------------------------------------------
    // Fondasi (Fase 0)
    // ------------------------------------------------------------------

    public static function cacheService(bool $getShared = true): CacheService
    {
        if ($getShared) {
            return static::getSharedInstance('cacheService');
        }

        return new CacheService(static::cache());
    }

    public static function esignGateway(bool $getShared = true): EsignGatewayInterface
    {
        if ($getShared) {
            return static::getSharedInstance('esignGateway');
        }

        $driver = config(Integrations::class)->esign['driver'];

        return match ($driver) {
            'mock'  => new MockEsignAdapter(),
            default => throw new RuntimeException("Esign driver '{$driver}' belum tersedia (adapter real dikerjakan di Fase 6)."),
        };
    }

    public static function siasnGateway(bool $getShared = true): SiasnGatewayInterface
    {
        if ($getShared) {
            return static::getSharedInstance('siasnGateway');
        }

        $driver = config(Integrations::class)->siasn['driver'];

        return match ($driver) {
            'mock'  => new MockSiasnAdapter(),
            default => throw new RuntimeException("SIASN driver '{$driver}' belum tersedia (adapter real dikerjakan di Fase 6)."),
        };
    }

    public static function pushNotifGateway(bool $getShared = true): PushNotifGatewayInterface
    {
        if ($getShared) {
            return static::getSharedInstance('pushNotifGateway');
        }

        $driver = config(Integrations::class)->push['driver'];

        return match ($driver) {
            'mock'  => new MockFcmAdapter(),
            default => throw new RuntimeException("Push driver '{$driver}' belum tersedia (adapter real dikerjakan di Fase 6)."),
        };
    }
}
