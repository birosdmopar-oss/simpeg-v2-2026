<?php

declare(strict_types=1);

namespace App\Filters;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Libraries\Integrasi\Klien\PembatasKegagalan;
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
 * HTTP 200 `{"status":"02","msg":"Token is not valid","data":{}}` [K]. Bucket KEGAGALAN per IP (penuh → 429 sebelum
 * kueri), throttle per klien sesudah validasi; penolakan throttle ditulis ke `integrasi_log` paling banyak sekali per kunci per
 * jendela. Hanya alasan ringkas ke log (tanpa token/IP).
 */
class SimapiTokenFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $cfg      = config(KlienLayanan::class);
        $ip       = $request instanceof IncomingRequest ? $request->getIPAddress() : '';
        $pembatas = new PembatasKegagalan(service('cache'));
        $kunciIp  = md5('simapi-token|' . $ip);

        if (! $pembatas->terbuka($kunciIp, $cfg->throttleIpKapasitas)) {
            if ($pembatas->sekali('log|' . $kunciIp, $cfg->throttleDetik)) {
                self::catat('throttle_ip', null, 429);
            }

            return ResponsAuthKlien::terlaluBanyak(true, $cfg->throttleDetik);
        }

        $klien = (new TokenSimapi(db_connect(), $cfg->tokenPanjang, $cfg->tokenTtlDetik, $cfg->headerMaks))->periksa($request->getHeaderLine('Authorization'));

        if ($klien === null) {
            $pembatas->catatGagal($kunciIp, $cfg->throttleDetik);
            self::catat('token_tidak_sah', null, 200);

            return ResponsAuthKlien::gagal(true, ResponsAuthKlien::PESAN_TOKEN);
        }

        $thr = service('throttler');

        if (! $thr->check(md5('klien|' . $klien['id_klien']), $cfg->throttleKlienKapasitas, $cfg->throttleDetik)) {
            if ($pembatas->sekali('log|klien|' . $klien['id_klien'], $cfg->throttleDetik)) {
                self::catat('throttle_klien', $klien['id_klien'], 429);
            }

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

    private static function catat(string $alasan, ?int $idKlien, int $httpStatus): void
    {
        /** @var IntegrasiLogInterface $log */
        $log = service('integrasiLog');
        $log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_MASUK, 'simapi', 'token', CatatanIntegrasi::GAGAL, $httpStatus, $alasan, null, null, 1, null, $idKlien));
    }
}
