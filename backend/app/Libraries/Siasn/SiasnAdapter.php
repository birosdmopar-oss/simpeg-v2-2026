<?php

declare(strict_types=1);

namespace App\Libraries\Siasn;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Bkn\GalatTokenBkn;
use App\Libraries\Bkn\TokenBkn;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\Http\Respons;
use InvalidArgumentException;

/**
 * Adapter SIASN BKN nyata (H-03, MAKE-067; PRD Fase 6 §7.3, F6-14 ikut legacy penuh) — kontrak
 * {@see SiasnGatewayInterface} TIDAK diubah. Baca data per NIP lewat API gateway SIASN [K `getApi_siasn`]:
 * `GET {bkn.baseUrlApi}/pns/{jalur}/{nip}` dengan dua header token ({@see TokenBkn::kirimSiasn()}, 401 → ulang sekali),
 * GET di-retry otomatis `KlienHttp` (BP-3). `bkn.baseUrlApi` = alamat dasar sampai `/apisiasn/1.0` (hanya di `.env`).
 *
 * Bentuk hasil (`status_code`):
 * - `'200'` — HTTP 2xx, badan JSON `code == 1` dan `data` array [K syarat sukses `SS/*`];
 * - kode HTTP lain (mis. `'404'`, `'500'`), `'respons'` (badan bukan JSON / `code` ≠ 1 / `data` bukan array),
 *   `'timeout'` / `'koneksi'` (galat transport), `'token'` (token BKN gagal diperoleh).
 * Kegagalan tidak pernah dilempar (kontrak: kegagalan SIASN dicatat dan tidak menggagalkan operasi utama, AC-03.1); pesan
 * tanpa host, token, maupun NIP. Dorong (`syncPegawai`) belum didukung di sini — M2 memakai `SiasnDorongInterface`.
 */
final class SiasnAdapter implements SiasnGatewayInterface
{
    public const LAYANAN = 'siasn';

    public const SUKSES = '200';

    /** Jenis riwayat → jalur API [K `SS/Rw_*::get_siasn` / `::sync_siasn`]. */
    public const JALUR_RIWAYAT = [
        'golongan'   => 'rw-golongan',
        'pendidikan' => 'rw-pendidikan',
        'pmk'        => 'rw-masakerja',
        'skp22'      => 'rw-skp22',
        'ak'         => 'rw-angkakredit',
    ];

    public function __construct(private readonly TokenBkn $token, private readonly string $baseUrl)
    {
        $this->token->pastikanSiasn();
    }

    public function getPegawai(string $nip): array
    {
        $hasil = $this->ambil('data-utama', $nip);
        $data  = $hasil['data'];

        return ['status_code' => $hasil['status_code'], 'message' => $hasil['message'], 'data' => is_array($data) && ! array_is_list($data) ? $data : null];
    }

    public function getRiwayat(string $nip, string $jenis): array
    {
        $jalur = self::JALUR_RIWAYAT[$jenis] ?? throw new InvalidArgumentException("Jenis riwayat SIASN '{$jenis}' tidak dikenal.");
        $hasil = $this->ambil($jalur, $nip);
        $data  = $hasil['data'];

        return [
            'status_code' => $hasil['status_code'],
            'message'     => $hasil['message'],
            'data'        => is_array($data) ? array_values(array_filter($data, 'is_array')) : [],
        ];
    }

    public function syncPegawai(string $nip, array $payload): array
    {
        return ['status_code' => 'tidak_didukung', 'message' => 'Dorong SIASN memakai SiasnDorongInterface (MAKE-067 M2).', 'data' => null, 'synced_at' => null];
    }

    /**
     * @return array{status_code: string, message: string, data: mixed}
     */
    private function ambil(string $jalur, string $nip): array
    {
        try {
            $respons = $this->token->kirimSiasn(fn (array $h): Permintaan => new Permintaan(
                'GET',
                rtrim($this->baseUrl, '/') . '/pns/' . $jalur . '/' . rawurlencode($nip),
                self::LAYANAN,
                str_replace('-', '_', $jalur),
                header: $h,
            ));
        } catch (GalatTransport $e) {
            return ['status_code' => $e->timeout ? 'timeout' : 'koneksi', 'message' => 'Layanan SIASN tidak dapat dihubungi.', 'data' => null];
        } catch (GalatTokenBkn) {
            return ['status_code' => 'token', 'message' => 'Token BKN tidak dapat diperoleh.', 'data' => null];
        }

        return self::urai($respons);
    }

    /**
     * @return array{status_code: string, message: string, data: mixed}
     */
    public static function urai(Respons $respons): array
    {
        if (! $respons->sukses()) {
            return ['status_code' => (string) $respons->status, 'message' => "SIASN membalas HTTP {$respons->status}.", 'data' => null];
        }

        $json = $respons->json();

        if ($json === null || (string) ($json['code'] ?? '') !== '1' || ! is_array($json['data'] ?? null)) {
            return ['status_code' => 'respons', 'message' => 'Balasan SIASN tidak sah.', 'data' => null];
        }

        return ['status_code' => self::SUKSES, 'message' => 'OK', 'data' => $json['data']];
    }
}
