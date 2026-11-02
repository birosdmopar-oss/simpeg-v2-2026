<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

/**
 * Lapisan kirim mentah di bawah {@see KlienHttp}: produksi {@see CurlTransport}, test {@see FakeTransport} (BP-11 —
 * test tidak pernah memanggil jaringan).
 */
interface TransportHttpInterface
{
    /**
     * @throws GalatTransport tidak ada respons (koneksi/TLS/timeout)
     */
    public function kirim(Permintaan $permintaan, OpsiTransport $opsi): Respons;
}
