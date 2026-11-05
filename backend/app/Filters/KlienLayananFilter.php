<?php

declare(strict_types=1);

namespace App\Filters;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Libraries\Integrasi\Klien\HakKlien;
use App\Libraries\Integrasi\Klien\PengesahKlien;
use App\Libraries\Integrasi\Klien\ResponsAuthKlien;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\KlienLayanan;
use LogicException;

/**
 * MAKE-058 (ADR-036) — filter `klienLayanan:<hak>`: auth HTTP Basic klien layanan untuk SIMAPI `a_auth` (handshake token) dan
 * `services/*` (PRD Fase 6 §7.0.1, F6-03). Alur: throttle per IP → urai Basic → {@see PengesahKlien} (hash, status, allowlist IP,
 * hak) → throttle per klien → `KlienSaatIni`. Gagal → respons seragam per grup ({@see ResponsAuthKlien}), alasan hanya ke
 * `integrasi_log` (arah masuk, tanpa username/rahasia/IP). Argumen wajib: salah satu {@see HakKlien::SEMUA}.
 */
class KlienLayananFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $hak = $arguments[0] ?? '';

        if (! in_array($hak, HakKlien::SEMUA, true)) {
            throw new LogicException('Filter klienLayanan wajib diberi hak sah, mis. klienLayanan:stat.');
        }

        $simapi = $hak === HakKlien::SIMAPI;
        $cfg    = config(KlienLayanan::class);
        $ip     = $request instanceof IncomingRequest ? $request->getIPAddress() : '';
        $thr    = service('throttler');

        if (! $thr->check(md5('klien-auth|' . $ip), $cfg->throttleIpKapasitas, $cfg->throttleDetik)) {
            self::catat($simapi, 'throttle_ip', null);

            return ResponsAuthKlien::terlaluBanyak($simapi, $thr->getTokenTime());
        }

        [$username, $rahasia] = self::urai($request->getHeaderLine('Authorization'), $cfg->headerMaks);
        $hasil                = (new PengesahKlien(db_connect()))->periksa($username, $rahasia, $ip, $hak);

        if ($hasil['hasil'] !== PengesahKlien::DITERIMA) {
            self::catat($simapi, $hasil['hasil'], $hasil['id_klien']);

            return ResponsAuthKlien::gagal($simapi);
        }

        if (! $thr->check(md5('klien|' . $hasil['id_klien']), $cfg->throttleKlienKapasitas, $cfg->throttleDetik)) {
            self::catat($simapi, 'throttle_klien', $hasil['id_klien']);

            return ResponsAuthKlien::terlaluBanyak($simapi, $thr->getTokenTime());
        }

        service('klienSaatIni')->set((int) $hasil['id_klien'], (int) $hasil['jenis']);

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }

    /**
     * `Authorization: Basic base64(user:pass)` → [user, pass]; bentuk salah/terlalu panjang → ['', ''] (gagal seragam).
     *
     * @return array{0: string, 1: string}
     */
    private static function urai(string $header, int $maks): array
    {
        if ($header === '' || strlen($header) > $maks || preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($header), $m) !== 1) {
            return ['', ''];
        }

        $mentah = base64_decode($m[1], true);

        if ($mentah === false || ! str_contains($mentah, ':')) {
            return ['', ''];
        }

        [$username, $rahasia] = explode(':', $mentah, 2);

        return [$username, $rahasia];
    }

    private static function catat(bool $simapi, string $alasan, ?int $idKlien): void
    {
        /** @var IntegrasiLogInterface $log */
        $log = service('integrasiLog');
        $log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_MASUK, $simapi ? 'simapi' : 'services', 'auth_klien', CatatanIntegrasi::GAGAL, $simapi ? 200 : 401, $alasan, null, null, 1, null, $idKlien));
    }
}
