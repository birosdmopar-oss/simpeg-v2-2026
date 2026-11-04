<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Referensi SIASN BKN (H-03, MAKE-067 M3; PRD Fase 6 §7.3) — di luar {@see SiasnGatewayInterface} (kontraknya tidak
 * diubah). GET di-retry otomatis oleh klien HTTP; kegagalan tidak dilempar.
 */
interface SiasnReferensiInterface
{
    /**
     * Referensi unit organisasi instansi [K `SS/Ref_unor::sync` → `referensi/ref-unor`].
     *
     * `status_code` `'200'` hanya bila HTTP 2xx, `code == 1`, dan `data` = daftar objek; selain itu kode gagal seperti
     * {@see SiasnGatewayInterface::getRiwayat()} dengan `data` = [].
     *
     * @return array{status_code: string, message: string, data: list<array<string, mixed>>}
     */
    public function refUnor(): array;
}
