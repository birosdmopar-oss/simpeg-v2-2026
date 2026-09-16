<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Home as RootHome;

/**
 * Alias healthcheck di bawah prefix api/v1 (GET /api/v1/health).
 */
class Home extends RootHome
{
}
