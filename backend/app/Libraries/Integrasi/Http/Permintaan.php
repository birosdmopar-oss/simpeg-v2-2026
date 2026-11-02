<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

/**
 * Permintaan HTTP keluar (BP-3). `layanan`/`operasi` = label `integrasi_log`; `rahasia` = nilai literal tambahan yang
 * wajib disensor dari log selain kunci sensitif bawaan {@see Redaktor} (mis. passphrase/NIK yang dikirim di query
 * string BSrE, F6-17). Hanya GET yang boleh di-retry otomatis (`idempoten()`).
 */
final class Permintaan
{
    /**
     * @param array<string, scalar|null>                                    $query
     * @param array<string, string>                                         $header
     * @param array<string, scalar>|null                                    $form
     * @param list<array{nama: string, isi: string, namaBerkas?: string}>|null $multipart
     * @param list<string>                                                  $rahasia
     */
    public function __construct(
        public readonly string $metode,
        public readonly string $url,
        public readonly string $layanan,
        public readonly string $operasi,
        public readonly array $query = [],
        public readonly array $header = [],
        public readonly mixed $json = null,
        public readonly ?array $form = null,
        public readonly ?array $multipart = null,
        public readonly ?string $refId = null,
        public readonly array $rahasia = [],
    ) {
    }

    public function idempoten(): bool
    {
        return strtoupper($this->metode) === 'GET';
    }
}
