<?php

declare(strict_types=1);

namespace Config;

use App\Interfaces\EsignGatewayInterface;
use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\JwtService;
use App\Libraries\CacheService;
use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Push\MockFcmAdapter;
use App\Libraries\Siasn\MockSiasnAdapter;
use CodeIgniter\Config\BaseService;
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
