<?php

declare(strict_types=1);

namespace App\Interfaces\Integrasi;

use App\Libraries\Integrasi\Log\CatatanIntegrasi;

/**
 * Penulis log integrasi tunggal `integrasi_log` (PRD Fase 6 F6-24, DBV-027) — dipakai Jalur B (klien keluar, arah 1) dan
 * Jalur A (API masuk, arah 2). Implementasi WAJIB menyimpan pesan yang sudah teredaksi (BP-5) dan tidak boleh
 * menggagalkan operasi bisnis bila penulisan log gagal (BP-7: kegagalan log dicatat ke log berkas).
 */
interface IntegrasiLogInterface
{
    public function catat(CatatanIntegrasi $catatan): void;
}
