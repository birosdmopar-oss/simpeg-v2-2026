<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

use App\Libraries\Bkn\TokenBkn;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\Http\Respons;

/**
 * Klien API e-Kinerja BKN (H-10) — hanya GET baca [K `EB:23-24`, `EB:254-256`]:
 * `GET {baseUrlEkinerja}/referensi/periode` dan `GET {baseUrlEkinerja}/laporan/penilaian/{tahun}/{idPeriode}`
 * (satu instansi sekaligus), auth lewat {@see TokenBkn::kirimEkinerja()} (401 → ulang sekali). GET di-retry otomatis
 * oleh `KlienHttp` (BP-3). Konfigurasi wajib diperiksa saat dibuat (AC-K.4). `baseUrlEkinerja` = alamat dasar sampai
 * sebelum `/referensi` (mis. `https://<host>/api_kinerja`, hanya di `.env`).
 */
final class EkinerjaKlien
{
    public const LAYANAN = 'ekinerja';

    public function __construct(private readonly TokenBkn $token, private readonly string $baseUrl)
    {
        $this->token->pastikanEkinerja();
    }

    public function periode(): Respons
    {
        return $this->token->kirimEkinerja(fn (array $h): Permintaan => new Permintaan(
            'GET',
            rtrim($this->baseUrl, '/') . '/referensi/periode',
            self::LAYANAN,
            'referensi_periode',
            header: $h,
        ));
    }

    public function penilaian(int $tahun, string $idPeriode): Respons
    {
        return $this->token->kirimEkinerja(fn (array $h): Permintaan => new Permintaan(
            'GET',
            rtrim($this->baseUrl, '/') . '/laporan/penilaian/' . $tahun . '/' . rawurlencode($idPeriode),
            self::LAYANAN,
            'laporan_penilaian',
            header: $h,
            refId: $idPeriode,
        ));
    }
}
