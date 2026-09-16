<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * CORS untuk frontend SPA (Vite dev server / domain FE).
 * supportsCredentials WAJIB true agar cookie httpOnly JWT ikut terkirim (ADR-004, ADR-022).
 * Origin diisi lewat .env: cors.allowedOrigins (dipisah koma).
 */
class Cors extends BaseConfig
{
    /**
     * Dibaca dari .env (string dipisah koma), lalu dipecah di constructor.
     */
    public string $allowedOrigins = 'http://localhost:5173';

    /**
     * @var array{
     *   allowedOrigins: list<string>,
     *   allowedOriginsPatterns: list<string>,
     *   supportsCredentials: bool,
     *   allowedHeaders: list<string>,
     *   exposedHeaders: list<string>,
     *   allowedMethods: list<string>,
     *   maxAge: int,
     * }
     */
    public array $default = [
        'allowedOrigins'         => [],
        'allowedOriginsPatterns' => [],
        'supportsCredentials'    => true,
        'allowedHeaders'         => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
        'exposedHeaders'         => [],
        'allowedMethods'         => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'maxAge'                 => 7200,
    ];

    public function __construct()
    {
        parent::__construct();

        $origins = array_values(array_filter(array_map('trim', explode(',', $this->allowedOrigins))));

        $this->default['allowedOrigins'] = $origins;
    }
}
