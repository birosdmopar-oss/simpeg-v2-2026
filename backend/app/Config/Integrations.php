<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi integrasi eksternal (ADR-015).
 * driver = 'mock' dipakai Fase 0-5. Adapter real ditambahkan di Fase 6 (Modul H).
 * Kredensial WAJIB lewat .env, jangan pernah di-commit.
 */
class Integrations extends BaseConfig
{
    /**
     * `tlsVerify` false hanya untuk gateway internal tanpa TLS yang dicatat di `.env` (BP-3); `timeout` detik total.
     *
     * @var array{driver: string, baseUrl: string, username: string, password: string, tlsVerify: bool, timeout: float}
     */
    public array $esign = [
        'driver'    => 'mock',
        'baseUrl'   => '',
        'username'  => '',
        'password'  => '',
        'tlsVerify' => true,
        'timeout'   => 30.0,
    ];

    /**
     * @var array{driver: string, baseUrl: string, clientId: string, clientSecret: string}
     */
    public array $siasn = [
        'driver'       => 'mock',
        'baseUrl'      => '',
        'clientId'     => '',
        'clientSecret' => '',
    ];

    /**
     * @var array{driver: string, projectId: string, credentialsPath: string}
     */
    public array $push = [
        'driver'          => 'mock',
        'projectId'       => '',
        'credentialsPath' => '',
    ];

    /**
     * Kerangka layanan BKN (e-Kinerja H-10, SIASN H-03) — Fase 6 MAKE-063. Alamat & kredensial hanya dari `.env` server
     * (BP-2); bentuk jalur token (API gateway, SSO, e-Kinerja) dilengkapi bersama `TokenBkn` setelah bentuk legacy
     * dipastikan (tanpa token literal, F6-26/AC-03.4).
     *
     * @var array{baseUrlApi: string, baseUrlSso: string, baseUrlEkinerja: string, clientId: string, clientSecret: string, ssoUsername: string, ssoPassword: string, tlsVerify: bool, timeout: float}
     */
    public array $bkn = [
        'baseUrlApi'      => '',
        'baseUrlSso'      => '',
        'baseUrlEkinerja' => '',
        'clientId'        => '',
        'clientSecret'    => '',
        'ssoUsername'     => '',
        'ssoPassword'     => '',
        'tlsVerify'       => true,
        'timeout'         => 30.0,
    ];

    /**
     * Retensi baris `integrasi_log` (Q6-16, F6-24) untuk `integrasi:bersihkan-log`.
     */
    public int $logRetensiHari = 180;
}
