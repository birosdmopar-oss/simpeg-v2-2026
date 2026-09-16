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
     * @var array{driver: string, baseUrl: string, username: string, password: string}
     */
    public array $esign = [
        'driver'   => 'mock',
        'baseUrl'  => '',
        'username' => '',
        'password' => '',
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
}
