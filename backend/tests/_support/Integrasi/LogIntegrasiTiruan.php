<?php

declare(strict_types=1);

namespace Tests\Support\Integrasi;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;

/**
 * Penampung `integrasi_log` di memori untuk test klien/adapter integrasi (tanpa DB).
 */
final class LogIntegrasiTiruan implements IntegrasiLogInterface
{
    /** @var list<CatatanIntegrasi> */
    public array $isi = [];

    public function catat(CatatanIntegrasi $catatan): void
    {
        $this->isi[] = $catatan;
    }

    /**
     * Gabungan seluruh teks yang tersimpan (untuk uji redaksi).
     */
    public function semuaTeks(): string
    {
        return implode("\n", array_map(static fn (CatatanIntegrasi $c): string => implode(' ', [$c->layanan, $c->operasi, (string) $c->kode, (string) $c->pesan, (string) $c->refId]), $this->isi));
    }
}
