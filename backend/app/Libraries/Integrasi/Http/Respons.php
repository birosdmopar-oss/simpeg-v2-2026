<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

/**
 * Respons HTTP keluar yang sudah diterima utuh (status apa pun; HTTP 4xx/5xx bukan exception — keputusan sukses/gagal
 * milik adapter layanan).
 */
final class Respons
{
    /**
     * @param array<string, string> $header nama header huruf kecil
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $header = [],
    ) {
    }

    public function sukses(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<mixed>|null null bila badan bukan objek/array JSON
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }

    public function header(string $nama): ?string
    {
        return $this->header[strtolower($nama)] ?? null;
    }
}
