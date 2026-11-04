<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Dorong riwayat ke SIASN BKN (H-03, MAKE-067 M2; PRD Fase 6 §7.3) — kebutuhan tambahan di luar
 * {@see SiasnGatewayInterface} (kontraknya tidak diubah). POST tidak di-retry otomatis (BP-3); kegagalan tidak dilempar.
 */
interface SiasnDorongInterface
{
    /**
     * Simpan satu riwayat ke SIASN [K `postApi_siasn`]. `jalur` = path relatif (mis. `diklat/save`).
     *
     * `status_code`: `'200'` bila HTTP 2xx dan `success` truthy legacy (`true`/`"true"`/`1`/`"1"`); selain itu kode HTTP,
     * `'respons'` (badan tidak sah / `success` tidak truthy), `'timeout'` / `'koneksi'` (status di SIASN tidak diketahui), atau `'token'`. `data` =
     * `mapData` balasan (array atau skalar) saat sukses, selain itu null. `message` = pesan singkat tanpa host/token.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $rahasia nilai yang disensor dari log (mis. id PNS SIASN)
     * @param string|null          $refId   rujukan baris lokal `<tabel>:<pk>` untuk `integrasi_log` (tanpa NIP)
     *
     * @return array{status_code: string, message: string, data: mixed}
     */
    public function simpanRiwayat(string $jalur, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] array $rahasia = [], ?string $refId = null): array;

    /**
     * Unggah dokumen (PDF) sebuah riwayat SIASN [K `postApi_siasnArsip` → `upload-dok-rw`]. `status_code` `'200'` bila
     * HTTP 2xx dan `code == 1` (`data` = objek dokumen); selain itu kode gagal seperti {@see self::simpanRiwayat()}.
     *
     * @param list<string> $rahasia
     *
     * @return array{status_code: string, message: string, data: mixed}
     */
    public function unggahDokumen(string $idRiwayatSiasn, string $idRefDokumen, string $isi, string $namaBerkas, #[\SensitiveParameter] array $rahasia = []): array;
}
