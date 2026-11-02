<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use Closure;

/**
 * Klien HTTP keluar bersama Fase 6 (PRD §2.2 BP-3/BP-5/BP-7, §7.0.2 AC-K.1..AC-K.3).
 *
 * - Retry **hanya** untuk GET (idempoten): galat transport, 429, atau 5xx dicoba ulang sampai {@see self::PERCOBAAN_MAKS}
 *   kali dengan backoff eksponensial + jitter. POST/PUT/DELETE (sign, save, upload) tidak pernah di-retry otomatis.
 * - Setiap percobaan dicatat ke `integrasi_log` (arah keluar) dengan label `layanan`/`operasi` dari pemanggil — URL, query,
 *   header, dan body TIDAK dicatat (path BKN/BSrE bisa memuat NIP/NIK); pesan galat diredaksi {@see Redaktor}.
 * - Timeout/galat koneksi tanpa respons = kegagalan tercatat (`http_status` NULL, kode `timeout`/`koneksi`), lalu
 *   {@see GalatTransport} dilempar setelah percobaan terakhir.
 *
 * Panggilan ini tidak boleh berada di dalam transaksi DB bisnis (BP-4) — tanggung jawab pemanggil.
 */
final class KlienHttp
{
    public const PERCOBAAN_MAKS = 3;

    /** @var Closure(int): void tidur dalam milidetik (diinjeksi test agar tanpa jeda nyata) */
    private Closure $tidur;

    /** @var Closure(int): int jitter 0..n milidetik */
    private Closure $jitter;

    public function __construct(
        private readonly TransportHttpInterface $transport,
        private readonly IntegrasiLogInterface $log,
        private readonly Redaktor $redaktor = new Redaktor(),
        ?Closure $tidur = null,
        ?Closure $jitter = null,
        private readonly int $backoffDasarMs = 300,
    ) {
        $this->tidur = $tidur ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->jitter = $jitter ?? static fn (int $maks): int => random_int(0, max(0, $maks));
    }

    /**
     * @throws GalatTransport tidak ada respons setelah percobaan terakhir
     */
    public function kirim(Permintaan $permintaan, OpsiTransport $opsi = new OpsiTransport()): Respons
    {
        $maks = $permintaan->idempoten() ? self::PERCOBAAN_MAKS : 1;

        for ($ke = 1; ; $ke++) {
            $mulai = hrtime(true);

            try {
                $respons = $this->transport->kirim($permintaan, $opsi);
            } catch (GalatTransport $e) {
                $pesan = $this->redaktor->teks($e->getMessage(), $permintaan->rahasia);
                $this->catat($permintaan, CatatanIntegrasi::GAGAL, null, $e->timeout ? 'timeout' : 'koneksi', $pesan, $mulai, $ke);

                if ($ke < $maks) {
                    $this->jeda($ke);

                    continue;
                }

                throw new GalatTransport($pesan, $e->timeout, $e);
            }

            $bolehUlang = $respons->status === 429 || $respons->status >= 500;
            $status     = $respons->sukses() ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL;
            $pesan      = $status === CatatanIntegrasi::SUKSES ? null : $this->redaktor->teks($respons->body, $permintaan->rahasia);
            $this->catat($permintaan, $status, $respons->status, null, $pesan, $mulai, $ke);

            if ($bolehUlang && $ke < $maks) {
                $this->jeda($ke);

                continue;
            }

            return $respons;
        }
    }

    private function jeda(int $ke): void
    {
        $dasar = $this->backoffDasarMs * (2 ** ($ke - 1));
        ($this->tidur)($dasar + ($this->jitter)(intdiv($dasar, 2)));
    }

    private function catat(Permintaan $p, int $status, ?int $http, ?string $kode, ?string $pesan, int $mulaiNs, int $ke): void
    {
        $this->log->catat(new CatatanIntegrasi(
            arah: CatatanIntegrasi::ARAH_KELUAR,
            layanan: $p->layanan,
            operasi: $p->operasi,
            status: $status,
            httpStatus: $http,
            kode: $kode,
            pesan: $pesan,
            durasiMs: (int) round((hrtime(true) - $mulaiNs) / 1_000_000),
            percobaan: $ke,
            refId: $p->refId,
        ));
    }
}
