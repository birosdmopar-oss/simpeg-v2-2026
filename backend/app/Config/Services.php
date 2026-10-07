<?php

declare(strict_types=1);

namespace Config;

use App\Interfaces\CaptchaVerifierInterface;
use App\Interfaces\EsignGatewayInterface;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Kepegawaian\BiodataServiceInterface;
use App\Interfaces\Kepegawaian\KonketServiceInterface;
use App\Interfaces\Kepegawaian\LkhServiceInterface;
use App\Interfaces\Kepegawaian\NipCascadeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiServiceInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Kepegawaian\RiwayatServiceInterface;
use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Interfaces\Kepegawaian\StrukturServiceInterface;
use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\ResetTokenNotifierInterface;
use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\AuthService;
use App\Libraries\Auth\EmailResetTokenNotifier;
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
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use App\Libraries\Kepegawaian\Stub\StubAttachmentService;
use App\Libraries\Kepegawaian\Stub\StubBiodataService;
use App\Libraries\Kepegawaian\Stub\StubKonketService;
use App\Libraries\Kepegawaian\Stub\StubLkhService;
use App\Libraries\Kepegawaian\Stub\StubNipCascade;
use App\Libraries\Kepegawaian\Stub\StubPegawaiScope;
use App\Libraries\Kepegawaian\Stub\StubPegawaiService;
use App\Libraries\Kepegawaian\Stub\StubRiwayatService;
use App\Libraries\Kepegawaian\Stub\StubSnapshotSync;
use App\Libraries\Kepegawaian\Stub\StubStorageAdapter;
use App\Libraries\Kepegawaian\Stub\StubStrukturService;
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

    /**
     * Captcha A-03 dari Config\Auth::$captchaDriver. Driver 'mock' ditolak di production (ConfigException dari
     * MockCaptchaVerifier, ISSUE-021): authService dan resetPasswordService me-resolve captcha saat dibangun, sehingga
     * login, refresh/logout, serta lupa/reset password gagal 500 (fail-closed) sampai .env diperbaiki.
     */
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
     * Kanal pengiriman tautan reset password (A-07, ISSUE-006) dari Config\Auth::$resetTokenNotifier:
     * 'email' (production, CR-014: antrean `email` + worker `php spark queue:work email`, guard email.* dan
     * encryption.key di constructor), 'log' (development), 'mock' (test). Driver yang salah konfigurasi atau tidak
     * dikenal → ConfigException saat di-resolve (ResetPasswordService::request(), sebelum username dicari).
     */
    public static function resetTokenNotifier(bool $getShared = true): ResetTokenNotifierInterface
    {
        if ($getShared) {
            return static::getSharedInstance('resetTokenNotifier');
        }

        $driver = config(Auth::class)->resetTokenNotifier;

        return match ($driver) {
            'email' => new EmailResetTokenNotifier(config(Email::class), config(Encryption::class)),
            'mock'  => new MockResetTokenNotifier(),
            'log'   => new LogResetTokenNotifier(),
            default => throw new ConfigException("Driver auth.resetTokenNotifier '{$driver}' tidak dikenal (log|mock|email)."),
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
     * G-08 — hari libur (DBV-003/CR-010): daftar/detail role 1/4/5/8, tulis role 1, tanggalLibur() untuk Fase 5.
     */
    public static function hariLiburService(bool $getShared = true): \App\Libraries\MasterData\HariLiburService
    {
        if ($getShared) {
            return static::getSharedInstance('hariLiburService');
        }

        return new \App\Libraries\MasterData\HariLiburService();
    }

    /**
     * G-09 — web config bertipe (DBV-006/CR-030): CRUD role 1 + value()/values() untuk modul lain (cache diinvalidasi saat
     * tulis).
     */
    public static function webConfigService(bool $getShared = true): \App\Libraries\MasterData\WebConfigService
    {
        if ($getShared) {
            return static::getSharedInstance('webConfigService');
        }

        return new \App\Libraries\MasterData\WebConfigService(static::cacheService());
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
    // Modul B — Kepegawaian Core (Fase 3). Didaftarkan sekali di S0-A (MAKE-002); kontrak:
    // app/Controllers/Api/Kepegawaian/README.md. Tipe kembalian = interface. Setelah S0-A, satu-satunya perubahan yang
    // diizinkan di sini: pemilik service mengganti `new Stub...` dengan kelas nyata (dicatat di commit milestone-nya).
    // Stub di App\Libraries\Kepegawaian\Stub fail-closed (scope menolak semua, service lain → 501); fake untuk test
    // hanya di tests/_support/Kepegawaian, disuntik lewat Services::injectMock().
    // ------------------------------------------------------------------

    /**
     * Lingkup akses pegawai — WS-2 (MAKE-009).
     */
    public static function pegawaiScope(bool $getShared = true): PegawaiScopeInterface
    {
        if ($getShared) {
            return static::getSharedInstance('pegawaiScope');
        }

        return new StubPegawaiScope();
    }

    /**
     * Lampiran riwayat B-18 — WS-2 (MAKE-009).
     */
    public static function attachmentService(bool $getShared = true): AttachmentServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('attachmentService');
        }

        return new StubAttachmentService();
    }

    /**
     * Penyimpanan berkas lampiran (LocalStorageAdapter) — WS-2 (MAKE-009).
     */
    public static function storageAdapter(bool $getShared = true): StorageAdapterInterface
    {
        if ($getShared) {
            return static::getSharedInstance('storageAdapter');
        }

        return new StubStorageAdapter();
    }

    /**
     * Registry Definisi riwayat (auto-discovery folder Config\Kepegawaian::$definisiRiwayatPath) — WS-1.
     */
    public static function riwayatRegistry(bool $getShared = true): RiwayatRegistryInterface
    {
        if ($getShared) {
            return static::getSharedInstance('riwayatRegistry');
        }

        $config = config(Kepegawaian::class);

        // Scope di-resolve setiap dipakai (bukan ditangkap sekali) agar Services::injectMock('pegawaiScope') di test
        // berlaku juga untuk registry shared yang sudah ter-resolve.
        return RiwayatRegistry::dariFolder(
            static fn (): PegawaiScopeInterface => static::pegawaiScope(),
            $config->definisiRiwayatPath,
            $config->definisiRiwayatNamespace,
        );
    }

    /**
     * RiwayatEngine — WS-1 (MAKE-004).
     */
    public static function riwayatService(bool $getShared = true): RiwayatServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('riwayatService');
        }

        return new StubRiwayatService();
    }

    /**
     * SnapshotSync — WS-1 (MAKE-004).
     */
    public static function snapshotSync(bool $getShared = true): SnapshotSyncInterface
    {
        if ($getShared) {
            return static::getSharedInstance('snapshotSync');
        }

        return new StubSnapshotSync();
    }

    /**
     * Daftar & detail pegawai — WS-2 (MAKE-010/MAKE-014).
     */
    public static function pegawaiService(bool $getShared = true): PegawaiServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('pegawaiService');
        }

        return new StubPegawaiService();
    }

    /**
     * Biodata dua jalur + approval draft B-03/B-04 — WS-2 (MAKE-010/MAKE-011).
     */
    public static function biodataService(bool $getShared = true): BiodataServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('biodataService');
        }

        return new StubBiodataService();
    }

    /**
     * Koreksi NIP cascade B-06 — WS-2 (MAKE-010).
     */
    public static function nipCascade(bool $getShared = true): NipCascadeInterface
    {
        if ($getShared) {
            return static::getSharedInstance('nipCascade');
        }

        return new StubNipCascade();
    }

    /**
     * Struktur organisasi B-19 — WS-2 (MAKE-012).
     */
    public static function strukturService(bool $getShared = true): StrukturServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('strukturService');
        }

        return new StubStrukturService();
    }

    /**
     * LKH B-12b — WS-2 (MAKE-013).
     */
    public static function lkhService(bool $getShared = true): LkhServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('lkhService');
        }

        return new StubLkhService();
    }

    /**
     * Konket B-13 — WS-2 (MAKE-013).
     */
    public static function konketService(bool $getShared = true): KonketServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('konketService');
        }

        return new StubKonketService();
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
