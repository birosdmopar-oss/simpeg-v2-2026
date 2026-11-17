<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Basis controller helpdesk Halo Simpeg (MAKE-070 master; MAKE-071 tiket menyusul): validasi id path, query, tanpa cache.
 * Role dicek filter route (`Config/RoutesHaloSimpeg.php`); aturan di service `App\Libraries\HaloSimpeg\*`. Kontrak: README folder ini.
 */
abstract class HaloSimpegController extends ApiController
{
    protected function idValid(int|string $id): int
    {
        $s = (string) $id;

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
            throw ValidationException::forField('id', 'ID tidak valid.');
        }

        return (int) $s;
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }

    protected function tanpaCache(ResponseInterface $r): ResponseInterface
    {
        return $r->setHeader('Cache-Control', 'private, no-store');
    }
}
