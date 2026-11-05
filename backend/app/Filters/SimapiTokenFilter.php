<?php

declare(strict_types=1);

namespace App\Filters;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Libraries\Integrasi\Klien\ResponsAuthKlien;
use App\Libraries\Integrasi\Klien\TokenSimapi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\KlienLayanan;

/**
 * MAKE-058 (ADR-036) — filter `simapiToken`: validasi token SIMAPI di header `Authorization` (elemen kedua [K `AE:30-47`]) untuk
 * `a_employee`, `a_dm`, `a_sinkron` (PRD Fase 6 §7.0.1, AC-F.5). Token tidak sah/kedaluwarsa/dicabut/klien nonaktif →
 * HTTP 200 `{"status":"02","msg":"Token is not valid","data":{}}` [K]. Throttle per IP sebelum dan per klien sesudah
 * validasi. Hanya alasan ringkas ke `integrasi_log` (tanpa token/IP).
 */
class SimapiTokenFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $cfg = config(KlienLayanan::class);
        $ip  = $request instanceof IncomingRequest ? $request->getIPAddress() : '';
        $thr = service('throttler');

        if (! $thr->check(md5('simapi-token|' . $ip), $cfg->throttleIpKapasitas, $cfg->throttleDetik)) {
            self::catat('throttle_ip', null);

            return ResponsAuthKlien::terlaluBanyak(true, $thr->getTokenTime());
        }

        $klien = (new TokenSimapi(db_connect(), $cfg->tokenPanjang, $cfg->tokenTtlDetik, $cfg->headerMaks))->periksa($request->getHeaderLine('Authorization'));

        if ($klien === null) {
            self::catat('token_tidak_sah', null);

            return ResponsAuthKlien::gagal(true, ResponsAuthKlien::PESAN_TOKEN);
        }

        if (! $thr->check(md5('klien|' . $klien['id_klien']), $cfg->throttleKlienKapasitas, $cfg->throttleDetik)) {
            self::catat('throttle_klien', $klien['id_klien']);

            return ResponsAuthKlien::terlaluBanyak(true, $thr->getTokenTime());
        }

        service('klienSaatIni')->set($klien['id_klien'], $klien['jenis']);

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }

    private static function catat(string $alasan, ?int $idKlien): void
    {
        /** @var IntegrasiLogInterface $log */
        $log = service('integrasiLog');
        $log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_MASUK, 'simapi', 'token', CatatanIntegrasi::GAGAL, 200, $alasan, null, null, 1, null, $idKlien));
    }
}
