<?php

declare(strict_types=1);

namespace App\Models\Layanan;

/**
 * Tabel `petugas_layanan` (DBV-022) — PIC layanan per `id_jenis_layanan` (C-10, PRD Fase 4 §6.10.2). Ditulis hanya role 1
 * lewat PetugasLayananService; whitelist kolom di bawah (bukan mass-assignment legacy). Hapus fisik + audit [K].
 * `created_at` DEFAULT dan `updated_at` ON UPDATE diisi DB.
 */
class PetugasLayananModel extends LayananModel
{
    protected $table         = 'petugas_layanan';
    protected $primaryKey    = 'id_petugas_layanan';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_jenis_layanan', 'nip', 'status', 'created_at', 'updated_at'];
}
