<?php

declare(strict_types=1);

namespace App\Controllers\Simapi;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Klien\KlienTidakAktif;
use App\Libraries\Integrasi\Klien\ResponsAuthKlien;
use App\Libraries\Integrasi\Klien\TokenSimapi;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;
use Config\KlienLayanan;
use RuntimeException;

/**
 * MAKE-059 (H-05) — `simapi/v1/a_auth/token` [K `A_auth.php:19-111`]. Auth Basic sudah lewat filter `klienLayanan:simapi`
 * (MAKE-058); di sini hanya menerbitkan token baru per handshake (F6-05) dan mengembalikan `data.token` saja (Q6-02).
 * Handshake yang berlomba dengan nonaktif/hapus/rotasi rahasia → "Authentication failed" seragam (ADR-036 §4).
 */
class AAuth extends BaseController
{
    public function token(): ResponseInterface
    {
        $klien = service('klienSaatIni');
        $sidik = $klien->sidikRahasia();

        if (! $klien->ada() || $sidik === null) {
            return ResponsAuthKlien::gagal(true);
        }

        $cfg = config(KlienLayanan::class);

        try {
            $hasil = (new TokenSimapi(db_connect(), $cfg->tokenPanjang, $cfg->tokenTtlDetik, $cfg->headerMaks))->terbitkan((int) $klien->id(), $sidik);
        } catch (KlienTidakAktif) {
            return ResponsAuthKlien::gagal(true);
        } catch (RuntimeException $e) {
            // Hanya kelas + kode: pesan DatabaseException bisa memuat SQL/nilai (hash token).
            log_message('error', 'SIMAPI a_auth/token gagal: ' . (new \ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');

            return ResponsSimapi::gagal(ResponsSimapi::PESAN_SERVER);
        }

        return ResponsSimapi::sukses(['token' => $hasil['token']]);
    }
}
