<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Log;

/**
 * Satu baris `integrasi_log` (DBV-027 §3.1). `pesan` diisi teks yang SUDAH teredaksi (lihat {@see \App\Libraries\Integrasi\Http\Redaktor});
 * panjangnya dipotong penulis log menjadi {@see self::PESAN_MAKS} karakter.
 */
final class CatatanIntegrasi
{
    public const ARAH_KELUAR = 1;

    public const ARAH_MASUK = 2;

    public const SUKSES = 1;

    public const GAGAL = 2;

    public const PESAN_MAKS = 1000;

    public function __construct(
        public readonly int $arah,
        public readonly string $layanan,
        public readonly string $operasi,
        public readonly int $status,
        public readonly ?int $httpStatus = null,
        public readonly ?string $kode = null,
        public readonly ?string $pesan = null,
        public readonly ?int $durasiMs = null,
        public readonly int $percobaan = 1,
        public readonly ?string $refId = null,
        public readonly ?int $idKlien = null,
    ) {
    }
}
