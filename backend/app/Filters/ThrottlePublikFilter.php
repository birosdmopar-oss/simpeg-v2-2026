<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Throttle endpoint publik verifikasi QR slip gaji (PRD 5A §6.7, F5-7, ADR-032): **30 permintaan per 60 detik per IP**
 * memakai `CodeIgniter\Throttle\Throttler` (`service('throttler')`, cache bawaan).
 *
 * Kunci bucket = hash IP (`md5`, aman sebagai kunci cache); IP **tidak** ditulis ke DB, log aplikasi, maupun audit.
 * Melebihi batas → 429 `{status: "error", message}` + `Retry-After`, beserta header `X-Robots-Tag: noindex, nofollow`
 * dan `Cache-Control: no-store` (sama dengan respons 200/404 endpoint itu, AC-D14.5).
 */
class ThrottlePublikFilter implements FilterInterface
{
    public const KAPASITAS = 30;

    public const DETIK = 60;

    public const PESAN = 'Terlalu banyak permintaan. Coba lagi beberapa saat.';

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $ip        = $request instanceof IncomingRequest ? $request->getIPAddress() : '';
        $throttler = service('throttler');

        if ($throttler->check(md5('verifikasi-publik|' . $ip), self::KAPASITAS, self::DETIK)) {
            return null;
        }

        log_message('info', 'Verifikasi slip publik: 429 (throttle).');

        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) max(1, $throttler->getTokenTime()))
            ->setHeader('X-Robots-Tag', 'noindex, nofollow')
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => 'error', 'message' => self::PESAN]);
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
