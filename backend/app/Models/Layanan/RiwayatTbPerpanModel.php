<?php

declare(strict_types=1);

namespace App\Models\Layanan;

/**
 * Tabel `riwayat_tb_perpan` (DBV-022) — perpanjangan TB, baris terpisah terhubung induk (D15, PRD Fase 4 §6.7.4). Hapus
 * fisik + audit [K]. `file_sperpan` NOT NULL [K]: v2 mengisinya dengan path lampiran `document_attachment` kode 48.
 */
class RiwayatTbPerpanModel extends LayananModel
{
    protected $table         = 'riwayat_tb_perpan';
    protected $primaryKey    = 'id_riwayat_tb_perpan';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'id_riwayat_tb', 'tgl_mulai_perpan', 'tgl_akhir_perpan', 'file_sperpan', 'created_at', 'created_by',
    ];
}
