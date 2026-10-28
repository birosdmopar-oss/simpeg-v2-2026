<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Riwayat percobaan tanda tangan elektronik `esign_hist` (PRD Fase 4 §6.5.3, D12/D13). Append-only: satu baris per
 * percobaan, tidak pernah diubah atau dihapus oleh aplikasi. `nik` adalah PII dan tidak pernah masuk respons, log, atau
 * audit; passphrase tidak pernah disimpan (tidak ada kolomnya).
 *
 * Penulisan harus di LUAR transaksi bisnis agar percobaan gagal tetap tercatat saat transaksi bisnis di-rollback.
 */
class EsignHistModel extends Model
{
    public const STATUS_BERHASIL = '1';

    public const STATUS_GAGAL = '2';

    protected $table         = 'esign_hist';
    protected $primaryKey    = 'id_esign_hist';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip', 'nik', 'nama_lengkap', 'modul', 'id_rw', 'file_pdf_to_sign', 'status', 'status_code',
        'ip_address', 'user_agent', 'keterangan', 'created_at',
    ];
}
