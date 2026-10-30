<?php

declare(strict_types=1);

namespace App\Models\Layanan;

/**
 * Tabel `riwayat_pmk` (DBV-022) — riwayat PMK final dari impor/SIASN [K D16, P-2] (C-09, PRD Fase 4 §6.9.1). v2 hanya
 * menghapus (fisik + audit) dan menambah arsip SK di `document_attachment` kode 42; persetujuan pengajuan TIDAK menulis
 * tabel ini (D16).
 */
class RiwayatPmkModel extends LayananModel
{
    protected $table         = 'riwayat_pmk';
    protected $primaryKey    = 'id_riwayat_pmk';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['updated_at', 'updated_by', 'created_at'];
}
